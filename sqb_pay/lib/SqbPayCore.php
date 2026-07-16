<?php

namespace gateways\sqb_pay\lib;

use GuzzleHttp\Client;
use gateways\sqb_pay\model\SqbPayPendingModel;

class SqbPayCore
{
    private $username;
    private $password;
    private $terminal_sn;
    private $client;

    public function __construct($config)
    {
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->terminal_sn = $config['terminal_sn'];

        $this->client = new Client([
            'base_uri' => 'https://web-platforms-msp.shouqianba.com',
            'timeout'  => 30,
            'verify'   => false,
        ]);
    }

    // ========== 待支付记录管理（基于数据库） ==========

    /**
     * 获取 Pending Model 实例
     * @return SqbPayPendingModel
     */
    private static function pendingModel()
    {
        return new SqbPayPendingModel();
    }

    /**
     * 添加待支付记录
     * @param int|string $invoiceId 发票ID
     * @param float|string $amountYuan 金额（元）
     * @param int $ttl 过期时间（秒）
     */
    public static function addPending($invoiceId, $amountYuan, $ttl = 300)
    {
        self::pendingModel()->addPending($invoiceId, $amountYuan, $ttl);
    }

    /**
     * 移除单条待支付记录
     * @param int|string $invoiceId
     */
    public static function removePending($invoiceId)
    {
        self::pendingModel()->removePending($invoiceId);
    }

    /**
     * 批量移除待支付记录
     * @param array $invoiceIds
     */
    public static function removeBatchPending($invoiceIds)
    {
        self::pendingModel()->removeBatchPending($invoiceIds);
    }

    /**
     * 根据金额（分）查找发票ID
     * @param int $amountCents 金额（分）
     * @return string|null
     */
    public static function findInvoiceByAmount($amountCents)
    {
        return self::pendingModel()->findInvoiceByAmount($amountCents);
    }

    /**
     * 清理过期记录
     */
    public static function clearExpiredPending()
    {
        self::pendingModel()->clearExpired();
    }

    /**
     * 清理指定时间之前的记录
     * @param int $beforeTime 时间戳
     */
    public static function clearOlderThanPending($beforeTime)
    {
        self::pendingModel()->clearOlderThan($beforeTime);
    }

    // ========== 收钱吧API ==========

    /**
     * 登录获取token
     */
    public function login()
    {
        $cacheKey = 'sqb_web_token_' . $this->username;
        $cachedToken = cache($cacheKey);

        if ($cachedToken) {
            return $cachedToken;
        }

        $data = [
            'username' => $this->username,
            'password' => $this->password,
            'uc_device' => [
                'device_type'        => 2,
                'default_device'     => 0,
                'platform'           => '商户服务平台',
                'device_fingerprint' => $this->generateUuid(),
                'device_name'        => '收钱吧商户平台',
                'device_model'       => 'Windows',
                'device_brand'       => 'Edge',
            ],
        ];

        $response = $this->client->post('/api/login/ucUser/login', [
            'headers' => [
                'Content-Type' => 'application/json;charset=UTF-8',
                'Accept'       => 'application/json',
                'sub_appid'    => 'sqb',
                'Origin'       => 'https://s.shouqianba.com',
                'Referer'      => 'https://s.shouqianba.com/',
            ],
            'json' => $data,
        ]);

        $body = (string) $response->getBody();
        $result = json_decode($body, true);

        if (!isset($result['data']['mchUserTokenInfo']['token'])) {
            active_log('收钱吧登录失败: ' . ($result['msg'] ?? $body));
            throw new \Exception('收钱吧登录失败: ' . ($result['msg'] ?? $body));
        }

        $token = $result['data']['mchUserTokenInfo']['token'];
        $validTime = isset($result['data']['mchUserTokenInfo']['valid_time']) ? intval($result['data']['mchUserTokenInfo']['valid_time']) / 1000 : 3500;
        cache($cacheKey, $token, $validTime - 60);

        return $token;
    }

    /**
     * 查询交易列表
     */
    public function queryTransactions($params)
    {
        $token = $this->login();

        $data = [
            'upayQueryType'   => 0,
            'page'            => $params['page'] ?? 1,
            'page_size'       => $params['page_size'] ?? 100,
            'date_start'      => $params['date_start'],
            'date_end'        => $params['date_end'],
            'show_fund_state' => true,
            'terminal_sn'     => $this->terminal_sn,
        ];

        $response = $this->client->post('/api/transaction/findTransactions', [
            'headers' => [
                'Content-Type'   => 'application/json;charset=UTF-8',
                'Accept'         => 'application/json',
                'Origin'         => 'https://s.shouqianba.com',
                'Referer'        => 'https://s.shouqianba.com/',
            ],
            'query' => [
                'token'          => $token,
                'client_version' => '7.0.0',
            ],
            'json' => $data,
        ]);

        $body = (string) $response->getBody();
        $result = json_decode($body, true);

        if (!is_array($result)) {
            throw new \Exception('API响应解析失败: ' . $body);
        }

        return $result;
    }

    /**
     * 匹配支付并处理
     * @param array $pendingInvoices 待支付订单 [id => total(元)]
     * @return array 匹配成功的列表 [invoice_id, trans_id, amount]
     */
    public function matchPayments($pendingInvoices)
    {
        $matched = [];

        // 取待支付表中最早一条记录的时间作为查询起点
        $earliestTime = self::pendingModel()->getEarliestCreateTime();
        $startTime = $earliestTime ?: (time() - 300);

        $result = $this->queryTransactions([
            'date_start' => $startTime * 1000,
            'date_end'   => time() * 1000,
        ]);
        $transactions = $result['data']['records'] ?? [];

        if (empty($transactions)) {
            return $matched;
        }

        // 已入库的交易号，防止重复匹配
        $transIds = array_filter(array_column($transactions, 'order_sn'));
        $existTransIds = \think\Db::name('accounts')
            ->where('trans_id', 'in', $transIds)
            ->column('trans_id');

        foreach ($transactions as $transaction) {
            if ($transaction['status'] != 2000) {
                continue;
            }

            $amountCents = (int) $transaction['paid_amount'];
            $transId = $transaction['order_sn'] ?? $transaction['trade_no'] ?? '';

            if (in_array($transId, $existTransIds)) {
                continue;
            }

            // 优先从数据库待支付表查找（已含 FIFO + 过期过滤），fallback 到传入的待支付列表
            $invoiceId = self::findInvoiceByAmount($amountCents);

            if (!$invoiceId && isset($pendingInvoices)) {
                foreach ($pendingInvoices as $id => $total) {
                    if ((int) round($total * 100) === $amountCents) {
                        $invoiceId = $id;
                        break;
                    }
                }
            }

            if ($invoiceId) {
                // 二次校验：确保待支付记录未过期
                $pendingRecord = self::pendingModel()
                    ->where('invoice_id', $invoiceId)
                    ->where('expire_time', '>', time())
                    ->find();

                if (!$pendingRecord) {
                    active_log("SqbPay: 交易{$transId}金额{$amountCents}分匹配到订单{$invoiceId}，但记录已过期，跳过");
                    continue;
                }

                $paidTime = isset($transaction['finish_time']) ? intval($transaction['finish_time'] / 1000) : time();

                check_pay([
                    'invoice_id' => $invoiceId,
                    'trans_id'   => $transId,
                    'currency'   => 'CNY',
                    'payment'    => 'SqbPay',
                    'amount_in'  => $amountCents / 100,
                    'paid_time'  => date('Y-m-d H:i:s', $paidTime),
                ]);

                self::removePending($invoiceId);

                active_log("SqbPay: 匹配成功 订单{$invoiceId} 交易{$transId} 金额{$amountCents}分");

                $matched[] = [
                    'invoice_id' => $invoiceId,
                    'trans_id'   => $transId,
                    'amount'     => $amountCents / 100,
                ];
            }
        }

        return $matched;
    }

    /**
     * 查询额度
     * @return array API返回的data部分
     * @throws \Exception
     */
    public function queryQuota()
    {
        $token = $this->login();

        $response = $this->client->post('/api/queryQuota', [
            'headers' => [
                'Content-Type' => 'application/json;charset=UTF-8',
                'Accept'       => 'application/json',
                'Origin'       => 'https://s.shouqianba.com',
                'Referer'      => 'https://s.shouqianba.com/',
            ],
            'query' => [
                'token' => $token,
            ],
            'json' => (object) [],
        ]);

        $body = (string) $response->getBody();
        $result = json_decode($body, true);

        if (!is_array($result) || ($result['code'] ?? 0) !== 50000) {
            throw new \Exception('查询收钱吧额度失败: ' . ($result['msg'] ?? $body));
        }

        return $result['data'] ?? [];
    }

    /**
     * 生成UUID
     */
    private function generateUuid()
    {
        $chars = md5(uniqid(mt_rand(), true));
        return substr($chars, 0, 8) . '-' .
               substr($chars, 8, 4) . '-' .
               substr($chars, 12, 4) . '-' .
               substr($chars, 16, 4) . '-' .
               substr($chars, 20, 12);
    }
}
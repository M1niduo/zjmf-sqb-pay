<?php

namespace gateways\sqb_pay;

use app\admin\lib\Plugin;
use gateways\sqb_pay\lib\SqbPayCore;
use gateways\sqb_pay\model\SqbPayPendingModel;

class SqbPayPlugin extends Plugin
{

    public $info = array(
        'name'        => 'SqbPay',
        'title'       => '收钱吧支付',
        'description' => '收钱吧码牌支付，支持轮询查询',
        'status'      => 1,
        'author'      => '迷你哆云',
        'version'     => '1.0',
        'module'        => 'gateways',
    );

    public function install()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `shd_sqb_pay_pending` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `invoice_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '发票ID',
            `amount` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '金额（分）',
            `create_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间戳',
            `expire_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '过期时间戳',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_invoice_id` (`invoice_id`),
            UNIQUE KEY `uk_amount_expire` (`amount`, `expire_time`),
            KEY `idx_expire_time` (`expire_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='收钱吧待支付记录';";

        try {
            \think\Db::execute($sql);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function uninstall()
    {
        try {
            \think\Db::execute("DROP TABLE IF EXISTS `shd_sqb_pay_pending`");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function SqbPayHandle($param)
    {
        $config = $this->config();
        $totalFee = $param['total_fee'];
        $outTradeNo = $param['out_trade_no'];

        // 查询收钱吧额度，防止超额
        $errorMsg = $this->checkQuota($config, $totalFee);
        if ($errorMsg !== null) {
            $html = $this->buildErrorHtml($config, $totalFee, $errorMsg);
            return [
                'type' => 'html',
                'data' => $html,
            ];
        }

        $maxFloatingAmount = intval($config['max_floating_amount'] ?? 1000);
        $uniqueAmount = $this->getUniqueAmount($totalFee, $outTradeNo, $maxFloatingAmount);
        
        $pollingTimeout = $config['polling_timeout'] ?: 300;
        
        SqbPayCore::addPending($outTradeNo, $uniqueAmount, $pollingTimeout);
        
        $html = $this->buildPayHtml($config, $uniqueAmount, $outTradeNo, $pollingTimeout);
        
        return [
            'type' => 'html',
            'data' => $html,
        ];
    }

    /**
     * 查询收钱吧额度，校验金额是否超过最大额度
     * @param array $config 插件配置
     * @param float|string $totalFee 支付金额（元）
     * @return string|null 错误信息，null表示校验通过
     */
    private function checkQuota($config, $totalFee)
    {
        try {
            $core = new SqbPayCore($config);
            $quotaData = $core->queryQuota();

            // totalQuota 单位为分
            $totalQuota = intval($quotaData['totalQuota'] ?? 0);
            $amountCents = (int) round($totalFee * 100);

            if ($amountCents > $totalQuota) {
                return "支付金额 {$totalFee} 元超过收钱吧最大可用额度 " . number_format($totalQuota / 100, 2) . " 元，请联系管理员";
            }
        } catch (\Exception $e) {
            return '收钱吧额度校验失败: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * 构建错误提示页面HTML
     */
    private function buildErrorHtml($config, $totalFee, $errorMsg)
    {
        $errorMsgHtml = htmlspecialchars($errorMsg);
        return <<<HTML
        <div class="container" style="width:400px;transform:translateX(-25%);">
            <div class="card">
                <div class="card-body text-center">
                    <img src="/plugins/gateways/sqb_pay/SqbPay.png" alt="收钱吧" class="img-fluid w-25 mb-2">
                    <div class="alert alert-danger" role="alert">
                        {$errorMsgHtml}
                    </div>
                </div>
            </div>
        </div>
HTML;
    }

    private function buildPayHtml($config, $amount, $outTradeNo, $pollingTimeout)
    {
        // 从待支付表获取过期时间
        $pending = (new SqbPayPendingModel())->where('invoice_id', $outTradeNo)->find();
        $expireTimestamp = $pending ? $pending->expire_time : time();
        if (time() > $expireTimestamp) {
            return <<<HTML
            <div class="container" style="width:400px;transform:translateX(-25%);">
                <div class="card">
                    <div class="card-body text-center">
                        <img src="/plugins/gateways/sqb_pay/SqbPay.png" alt="收钱吧" class="img-fluid w-25 mb-2">
                        <div class="alert alert-warning" role="alert">
                            支付金额为 <strong>¥{$amount}</strong>，否则无法识别
                        </div>
                        <div class="text-danger">
                            订单已超时
                        </div>
                    </div>
                </div>
            </div>
HTML;
        }

        $timeoutText = date('Y-m-d H:i:s', $expireTimestamp);
        $qrCode = $this->urlToBase64Image($config['qr_code_image'] ?? '');

        return <<<HTML
    <div class="container" style="width:400px;transform:translateX(-25%);">
        <div class="card">
            <div class="card-body text-center">
                <img src="/plugins/gateways/sqb_pay/SqbPay.png" alt="收钱吧" class="img-fluid w-25">
                <div class="alert alert-warning" style="margin: 0.5rem;margin-bottom: 0;" role="alert">
                    请使用<b>微信</b>/<b>支付宝</b>/<b>云闪付</b><br>扫描下方二维码<br>
                    支付金额为 <strong>¥{$amount}</strong>，否则无法识别
                </div>
                <div class="my-3">
                    <img src="{$qrCode}" alt="二维码" class="img-fluid w-150">
                </div>
                <div class="text-muted">
                    支付有效期至：<span class="badge badge-secondary">{$timeoutText}</span>
                </div>
            </div>
        </div>
    </div>
HTML;
    }

    private function urlToBase64Image($text)
    {
        if (empty($text)) {
            return '';
        }
        ob_start();
        \cmf\phpqrcode\QRcode::png($text, false, QR_ECLEVEL_L, 6, 2);
        $imageData = ob_get_clean();
        return 'data:image/png;base64,' . base64_encode($imageData);
    }

    /**
     * 生成唯一金额（整数分），带重试和上限保护
     * @param float|string $baseAmount 原始金额（元）
     * @param int|string $outTradeNo 发票ID
     * @param int $maxFloating 最大上浮金额（分），默认1000
     * @return float 唯一金额（元）
     * @throws \Exception 金额冲突超过上限时抛出
     */
    private function getUniqueAmount($baseAmount, $outTradeNo, $maxFloating = 1000)
    {
        $pendingModel = new SqbPayPendingModel();
        $baseAmountCents = (int) round($baseAmount * 100);

        $maxAmountCents = $baseAmountCents + $maxFloating;
        $maxRetries = 100;

        for ($i = 0; $i < $maxRetries; $i++) {
            $tryAmountCents = $baseAmountCents + $i;
            if ($tryAmountCents > $maxAmountCents) {
                throw new \Exception('收钱吧支付金额冲突过多，请稍后重试');
            }

            // 检查是否已有相同金额的有效记录
            $exists = $pendingModel
                ->where('amount', $tryAmountCents)
                ->where('expire_time', '>', time())
                ->where('invoice_id', '<>', $outTradeNo)
                ->count();

            if ($exists === 0) {
                return $tryAmountCents / 100;
            }
        }

        throw new \Exception('收钱吧支付金额生成失败，已达到重试上限');
    }

    public function config()
    {
        $name = $this->info['name'];

        $config = db('plugin')->where('name', $name)->value('config');
        if (!empty($config) && $config != "null") {
            $config = json_decode($config, true);
        } else {
            return [];
        }

        return [
            'username'             => $config['username'] ?? '',
            'password'             => $config['password'] ?? '',
            'terminal_sn'          => $config['terminal_sn'] ?? '',
            'qr_code_image'        => $config['qr_code_image'] ?? '',
            'polling_timeout'      => $config['polling_timeout'] ?? 300,
            'max_floating_amount'  => $config['max_floating_amount'] ?? 1000,
        ];
    }
}

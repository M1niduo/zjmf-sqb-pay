<?php

namespace gateways\sqb_pay\controller;

use think\Controller;
use gateways\sqb_pay\SqbPayPlugin;
use gateways\sqb_pay\lib\SqbPayCore;

class IndexController extends Controller
{
    public function pay()
    {
        $amount = input('amount', 0);
        $outTradeNo = input('out_trade_no', '');

        if (!$amount || !$outTradeNo) {
            $this->error('参数错误');
        }

        $config = $this->getConfig();
        $pollingTimeout = $config['polling_timeout'] ?: 300;

        $this->assign([
            'qr_code_image'        => $config['qr_code_image'],
            'amount'               => $amount,
            'out_trade_no'         => $outTradeNo,
            'polling_timeout'      => $pollingTimeout,
            'polling_timeout_text' => floor($pollingTimeout / 60) . ':' . str_pad($pollingTimeout % 60, 2, '0', STR_PAD_LEFT),
        ]);

        $tpl_path = CMF_ROOT . 'public/plugins/gateways/sqb_pay/template/pay.tpl';
        return $this->fetch($tpl_path);
    }

    /**
     * 手动查询收钱吧收款记录
     */
    public function pollingQuery()
    {
        $outTradeNo = input('out_trade_no', '');

        if (!$outTradeNo) {
            return json(['status' => 400, 'msg' => '参数错误']);
        }

        $invoice = \think\Db::name('invoices')
            ->where('id', $outTradeNo)
            ->where('delete_time', 0)
            ->find();

        if (!$invoice) {
            return json(['status' => 400, 'msg' => '订单不存在']);
        }

        if ($invoice['status'] == 'Paid') {
            return json(['status' => 1000, 'msg' => '支付成功']);
        }

        if ($invoice['status'] == 'Cancelled') {
            return json(['status' => 1002, 'msg' => '订单已取消']);
        }

        try {
            $config = $this->getConfig();
            $client = new SqbPayCore($config);
            $client->matchPayments([$invoice['id'] => $invoice['total']]);
        } catch (\Exception $e) {
            \think\facade\Log::error('收钱吧手动查询异常: ' . $e->getMessage());
        }

        $invoice = \think\Db::name('invoices')
            ->where('id', $outTradeNo)
            ->where('delete_time', 0)
            ->find();

        if ($invoice['status'] == 'Paid') {
            return json(['status' => 1000, 'msg' => '支付成功']);
        }

        return json(['status' => 1001, 'msg' => '等待支付']);
    }

    private function getConfig()
    {
        $class = new SqbPayPlugin();
        return $class->config();
    }
}

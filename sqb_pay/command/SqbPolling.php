<?php

namespace gateways\sqb_pay\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use gateways\sqb_pay\SqbPayPlugin;
use gateways\sqb_pay\lib\SqbPayCore;

class SqbPolling extends Command
{
    private $config;

    protected function configure()
    {
        $this->setName('sqb_polling')->setDescription('收钱吧支付轮询查询');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('收钱吧轮询开始:' . date('Y-m-d H:i:s'));

        // 文件锁防止重复执行
        $lockFile = RUNTIME_PATH . 'sqb_polling.lock';
        $fp = fopen($lockFile, 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            $output->writeln('收钱吧轮询已有实例在运行，跳过');
            return;
        }

        $plugin = new SqbPayPlugin();
        $this->config = $plugin->config();

        if (empty($this->config) || empty($this->config['username'])) {
            $output->writeln('收钱吧未配置，跳过');
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }

        // 清理过期的待支付记录
        SqbPayCore::clearExpiredPending();

        // 从待支付表获取有效的浮动金额（用于fallback匹配）
        $pendingModel = new \gateways\sqb_pay\model\SqbPayPendingModel();
        $pendingRecords = $pendingModel
            ->where('expire_time', '>', time())
            ->column('amount', 'invoice_id');

        if (empty($pendingRecords)) {
            $output->writeln('无待支付记录，跳过');
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }

        // 从invoices表获取未支付订单，只取pending表中存在的
        $pendingInvoices = \think\Db::name('invoices')
            ->where('status', 'Unpaid')
            ->where('payment', 'SqbPay')
            ->where('delete_time', 0)
            ->where('id', 'in', array_keys($pendingRecords))
            ->column('total', 'id');

        // 用pending表的浮动金额覆盖invoices的原始金额
        foreach ($pendingInvoices as $id => &$total) {
            if (isset($pendingRecords[$id])) {
                $total = $pendingRecords[$id] / 100;
            }
        }
        unset($total);

        if (empty($pendingInvoices)) {
            $output->writeln('无待支付订单，跳过');
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }

        $client = new SqbPayCore($this->config);

        try {
            $matched = $client->matchPayments($pendingInvoices);

            foreach ($matched as $item) {
                $output->writeln("匹配成功: 订单{$item['invoice_id']}, 金额{$item['amount']}, 交易号{$item['trans_id']}");
            }

            if (empty($matched)) {
                $output->writeln('无匹配交易');
            }
        } catch (\Exception $e) {
            $output->writeln('收钱吧轮询异常: ' . $e->getMessage());
            active_log('收钱吧轮询异常: ' . $e->getMessage());
        }

        $output->writeln('收钱吧轮询结束:' . date('Y-m-d H:i:s'));

        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
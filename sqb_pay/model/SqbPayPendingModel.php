<?php

namespace gateways\sqb_pay\model;

class SqbPayPendingModel extends \think\Model
{
    protected $name = 'sqb_pay_pending';

    /**
     * 添加待支付记录
     * @param int|string $invoiceId 发票ID
     * @param float|string $amountYuan 金额（元）
     * @param int $ttl 过期时间（秒）
     * @return bool
     */
    public function addPending($invoiceId, $amountYuan, $ttl = 300)
    {
        $amountCents = (int) round($amountYuan * 100);
        $now = time();

        $data = $this->findOrCreateByInvoiceId($invoiceId);
        $data->amount = $amountCents;
        $data->expire_time = $now + $ttl;

        return $data->save();
    }

    /**
     * 按 invoice_id 查找或创建
     * @param int|string $invoiceId
     * @return static
     */
    public function findOrCreateByInvoiceId($invoiceId)
    {
        $record = $this->where('invoice_id', $invoiceId)->find();
        if (!$record) {
            $record = $this->newInstance([
                'invoice_id' => $invoiceId,
                'create_time' => time(),
            ]);
        }
        return $record;
    }

    /**
     * 移除单条待支付记录
     * @param int|string $invoiceId
     * @return int 影响行数
     */
    public function removePending($invoiceId)
    {
        return $this->where('invoice_id', $invoiceId)->delete();
    }

    /**
     * 批量移除待支付记录
     * @param array $invoiceIds
     * @return int 影响行数
     */
    public function removeBatchPending($invoiceIds)
    {
        if (empty($invoiceIds)) {
            return 0;
        }
        return $this->whereIn('invoice_id', $invoiceIds)->delete();
    }

    /**
     * 根据金额（分）查找发票ID（FILO策略：取最近创建的有效记录）
     * @param int $amountCents 金额（分）
     * @return string|null invoice_id
     */
    public function findInvoiceByAmount($amountCents)
    {
        $record = $this->where('amount', $amountCents)
            ->where('expire_time', '>', time())
            ->order('create_time', 'desc')
            ->limit(1)
            ->find();
        return $record ? $record->invoice_id : null;
    }

    /**
     * 清理过期记录
     * @return int 影响行数
     */
    public function clearExpired()
    {
        return $this->where('expire_time', '<', time())->delete();
    }

    /**
     * 清理指定时间之前的记录
     * @param int $beforeTime 时间戳，删除此时间之前的记录
     * @return int 影响行数
     */
    public function clearOlderThan($beforeTime)
    {
        return $this->where('create_time', '<', $beforeTime)->delete();
    }

    /**
     * 获取所有有效的金额（分），排除指定发票
     * @param int|string|null $excludeInvoiceId 排除的发票ID
     * @return array 金额分列表
     */
    public function getExistingAmounts($excludeInvoiceId = null)
    {
        $query = $this->where('expire_time', '>', time());
        if ($excludeInvoiceId !== null) {
            $query->where('invoice_id', '<>', $excludeInvoiceId);
        }
        return $query->column('amount');
    }

    /**
     * 获取最早一条待支付记录的创建时间
     * @return int|null 时间戳
     */
    public function getEarliestCreateTime()
    {
        $record = $this->order('create_time', 'asc')->limit(1)->find();
        return $record ? $record->create_time : null;
    }

    /**
     * 清空全部待支付记录
     * @return int 影响行数
     */
    public function clearAll()
    {
        return $this->where('id', '>', 0)->delete();
    }
}
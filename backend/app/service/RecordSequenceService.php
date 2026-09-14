<?php
declare(strict_types=1);
namespace app\service;
use app\model\Record;
use app\model\User;
class RecordSequenceService
{
    /**
     * 在事务内锁定员工行（SELECT ... FOR UPDATE）。
     * 同一员工的“取号”和“删除重排”都先拿这把锁，
     * 并发分批上传时不会读到相同的 max 而产生重号。
     */
    public function lockUser(int $userId): void
    {
        User::where('id', $userId)->lock(true)->find();
    }

    /**
     * 下一可用序号 = 当前最大序号 + 1（按 员工 + 检查日期 分组）。
     * 必须在 lockUser() 之后调用，保证取号期间无人插队。
     */
    public function getNextSequenceKey(int $userId, ?string $checkDate = null): int
    {
        $query = Record::where('user_id', $userId);
        if ($checkDate) {
            $query->where('check_date', $checkDate);
        } else {
            // 历史无日期数据自成一组，不跨日期取 max
            $query->whereNull('check_date');
        }
        $max = $query->max('sequence_key');
        return (int) $max + 1;
    }

    /**
     * 删除后压缩重排：把该「员工 + 检查日期」分组内剩余记录的序号
     * 按 sequence_key、id 顺序整体重写为 1、2、3… 的连续序列。
     *
     * 注意：不能假设旧数据是连续的。旧版保存逻辑可能留下 [1,2,4] 这样的空洞
     * （如被过滤的无效项也占了号）。此时若只把“大于被删序号”的 key 减 1：
     * 删除 #1 会得到 [1,3]，#1 仍在且仍然跳号。
     * 整体压缩无论存量数据是否有洞，删除后都保证 1..n 连续。
     * 单条循环 UPDATE，须在事务内（与 delete 同一事务）、lockUser() 之后调用。
     */
    public function reorderAfterDelete(int $userId, ?string $checkDate = null): void
    {
        $query = Record::where('user_id', $userId);
        if ($checkDate) {
            $query->where('check_date', $checkDate);
        } else {
            // 历史无日期数据自成一组，不跨日期重排
            $query->whereNull('check_date');
        }
        $remaining = $query->order('sequence_key', 'asc')->order('id', 'asc')
            ->field('id,sequence_key')->select();

        $key = 1;
        foreach ($remaining as $row) {
            // 只写真正变化的行，避免无意义 UPDATE；前面有洞时后续行也会被一并拉平
            if ((int) $row->sequence_key !== $key) {
                Record::where('id', $row->id)->update(['sequence_key' => $key]);
            }
            $key++;
        }
    }
}

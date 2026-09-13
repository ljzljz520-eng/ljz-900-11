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
     * 删除后重排：比被删序号大的记录整体前移一位，保证 #1、#2… 连续 无跳号。
     * 单条原子 UPDATE，须在事务内（与 delete 同一事务）调用。
     */
    public function reorderAfterDelete(int $userId, int $deletedSequenceKey, ?string $checkDate = null): void
    {
        $query = Record::where('user_id', $userId)->where('sequence_key', '>', $deletedSequenceKey);
        if ($checkDate) {
            $query->where('check_date', $checkDate);
        } else {
            $query->whereNull('check_date');
        }
        $query->dec('sequence_key', 1)->update();
    }
}

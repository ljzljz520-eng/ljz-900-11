<?php
declare(strict_types=1);
namespace app\service;
use app\model\Record;
use think\facade\Db;

/**
 * 同一员工的问题图序号（#1、#2…）服务：
 * - 编号范围：同一员工全部检查记录（跨检查日期连续），员工端 / 汇总页看到的编号才不会重复；
 * - 新增：从该员工已有最大序号继续自增，单张与分批一致；
 * - 删除：删除后把后续序号整体前移，展示始终连续、无跳号；
 * - 并发：以员工级 MySQL 咨询锁串行化所有写操作，配合 uk_user_sequence 唯一索引兜底。
 */
class RecordSequenceService
{
    private const LOCK_TIMEOUT = 10; // 秒

    /**
     * 获取某员工的序号写锁（建议锁，需在事务结束后主动释放）。
     * 获取失败抛异常，避免两批上传并发读到同一个最大序号而重号。
     */
    public function lockUser(int $userId): void
    {
        $name = 'hygiene_record_seq_' . $userId;
        $got = (int) Db::query('SELECT GET_LOCK(:name, :timeout) AS lck', [
            'name' => $name,
            'timeout' => self::LOCK_TIMEOUT,
        ])[0]['lck'];
        if ($got !== 1) {
            throw new \RuntimeException('序号繁忙，请稍后重试');
        }
    }

    public function unlockUser(int $userId): void
    {
        Db::query('SELECT RELEASE_LOCK(:name)', ['name' => 'hygiene_record_seq_' . $userId]);
    }

    /**
     * 同一员工下一张图的序号：已有最大序号 + 1，无记录时为 #1。
     * 注意：不再按 check_date 分组，保证员工维度全局唯一、连续。
     */
    public function getNextSequenceKey(int $userId): int
    {
        $max = Record::where('user_id', $userId)->max('sequence_key');
        return (int) $max + 1;
    }

    /**
     * 删除某张图后重排：把该员工序号大于被删序号的记录整体前移一位。
     * 必须在 lockUser 之后调用。
     *
     * (user_id, sequence_key) 上有唯一索引，逐条 -1 会与相邻记录撞键，
     * 因此先整体加足够大的偏移量让出位置，再减到目标值。
     */
    public function compactAfterDelete(int $userId, int $deletedSequenceKey): void
    {
        $offset = 1000000;

        Db::transaction(function () use ($userId, $deletedSequenceKey, $offset) {
            // 第一步：上移偏移，腾出唯一键空间
            Record::where('user_id', $userId)
                ->where('sequence_key', '>', $deletedSequenceKey)
                ->update(['sequence_key' => Db::raw('sequence_key + ' . $offset)]);
            // 第二步：落到最终连续序号
            Record::where('user_id', $userId)
                ->where('sequence_key', '>', $offset)
                ->update(['sequence_key' => Db::raw('sequence_key - ' . ($offset + 1))]);
        });
    }

    /**
     * 把某员工的全部记录按（检查日期、原序号、id）压实为从 1 开始的连续序号。
     * 用于历史数据迁移 / 修复脏数据；调用方需保证无并发写入。
     */
    public function normalizeUser(int $userId): void
    {
        $list = Record::where('user_id', $userId)
            ->order('check_date', 'asc')
            ->order('sequence_key', 'asc')
            ->order('id', 'asc')
            ->select();
        $offset = 1000000;
        Db::transaction(function () use ($list, $offset) {
            foreach ($list as $idx => $r) {
                $r->sequence_key = $offset + $idx + 1;
                $r->save();
            }
            foreach ($list as $idx => $r) {
                $r->sequence_key = $idx + 1;
                $r->save();
            }
        });
    }
}

<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 问题图 key 编号服务
 *
 * 编号作用域：同一员工（user_id）+ 同一检查日期（check_date）内连续编号，
 * 从该作用域已有最大序号 +1 开始续号；删除任意一张后对剩余图片重新紧凑编号，
 * 保证 #1、#2、#3… 始终连续、不跳号。
 *
 * 所有写操作都在事务内通过「区间锁（SELECT ... FOR UPDATE 锁定该员工的
 * 记录与间隙）+ 唯一索引兜底」完成，避免管理员单张/分批并发上传时出现重复序号。
 */
class RecordSequenceService
{
    /**
     * 构造作用域查询（每次新建，避免查询构造器状态残留）。
     */
    private function groupQuery(int $userId, ?string $checkDate)
    {
        $query = Db::table('records')->where('user_id', $userId);
        if ($checkDate !== null) {
            $query->where('check_date', $checkDate);
        } else {
            $query->whereNull('check_date');
        }
        return $query;
    }

    /**
     * 在事务内锁定该员工（+日期）的记录区间。
     *
     * 用 SELECT ... FOR UPDATE 锁住命中行以及它们之间的间隙：
     * - 作用域内已有记录：并发事务在同一批行/间隙上互斥，续号被串行化；
     * - 作用域内尚无记录（新的一天第一批）：FOR UPDATE 锁不到行，
     *   此时依靠 uk_user_date_seq 唯一索引 + 调用方对 1062 冲突的重试兜底。
     */
    public function lockGroup(int $userId, ?string $checkDate = null): void
    {
        $this->groupQuery($userId, $checkDate)->field('id')->lock(true)->select();
    }

    /**
     * 取该作用域当前最大序号（调用方必须先 lockGroup 或已在写事务内）。
     */
    public function getMaxSequenceKey(int $userId, ?string $checkDate = null): int
    {
        return (int) $this->groupQuery($userId, $checkDate)->max('sequence_key');
    }

    /**
     * 分配一批连续序号：从 max+1 开始，返回 [$startKey, $endKey]。
     * 必须在已 lockGroup 的事务内调用。
     */
    public function allocateRange(int $userId, int $count, ?string $checkDate = null): array
    {
        $startKey = $this->getMaxSequenceKey($userId, $checkDate) + 1;
        return [$startKey, $startKey + max(0, $count - 1)];
    }

    /**
     * 删除后，把该作用域内剩余图片按「旧序号、id」顺序重新紧凑编号为 1..N。
     *
     * 两阶段更新（先整体平移到大偏移区间，再压回 1..N），避免与
     * (user_id, check_date, sequence_key) 唯一索引在中间状态发生冲突，
     * 同时能顺带修复历史遗留的空洞/重复，保证删除后展示严格连续。
     * 必须在已 lockGroup 的事务内调用。
     */
    public function reindexGroup(int $userId, ?string $checkDate = null): void
    {
        $offset = 10000000; // 远大于单日单员工正常记录数；sequence_key 为 int unsigned

        $rows = $this->groupQuery($userId, $checkDate)
            ->field('id')
            ->order('sequence_key', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (empty($rows)) {
            return;
        }

        // 阶段一：平移到安全区间，释放低位序号
        foreach ($rows as $pos => $row) {
            $this->groupQuery($userId, $checkDate)
                ->where('id', (int) $row['id'])
                ->update(['sequence_key' => $offset + $pos + 1]);
        }

        // 阶段二：按展示顺序压回 1..N
        foreach ($rows as $pos => $row) {
            $this->groupQuery($userId, $checkDate)
                ->where('id', (int) $row['id'])
                ->update(['sequence_key' => $pos + 1]);
        }
    }
}

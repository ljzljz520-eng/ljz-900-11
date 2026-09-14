-- 修复历史脏数据：旧版保存逻辑可能在同一「员工 + 检查日期」分组内留下
-- 不连续的 sequence_key（例如 [1,2,4]）。本脚本按
-- (user_id, check_date) 分组，依 (sequence_key, id) 顺序将序号压缩为
-- 1、2、3… 连续序列。可重复执行（幂等）。
--
-- 执行示例：
-- docker compose exec -T db mysql -uroot -proot hygiene_audit < backend/database/migrate_compact_sequence.sql
--
-- 说明：check_date 为 NULL 的记录按 user_id 各自成组（NULL 在窗口分区中
-- 相互等同，符合应用端 whereNull('check_date') 的分组语义）。
-- 需要 MySQL 8.0+（窗口函数）。

SET NAMES utf8mb4;
USE hygiene_audit;

UPDATE `records` r
JOIN (
    SELECT `id`,
           ROW_NUMBER() OVER (
               PARTITION BY `user_id`, `check_date`
               ORDER BY `sequence_key`, `id`
           ) AS new_key
    FROM `records`
) t ON t.`id` = r.`id`
SET r.`sequence_key` = t.new_key
WHERE r.`sequence_key` <> t.new_key;

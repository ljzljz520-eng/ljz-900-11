-- 将 records.sequence_key 统一为“同一员工全局连续编号”（跨检查日期）
-- 背景：旧逻辑按 (user_id, check_date) 各自从 1 编号，员工端/汇总页展示全部日期时
--       会出现重复的 #1、#2。本脚本：
--   1) 先按员工把历史记录压实为从 1 开始的连续序号（排序：检查日期 → 旧序号 → id）；
--   2) 将普通索引 user_sequence 升级为唯一索引 uk_user_sequence，防止并发重号。
-- 可重复执行。
-- 执行示例：
-- docker compose exec -T db mysql -uroot -proot hygiene_audit < backend/database/migrate_sequence_per_user.sql

SET NAMES utf8mb4;
USE hygiene_audit;

-- 第一步：按员工全局重排（MySQL 8 窗口函数；先写到临时列再回写）
DROP TEMPORARY TABLE IF EXISTS `_tmp_seq_renumber`;
CREATE TEMPORARY TABLE `_tmp_seq_renumber` AS
SELECT
  `id`,
  ROW_NUMBER() OVER (
    PARTITION BY `user_id`
    ORDER BY `check_date` ASC, `sequence_key` ASC, `id` ASC
  ) AS `new_key`
FROM `records`;

UPDATE `records` r
JOIN `_tmp_seq_renumber` t ON t.`id` = r.`id`
SET r.`sequence_key` = 1000000 + t.`new_key`;

UPDATE `records`
SET `sequence_key` = `sequence_key` - 1000000
WHERE `sequence_key` >= 1000000;

DROP TEMPORARY TABLE IF EXISTS `_tmp_seq_renumber`;

-- 第二步：移除旧的普通索引 user_sequence（若存在）
SET @db = DATABASE();
SET @sql = (
  SELECT IF(
    (SELECT COUNT(*)
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db
       AND TABLE_NAME = 'records'
       AND INDEX_NAME = 'user_sequence') > 0,
    'ALTER TABLE `records` DROP INDEX `user_sequence`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 第三步：添加唯一索引 uk_user_sequence（同一员工序号唯一）
SET @sql = (
  SELECT IF(
    (SELECT COUNT(*)
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db
       AND TABLE_NAME = 'records'
       AND INDEX_NAME = 'uk_user_sequence') = 0,
    'ALTER TABLE `records` ADD UNIQUE KEY `uk_user_sequence` (`user_id`, `sequence_key`)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

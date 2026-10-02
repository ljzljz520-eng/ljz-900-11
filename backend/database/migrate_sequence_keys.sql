-- 问题图 key 编号稳定性改造（可重复执行）
-- 1) 补齐历史空 check_date
-- 2) 按「员工 + 检查日期」把 sequence_key 紧凑重排为 1..N，消除历史空洞/重复
-- 3) 增加唯一索引 (user_id, check_date, sequence_key)，兜底并发重复
--
-- 执行示例：
-- docker compose exec -T db mysql -uroot -proot hygiene_audit < backend/database/migrate_sequence_keys.sql

SET NAMES utf8mb4;
USE hygiene_audit;

-- 1) check_date 不允许为空（与应用层口径一致），缺失时取 created_at 日期，再退化为当天
UPDATE `records`
SET `check_date` = COALESCE(DATE(`created_at`), CURDATE())
WHERE `check_date` IS NULL;

-- 2) 两阶段紧凑重排（先平移到大偏移释放低位，再压回连续序号），
--    与后端 RecordSequenceService::reindexGroup 同一思路，防止中途撞唯一键。
SET @off := 10000000;

UPDATE `records` r
JOIN (
    SELECT `id`,
           ROW_NUMBER() OVER (PARTITION BY `user_id`, `check_date`
                              ORDER BY `sequence_key` ASC, `id` ASC) AS rn
    FROM `records`
) t ON t.`id` = r.`id`
SET r.`sequence_key` = @off + t.rn;

UPDATE `records` r
JOIN (
    SELECT `id`,
           ROW_NUMBER() OVER (PARTITION BY `user_id`, `check_date`
                              ORDER BY `sequence_key` ASC, `id` ASC) AS rn
    FROM `records`
) t ON t.`id` = r.`id`
SET r.`sequence_key` = t.rn;

-- 3) 唯一索引兜底：同一员工同一天序号唯一（不存在才创建）
SET @db = DATABASE();
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'records'
      AND INDEX_NAME = 'uk_user_date_seq'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE `records` ADD UNIQUE KEY `uk_user_date_seq` (`user_id`, `check_date`, `sequence_key`)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

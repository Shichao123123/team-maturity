-- ============================================================
-- 预留：三级指标一题多选一（题池 + 开测抽题策略）
-- 在已有 team_maturity 上执行；可重复执行（先判断列）
-- ============================================================
USE `team_maturity`;
SET NAMES utf8mb4;

-- 版本级抽题策略：first | random | sequential
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tm_indicator_version'
    AND COLUMN_NAME = 'question_pick_mode'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `tm_indicator_version` ADD COLUMN `question_pick_mode` VARCHAR(16) NOT NULL DEFAULT ''random'' COMMENT ''开测抽题：first首题/random随机/sequential轮询；同一三级多题时生效'' AFTER `remark`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 题目表备注强化（若需手工改注释可忽略失败）
ALTER TABLE `tm_question`
  MODIFY COLUMN `indicator_id` BIGINT NOT NULL COMMENT '三级指标ID；同一指标可挂多题形成题池，开测时抽1道写入批次快照';

-- 批次可覆盖版本策略（可选）
SET @col2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tm_batch'
    AND COLUMN_NAME = 'question_pick_mode'
);
SET @sql2 := IF(@col2 = 0,
  'ALTER TABLE `tm_batch` ADD COLUMN `question_pick_mode` VARCHAR(16) NULL DEFAULT NULL COMMENT ''空=跟随指标版本；非空则本批次覆盖抽题策略'' AFTER `version_id`',
  'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 标准题库版本默认 random（当前虽 1:1，后期扩题无需改代码路径）
UPDATE `tm_indicator_version`
SET `question_pick_mode` = 'random'
WHERE `version_no` = 'V1.0.0' AND (`question_pick_mode` IS NULL OR `question_pick_mode` = '');

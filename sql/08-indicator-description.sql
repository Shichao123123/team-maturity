-- 指标描述字段：草稿版本可编辑各级指标说明
-- 执行：mysql -uroot -p team_maturity < sql/08-indicator-description.sql

ALTER TABLE `tm_indicator`
  ADD COLUMN `description` VARCHAR(1024) NULL DEFAULT NULL COMMENT '指标描述' AFTER `name`;

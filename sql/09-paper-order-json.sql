-- 答卷每人题目/选项顺序（首次开卷生成，续答复用）
-- 执行：mysql -uroot -p team_maturity < sql/09-paper-order-json.sql

ALTER TABLE `tm_paper`
  ADD COLUMN `order_json` TEXT NULL DEFAULT NULL COMMENT '题目与选项乱序 JSON' AFTER `personal_score`;

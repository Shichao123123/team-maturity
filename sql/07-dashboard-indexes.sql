-- 看板 / 排行 / 结果统计加速索引（可重复执行需自行判断）
-- 在 MySQL 中执行：source sql/07-dashboard-indexes.sql;

ALTER TABLE `tm_team_result`
  ADD INDEX `idx_batch_score` (`batch_id`, `team_score`),
  ADD INDEX `idx_batch_level` (`batch_id`, `level_code`),
  ADD INDEX `idx_scored_at` (`scored_at`);

ALTER TABLE `tm_paper`
  ADD INDEX `idx_batch_team_status` (`batch_id`, `team_id`, `status`);

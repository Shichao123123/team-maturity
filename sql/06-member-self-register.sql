-- 成员自助登记：手机号不再作为开测前置；小程序选班组+姓名即可落库
-- 执行：mysql -uroot -p123456 team_maturity < sql/06-member-self-register.sql

ALTER TABLE `tm_member`
  MODIFY COLUMN `mobile` CHAR(11) NULL DEFAULT NULL COMMENT '手机号（可选；PC补录/导入时使用）';

-- 历史空串改为 NULL，避免唯一索引冲突
UPDATE `tm_member` SET `mobile` = NULL WHERE `mobile` = '';

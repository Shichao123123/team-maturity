-- 全国运营看板：组织补充行业/地区/坐标
USE `team_maturity`;
SET NAMES utf8mb4;

ALTER TABLE `tm_org`
  ADD COLUMN `industry` VARCHAR(64)  NULL DEFAULT NULL COMMENT '行业类型，如钢铁/有色/焦化' AFTER `org_type`,
  ADD COLUMN `province` VARCHAR(32)  NULL DEFAULT NULL COMMENT '省/直辖市' AFTER `industry`,
  ADD COLUMN `city`     VARCHAR(64)  NULL DEFAULT NULL COMMENT '市' AFTER `province`,
  ADD COLUMN `lng`      DECIMAL(10,6) NULL DEFAULT NULL COMMENT '经度' AFTER `city`,
  ADD COLUMN `lat`      DECIMAL(10,6) NULL DEFAULT NULL COMMENT '纬度' AFTER `lng`;

-- 菜单：全国运营看板
UPDATE `tm_power`
SET `parent_id` = 0, `name` = '首页', `sort_no` = 0, `icon` = 'layui-icon-chart-screen'
WHERE `id` = 34 OR `code` = 'dashboard:national';

INSERT INTO `tm_power` (`id`, `parent_id`, `name`, `code`, `url`, `icon`, `sort_no`, `type`, `status`)
SELECT 34, 0, '首页', 'dashboard:national', '/TmNational/index', 'layui-icon-chart-screen', 0, 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `tm_power` WHERE `id` = 34 OR `code` = 'dashboard:national');

INSERT INTO `tm_role_power` (`role_id`, `power_id`)
SELECT r.id, 34 FROM `tm_role` r
WHERE NOT EXISTS (SELECT 1 FROM `tm_role_power` rp WHERE rp.`role_id` = r.`id` AND rp.`power_id` = 34);


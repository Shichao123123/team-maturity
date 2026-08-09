-- 组织可变层级：仅更新注释，不改结构
USE `team_maturity`;
SET NAMES utf8mb4;

ALTER TABLE `tm_org`
  MODIFY COLUMN `org_type` TINYINT NOT NULL DEFAULT 1
  COMMENT '类型标签(不限制层级)：1公司/集团 2厂/分厂 3工段/部门 0其他';

ALTER TABLE `tm_team`
  MODIFY COLUMN `org_id` BIGINT NOT NULL
  COMMENT '所属组织节点ID（任意层级，不限工段）';

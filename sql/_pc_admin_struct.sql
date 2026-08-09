-- ============================================================
-- PC 管理端核心：管理员 / 角色 / 菜单权限（本地 team_maturity）
-- 小程序仅扫码答题，业务配置与结果分析均在 PC 端
-- ============================================================
USE `team_maturity`;
SET NAMES utf8mb4;

-- 角色
DROP TABLE IF EXISTS `tm_role`;
CREATE TABLE `tm_role` (
  `id`          BIGINT       NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(64)  NOT NULL COMMENT '角色名',
  `remark`      VARCHAR(255) NULL DEFAULT NULL,
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PC角色';

-- 菜单/权限树
DROP TABLE IF EXISTS `tm_power`;
CREATE TABLE `tm_power` (
  `id`          BIGINT       NOT NULL AUTO_INCREMENT,
  `parent_id`   BIGINT       NOT NULL DEFAULT 0,
  `name`        VARCHAR(64)  NOT NULL COMMENT '菜单/权限名称',
  `code`        VARCHAR(64)  NULL DEFAULT NULL COMMENT '权限码，如 team:list',
  `url`         VARCHAR(255) NULL DEFAULT '' COMMENT '前端路由或后端路径',
  `icon`        VARCHAR(64)  NULL DEFAULT '',
  `sort_no`     INT          NOT NULL DEFAULT 0,
  `type`        TINYINT      NOT NULL DEFAULT 1 COMMENT '1菜单 2按钮',
  `status`      TINYINT      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PC菜单权限';

-- 角色-权限
DROP TABLE IF EXISTS `tm_role_power`;
CREATE TABLE `tm_role_power` (
  `id`        BIGINT NOT NULL AUTO_INCREMENT,
  `role_id`   BIGINT NOT NULL,
  `power_id`  BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_power` (`role_id`, `power_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色权限关联';

-- 管理员账号（PC 登录）
DROP TABLE IF EXISTS `tm_admin`;
CREATE TABLE `tm_admin` (
  `id`              BIGINT       NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(64)  NOT NULL COMMENT '姓名/显示名',
  `username`        VARCHAR(64)  NOT NULL COMMENT '登录账号',
  `mobile`          CHAR(11)     NULL DEFAULT NULL,
  `password_hash`   VARCHAR(255) NOT NULL COMMENT 'password_hash 存储，勿用明文md5',
  `role_id`         BIGINT       NOT NULL,
  `org_id`          BIGINT       NULL DEFAULT NULL COMMENT '可选：数据范围挂组织',
  `status`          TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `last_login_at`   DATETIME     NULL DEFAULT NULL,
  `login_count`     INT          NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`      DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_mobile` (`mobile`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PC管理员';

-- 默认超级管理员角色
INSERT INTO `tm_role` (`id`, `name`, `remark`, `status`) VALUES
(1, '超级管理员', '拥有全部PC功能', 1);

-- 默认菜单（与 docs/02-admin-api.md 对齐）
INSERT INTO `tm_power` (`id`, `parent_id`, `name`, `code`, `url`, `icon`, `sort_no`, `type`, `status`) VALUES
(1,  0, '组织档案',     'org',          '',                  'layui-icon-user', 1, 1, 1),
(2,  1, '组织架构',     'org:tree',     '/TmOrg/index',      '', 1, 1, 1),
(3,  1, '班组管理',     'team:list',    '/TmTeam/index',     '', 2, 1, 1),
(4,  1, '成员管理',     'member:list',  '/TmMember/index',   '', 3, 1, 1),
(10, 0, '评价指标',     'indicator',    '',                  'layui-icon-form', 2, 1, 1),
(11, 10,'指标版本',     'indicator:ver','/TmIndicatorVersion/index','', 1, 1, 1),
(12, 10,'指标与题库',   'indicator:q',  '/TmIndicator/index','', 2, 1, 1),
(13, 10,'等级与赋能',   'level',        '/TmLevel/index',    '', 3, 1, 1),
(20, 0, '测评管理',     'batch',        '',                  'layui-icon-survey', 3, 1, 1),
(21, 20,'测评批次',     'batch:list',   '/TmBatch/index',    '', 1, 1, 1),
(22, 20,'结果查询',     'result:list',  '/TmResult/index',   '', 2, 1, 1),
(23, 20,'模拟做题',     'batch:mock',   '/TmMockExam/index', '', 3, 1, 1),
(30, 0, '统计分析',     'dashboard',    '',                  'layui-icon-chart', 4, 1, 1),
(31, 30,'成熟度看板',   'dashboard:dist','/TmDashboard/index','', 1, 1, 1),
(32, 30,'成长趋势',     'dashboard:trend','/TmDashboard/trend','', 2, 1, 1),
(33, 30,'班组排行榜',   'dashboard:rank','/TmDashboard/rank','', 3, 1, 1),
(40, 0, '系统管理',     'sys',          '',                  'layui-icon-set', 5, 1, 1),
(41, 40,'管理员账号',   'admin:list',   '/TmAdmin/index',    '', 1, 1, 1),
(42, 40,'角色权限',     'role:list',    '/TmRole/index',     '', 2, 1, 1),
(43, 40,'导入任务',     'import:list',  '/TmImport/index',   '', 3, 1, 1),
(44, 40,'操作日志',     'log:list',     '/TmLog/index',      '', 4, 1, 1);

-- 超管拥有全部菜单
INSERT INTO `tm_role_power` (`role_id`, `power_id`)
SELECT 1, `id` FROM `tm_power`;


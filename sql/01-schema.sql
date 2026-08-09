-- ============================================================
-- 班组安全成熟度测评系统 - 数据库脚本（第一步）
-- 库名建议：team_maturity（也可与旧 qa 同实例，表前缀 tm_ 隔离）
-- 字符集：utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS `team_maturity`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `team_maturity`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 1. 组织 / 班组 / 成员
-- ----------------------------

DROP TABLE IF EXISTS `tm_org`;
CREATE TABLE `tm_org` (
  `id`          BIGINT       NOT NULL AUTO_INCREMENT,
  `parent_id`   BIGINT       NOT NULL DEFAULT 0 COMMENT '父节点，0=根',
  `name`        VARCHAR(128) NOT NULL COMMENT '组织名称',
  `org_type`    TINYINT      NOT NULL DEFAULT 1 COMMENT '类型标签(不限制层级)：1公司/集团 2厂/分厂 3工段/部门 0其他',
  `sort_no`     INT          NOT NULL DEFAULT 0,
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_type_status` (`org_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='组织架构树';

DROP TABLE IF EXISTS `tm_team`;
CREATE TABLE `tm_team` (
  `id`          BIGINT       NOT NULL AUTO_INCREMENT,
  `org_id`      BIGINT       NOT NULL COMMENT '所属组织节点（任意层级，两层或三层均可）',
  `name`        VARCHAR(128) NOT NULL COMMENT '班组名称',
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用；停用不可参与新测评',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org` (`org_id`),
  KEY `idx_name` (`name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='班组档案';

DROP TABLE IF EXISTS `tm_member`;
CREATE TABLE `tm_member` (
  `id`          BIGINT       NOT NULL AUTO_INCREMENT,
  `team_id`     BIGINT       NOT NULL COMMENT '所属班组',
  `name`        VARCHAR(64)  NOT NULL COMMENT '姓名',
  `mobile`      CHAR(11)     NULL DEFAULT NULL COMMENT '手机号（可选；PC补录/导入时使用）',
  `post_name`   VARCHAR(64)  NULL DEFAULT NULL COMMENT '岗位',
  `role_code`   VARCHAR(32)  NOT NULL DEFAULT 'member' COMMENT 'leader班组长 deputy组长 safety安全员 part_safety兼职安全员 member普通成员',
  `status`      TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `wx_user_id`  BIGINT       NULL DEFAULT NULL COMMENT '已绑定的小程序用户',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mobile` (`mobile`),
  KEY `idx_team` (`team_id`),
  KEY `idx_wx` (`wx_user_id`),
  KEY `idx_team_status` (`team_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='班组成员';

DROP TABLE IF EXISTS `tm_wx_user`;
CREATE TABLE `tm_wx_user` (
  `id`          BIGINT       NOT NULL AUTO_INCREMENT,
  `open_id`     VARCHAR(64)  NOT NULL,
  `union_id`    VARCHAR(64)  NULL DEFAULT NULL,
  `nickname`    VARCHAR(64)  NULL DEFAULT NULL,
  `avatar_url`  VARCHAR(512) NULL DEFAULT NULL,
  `mobile`      CHAR(11)     NULL DEFAULT NULL,
  `member_id`   BIGINT       NULL DEFAULT NULL COMMENT '绑定的成员档案',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_openid` (`open_id`),
  KEY `idx_mobile` (`mobile`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='小程序用户';

-- ----------------------------
-- 2. 指标体系 / 题库 / 版本
-- ----------------------------

DROP TABLE IF EXISTS `tm_indicator_version`;
CREATE TABLE `tm_indicator_version` (
  `id`            BIGINT       NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(128) NOT NULL COMMENT '版本名称',
  `version_no`    VARCHAR(32)  NOT NULL COMMENT '版本号，如 V1.0.0',
  `status`        TINYINT      NOT NULL DEFAULT 0 COMMENT '0草稿 1已发布 2停用',
  `published_at`  DATETIME     NULL DEFAULT NULL,
  `remark`        VARCHAR(255) NULL DEFAULT NULL,
  `question_pick_mode` VARCHAR(16) NOT NULL DEFAULT 'random' COMMENT '开测抽题：first/random/sequential；三级多题时每个指标抽1道',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ver` (`version_no`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='指标版本';

DROP TABLE IF EXISTS `tm_indicator`;
CREATE TABLE `tm_indicator` (
  `id`          BIGINT        NOT NULL AUTO_INCREMENT,
  `version_id`  BIGINT        NOT NULL,
  `parent_id`   BIGINT        NOT NULL DEFAULT 0,
  `level_no`    TINYINT       NOT NULL COMMENT '1一级 2二级 3三级',
  `code`        VARCHAR(32)   NULL DEFAULT NULL,
  `name`        VARCHAR(128)  NOT NULL,
  `weight`      DECIMAL(8,4)  NOT NULL DEFAULT 0.0000 COMMENT '一级/三级配置权重；二级可为0。约定：一级权重合计=100，三级在其一级下按业务规则合计',
  `sort_no`     INT           NOT NULL DEFAULT 0,
  `status`      TINYINT       NOT NULL DEFAULT 1,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ver_parent` (`version_id`, `parent_id`),
  KEY `idx_ver_level` (`version_id`, `level_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评价指标树';

DROP TABLE IF EXISTS `tm_question`;
CREATE TABLE `tm_question` (
  `id`            BIGINT       NOT NULL AUTO_INCREMENT,
  `version_id`    BIGINT       NOT NULL,
  `indicator_id`  BIGINT       NOT NULL COMMENT '三级指标ID；同一指标可挂多题形成题池，开测时抽1道写入批次快照',
  `title`         VARCHAR(512) NOT NULL COMMENT '题干',
  `remark`        VARCHAR(512) NULL DEFAULT NULL,
  `sort_no`       INT          NOT NULL DEFAULT 0,
  `status`        TINYINT      NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ind` (`indicator_id`),
  KEY `idx_ver` (`version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='测评题目';

DROP TABLE IF EXISTS `tm_option`;
CREATE TABLE `tm_option` (
  `id`           BIGINT       NOT NULL AUTO_INCREMENT,
  `question_id`  BIGINT       NOT NULL,
  `content`      VARCHAR(255) NOT NULL COMMENT '选项内容',
  `score_ratio`  DECIMAL(5,4) NOT NULL COMMENT '得分比例，默认1.0/0.75/0.5/0.25',
  `sort_no`      INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_q` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='题目选项';

DROP TABLE IF EXISTS `tm_batch_indicator_snap`;
CREATE TABLE `tm_batch_indicator_snap` (
  `id`                  BIGINT     NOT NULL AUTO_INCREMENT,
  `batch_id`            BIGINT     NOT NULL,
  `source_version_id`   BIGINT     NOT NULL,
  `snap_json`           MEDIUMTEXT NOT NULL COMMENT '批次开始时冻结的指标+题目+选项完整快照',
  `created_at`          DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='批次指标快照';

-- ----------------------------
-- 3. 测评批次 / 答卷 / 结果
-- ----------------------------

DROP TABLE IF EXISTS `tm_batch`;
CREATE TABLE `tm_batch` (
  `id`            BIGINT       NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(128) NOT NULL,
  `version_id`    BIGINT       NOT NULL COMMENT '绑定的已发布指标版本',
  `question_pick_mode` VARCHAR(16) NULL DEFAULT NULL COMMENT '空=跟随版本；非空覆盖本批次抽题策略',
  `start_time`    DATETIME     NOT NULL,
  `end_time`      DATETIME     NOT NULL,
  `status`        TINYINT      NOT NULL DEFAULT 0 COMMENT '0未开始 1进行中 2已结束',
  `score_status`  TINYINT      NOT NULL DEFAULT 0 COMMENT '0未算分 1算分中 2已算分 3失败',
  `created_by`    BIGINT       NULL DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`start_time`, `end_time`),
  KEY `idx_status` (`status`),
  KEY `idx_version` (`version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='测评批次';

DROP TABLE IF EXISTS `tm_batch_team`;
CREATE TABLE `tm_batch_team` (
  `id`             BIGINT NOT NULL AUTO_INCREMENT,
  `batch_id`       BIGINT NOT NULL,
  `team_id`        BIGINT NOT NULL,
  `member_total`   INT    NOT NULL DEFAULT 0 COMMENT '绑定时启用成员数快照',
  `submitted_cnt`  INT    NOT NULL DEFAULT 0 COMMENT '已提交人数',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_team` (`batch_id`, `team_id`),
  KEY `idx_team` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='批次参与班组';

DROP TABLE IF EXISTS `tm_paper`;
CREATE TABLE `tm_paper` (
  `id`              BIGINT        NOT NULL AUTO_INCREMENT,
  `batch_id`        BIGINT        NOT NULL,
  `team_id`         BIGINT        NOT NULL,
  `member_id`       BIGINT        NOT NULL,
  `wx_user_id`      BIGINT        NULL DEFAULT NULL,
  `status`          TINYINT       NOT NULL DEFAULT 0 COMMENT '0答题中 1已提交',
  `personal_score`  DECIMAL(8,2)  NULL DEFAULT NULL,
  `submitted_at`    DATETIME      NULL DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_member` (`batch_id`, `member_id`),
  KEY `idx_batch_team` (`batch_id`, `team_id`),
  KEY `idx_batch_status` (`batch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='成员答卷';

DROP TABLE IF EXISTS `tm_paper_answer`;
CREATE TABLE `tm_paper_answer` (
  `id`            BIGINT        NOT NULL AUTO_INCREMENT,
  `paper_id`      BIGINT        NOT NULL,
  `question_id`   BIGINT        NOT NULL,
  `option_id`     BIGINT        NOT NULL,
  `indicator_id`  BIGINT        NOT NULL COMMENT '三级指标',
  `weight`        DECIMAL(8,4)  NOT NULL COMMENT '答题时固化的题目权重',
  `score_ratio`   DECIMAL(5,4)  NOT NULL COMMENT '答题时固化的选项比例',
  `item_score`    DECIMAL(8,4)  NOT NULL COMMENT 'weight * score_ratio',
  `answered_at`   DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_paper_q` (`paper_id`, `question_id`),
  KEY `idx_paper` (`paper_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='答题明细';

DROP TABLE IF EXISTS `tm_team_result`;
CREATE TABLE `tm_team_result` (
  `id`                   BIGINT        NOT NULL AUTO_INCREMENT,
  `batch_id`             BIGINT        NOT NULL,
  `team_id`              BIGINT        NOT NULL,
  `team_score`           DECIMAL(8,2)  NOT NULL,
  `level_code`           VARCHAR(32)   NOT NULL COMMENT 'passive/dependent/independent/essential',
  `submit_cnt`           INT           NOT NULL DEFAULT 0,
  `weak_indicator_json`  TEXT          NULL COMMENT '薄弱指标及得分 JSON',
  `scored_at`            DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_team` (`batch_id`, `team_id`),
  KEY `idx_level` (`batch_id`, `level_code`),
  KEY `idx_score` (`batch_id`, `team_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='班组批次结果';

DROP TABLE IF EXISTS `tm_level_threshold`;
CREATE TABLE `tm_level_threshold` (
  `id`            BIGINT         NOT NULL AUTO_INCREMENT,
  `level_code`    VARCHAR(32)    NOT NULL,
  `level_name`    VARCHAR(64)    NOT NULL,
  `min_score`     DECIMAL(8,2)   NOT NULL,
  `max_score`     DECIMAL(8,2)   NULL DEFAULT NULL COMMENT 'NULL 表示无上界',
  `feature_desc`  VARCHAR(1024)  NULL DEFAULT NULL COMMENT '等级特征描述',
  `sort_no`       INT            NOT NULL DEFAULT 0,
  `status`        TINYINT        NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`level_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='成熟度等级阈值';

DROP TABLE IF EXISTS `tm_empower_measure`;
CREATE TABLE `tm_empower_measure` (
  `id`            BIGINT       NOT NULL AUTO_INCREMENT,
  `level_code`    VARCHAR(32)  NOT NULL,
  `indicator_id`  BIGINT       NULL DEFAULT NULL COMMENT '空=等级通用措施；非空=薄弱指标专项',
  `title`         VARCHAR(128) NOT NULL,
  `content`       TEXT         NOT NULL,
  `sort_no`       INT          NOT NULL DEFAULT 0,
  `status`        TINYINT      NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_level` (`level_code`),
  KEY `idx_indicator` (`indicator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='精准赋能建设措施';

-- ----------------------------
-- 4. 导入 / 日志
-- ----------------------------

DROP TABLE IF EXISTS `tm_import_job`;
CREATE TABLE `tm_import_job` (
  `id`           BIGINT       NOT NULL AUTO_INCREMENT,
  `type`         VARCHAR(32)  NOT NULL COMMENT 'org/team/member',
  `file_path`    VARCHAR(255) NOT NULL,
  `total_cnt`    INT          NOT NULL DEFAULT 0,
  `success_cnt`  INT          NOT NULL DEFAULT 0,
  `fail_cnt`     INT          NOT NULL DEFAULT 0,
  `error_json`   MEDIUMTEXT   NULL,
  `status`       TINYINT      NOT NULL DEFAULT 0 COMMENT '0处理中 1成功 2部分失败 3失败',
  `created_by`   BIGINT       NULL DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_time` (`type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Excel导入任务';

DROP TABLE IF EXISTS `tm_op_log`;
CREATE TABLE `tm_op_log` (
  `id`          BIGINT       NOT NULL AUTO_INCREMENT,
  `admin_id`    BIGINT       NULL DEFAULT NULL,
  `module`      VARCHAR(64)  NOT NULL,
  `action`      VARCHAR(64)  NOT NULL,
  `biz_id`      VARCHAR(64)  NULL DEFAULT NULL,
  `detail`      TEXT         NULL,
  `ip`          VARCHAR(64)  NULL DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mod_time` (`module`, `created_at`),
  KEY `idx_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- 5. 初始数据：等级阈值
-- ----------------------------
-- 判级口径对齐《班组成熟度模型需求规格说明书》：
-- 被动型＜70；依赖型70～80；自立型80～90；本质安全型＞90
-- 实现约定（左闭右开，90 分归自立型）：[0,70) [70,80) [80,90] (90,+∞)
INSERT INTO `tm_level_threshold`
  (`level_code`, `level_name`, `min_score`, `max_score`, `feature_desc`, `sort_no`, `status`)
VALUES
  ('passive',     '被动型',     0.00,  69.99, '被动整改阶段，依靠作业本能开展现场操作、安全处置仅事后整改，无前置风险防控、安全责任完全依托上级管理、班组自主履职缺失、缺少班组常态化安全管理机制，事故隐患频发', 1, 1),
  ('dependent',   '依赖型',    70.00,  79.99, '外部驱动阶段，依靠管理层监督、制度纪律约束作业行为、安全执行动力来自考核、处罚等受雇约束条件、依托作业规程、现场监督管控岗位风险配套安全培训、目标考核倒逼合规操作', 2, 1),
  ('independent', '自立型',    80.00,  90.00, '自主管控阶段，熟知岗位安全知识、标准并主动践行承诺、安全要求内化形成个人习惯，主动辨识消除风险以自我安全价值为导向，自觉关注岗位风险自主开展隐患上报、风险管控个人安全贡献得到认可', 3, 1),
  ('essential',   '本质安全型', 90.01, 100.00, '系统共治阶段，主动留心、协助身边同事遵守安全规范、建立班组互查互保机制，关注全员作业安全状态、以团队安全贡献、集体安全荣誉为核心导向、多维度系统治理风险根源，实现班组零违章、零隐患、零事故', 4, 1);

-- 示例赋能措施（可后续按客户内容替换）
INSERT INTO `tm_empower_measure` (`level_code`, `indicator_id`, `title`, `content`, `sort_no`, `status`) VALUES
('passive',     NULL, '强化基础制度宣贯', '开展班前会标准化、岗位安全职责再培训，建立每日隐患排查打卡。', 1, 1),
('dependent',   NULL, '完善自主管理机制', '明确班组长安全履职清单，推行周复盘与薄弱环节专项改进。', 1, 1),
('independent', NULL, '深化风险预控',     '围绕薄弱指标开展专题攻关，形成班组自查-自改-自验闭环。', 1, 1),
('essential',   NULL, '打造示范班组',     '固化优秀实践，开展跨班组经验分享与标准化输出。', 1, 1);

-- ----------------------------
-- 6. 算分约定（应用层实现，非 SQL）
-- ----------------------------
-- 题目得分 item_score = weight * score_ratio
-- 个人得分 personal_score = SUM(item_score)           （权重按百分制配置时总分约 0~100）
-- 班组得分 team_score     = AVG(已提交 personal_score)
-- 等级判定                = 按 tm_level_threshold 区间匹配（含下界，不含上界；最高级无上界）
-- 批次开始时写入 tm_batch_indicator_snap，答题与算分只读快照

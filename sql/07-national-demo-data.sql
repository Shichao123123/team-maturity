-- 全国首页数据库演示数据
-- 只写 tm_org 演示字段，不创建班组、批次、答卷或算分结果。
USE `team_maturity`;
SET NAMES utf8mb4;

SET @has_demo_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tm_org' AND COLUMN_NAME = 'is_demo'
);
SET @add_demo_columns = IF(@has_demo_column = 0,
  'ALTER TABLE `tm_org`
     ADD COLUMN `is_demo` TINYINT NOT NULL DEFAULT 0 COMMENT ''1首页演示企业；不参与真实测评统计'' AFTER `org_type`,
     ADD COLUMN `demo_score` DECIMAL(5,2) NULL DEFAULT NULL COMMENT ''首页演示综合分'' AFTER `is_demo`,
     ADD COLUMN `demo_level_code` VARCHAR(32) NULL DEFAULT NULL AFTER `demo_score`,
     ADD COLUMN `demo_level_name` VARCHAR(32) NULL DEFAULT NULL AFTER `demo_level_code`,
     ADD COLUMN `demo_team_cnt` INT NOT NULL DEFAULT 0 AFTER `demo_level_name`,
     ADD COLUMN `demo_sample_cnt` INT NOT NULL DEFAULT 0 AFTER `demo_team_cnt`,
     ADD COLUMN `demo_eval_cnt` INT NOT NULL DEFAULT 0 AFTER `demo_sample_cnt`,
     ADD COLUMN `demo_scored_at` DATETIME NULL DEFAULT NULL AFTER `demo_eval_cnt`,
     ADD COLUMN `demo_l1_json` TEXT NULL AFTER `demo_scored_at`,
     ADD COLUMN `demo_strengths_json` TEXT NULL AFTER `demo_l1_json`,
     ADD COLUMN `demo_weaknesses_json` TEXT NULL AFTER `demo_strengths_json`,
     ADD KEY `idx_demo` (`is_demo`)',
  'SELECT 1'
);
PREPARE demo_stmt FROM @add_demo_columns;
EXECUTE demo_stmt;
DEALLOCATE PREPARE demo_stmt;

DROP TEMPORARY TABLE IF EXISTS `tmp_national_demo_org`;
CREATE TEMPORARY TABLE `tmp_national_demo_org` (
  `name` VARCHAR(128) NOT NULL,
  `industry` VARCHAR(64) NOT NULL,
  `province` VARCHAR(32) NOT NULL,
  `city` VARCHAR(64) NOT NULL,
  `lng` DECIMAL(10,6) NOT NULL,
  `lat` DECIMAL(10,6) NOT NULL,
  `score` DECIMAL(5,2) NOT NULL,
  `level_code` VARCHAR(32) NOT NULL,
  `level_name` VARCHAR(32) NOT NULL,
  `team_cnt` INT NOT NULL,
  `sample_cnt` INT NOT NULL,
  `eval_cnt` INT NOT NULL
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4;

INSERT INTO `tmp_national_demo_org` VALUES
('【演示】北京首安钢铁有限公司','钢铁','北京市','北京市',116.407400,39.904200,91.8,'essential','本质安全型',42,1134,8),
('【演示】天津滨海冶金有限公司','钢铁','天津市','天津市',117.201000,39.084200,86.4,'independent','自主型',35,826,7),
('【演示】河北燕赵钢铁集团','钢铁','河北省','石家庄市',114.502500,38.045500,79.6,'dependent','依赖型',48,1205,9),
('【演示】山西晋安焦化集团','焦化','山西省','太原市',112.549200,37.857000,72.5,'dependent','依赖型',29,684,6),
('【演示】内蒙古北疆有色金属有限公司','有色金属','内蒙古自治区','包头市',109.840300,40.657400,67.3,'passive','被动型',31,742,5),
('【演示】辽宁辽河特钢有限公司','钢铁','辽宁省','沈阳市',123.429100,41.796800,88.9,'independent','自主型',40,1018,8),
('【演示】吉林长白冶炼有限公司','有色金属','吉林省','长春市',125.324500,43.886800,76.8,'dependent','依赖型',24,538,5),
('【演示】黑龙江北安钢铁有限公司','钢铁','黑龙江省','哈尔滨市',126.642500,45.756900,82.1,'independent','自主型',27,615,6),
('【演示】上海临港特钢集团','钢铁','上海市','上海市',121.473700,31.230400,93.2,'essential','本质安全型',52,1460,10),
('【演示】江苏江海冶金集团','钢铁','江苏省','南京市',118.767400,32.041500,89.7,'independent','自主型',46,1288,9),
('【演示】浙江钱塘有色有限公司','有色金属','浙江省','杭州市',120.153600,30.287500,84.5,'independent','自主型',33,792,7),
('【演示】安徽皖江钢铁有限公司','钢铁','安徽省','合肥市',117.283000,31.861200,78.2,'dependent','依赖型',28,647,6),
('【演示】福建海峡冶炼有限公司','有色金属','福建省','福州市',119.306200,26.075300,81.6,'independent','自主型',25,590,6),
('【演示】江西赣江铜业有限公司','有色金属','江西省','南昌市',115.892200,28.676500,74.9,'dependent','依赖型',36,874,7),
('【演示】山东齐鲁钢铁集团','钢铁','山东省','济南市',117.000900,36.675800,90.5,'essential','本质安全型',58,1625,11),
('【演示】河南中原冶金有限公司','钢铁','河南省','郑州市',113.665400,34.757900,83.7,'independent','自主型',44,1106,8),
('【演示】武汉钢铁有限公司','钢铁','湖北省','武汉市',114.298600,30.584400,92.4,'essential','本质安全型',36,986,8),
('【演示】湖南湘江有色集团','有色金属','湖南省','长沙市',112.982300,28.194100,77.6,'dependent','依赖型',30,708,6),
('【演示】广东岭南冶金有限公司','钢铁','广东省','广州市',113.264400,23.129100,87.2,'independent','自主型',39,1042,8),
('【演示】广西八桂铝业集团','有色金属','广西壮族自治区','南宁市',108.320000,22.824000,69.8,'passive','被动型',26,608,5),
('【演示】重庆山城特钢有限公司','钢铁','重庆市','重庆市',106.551600,29.563000,85.9,'independent','自主型',34,840,7),
('【演示】四川川蜀冶金集团','钢铁','四川省','成都市',104.065700,30.659500,80.3,'independent','自主型',41,975,8),
('【演示】云南云岭铜业有限公司','有色金属','云南省','昆明市',102.712300,25.040600,73.4,'dependent','依赖型',32,754,6),
('【演示】陕西秦岭焦化有限公司','焦化','陕西省','西安市',108.948000,34.263200,66.5,'passive','被动型',23,515,5);

INSERT INTO `tm_org`
(`parent_id`,`name`,`org_type`,`is_demo`,`demo_score`,`demo_level_code`,`demo_level_name`,
 `demo_team_cnt`,`demo_sample_cnt`,`demo_eval_cnt`,`demo_scored_at`,`demo_l1_json`,
 `demo_strengths_json`,`demo_weaknesses_json`,`industry`,`province`,`city`,`lng`,`lat`,`sort_no`,`status`)
SELECT 0,t.name,1,1,t.score,t.level_code,t.level_name,t.team_cnt,t.sample_cnt,t.eval_cnt,
       '2026-08-31 18:00:00',
       CONCAT('[{"name":"班组安全管理基础","score":',ROUND(t.score+1.8,1),'},{"name":"班组安全教育培训","score":',ROUND(t.score-0.6,1),'},{"name":"双重预防机制管理","score":',ROUND(t.score+0.9,1),'},{"name":"班组现场作业管理","score":',ROUND(t.score-2.1,1),'},{"name":"事故管理及应急处置","score":',ROUND(t.score-1.2,1),'},{"name":"班组安全活动","score":',ROUND(t.score+1.2,1),'}]'),
       '["班组安全管理基础","双重预防机制管理","班组安全活动"]',
       '["班组现场作业管理","事故管理及应急处置","班组安全教育培训"]',
       t.industry,t.province,t.city,t.lng,t.lat,900,1
FROM `tmp_national_demo_org` t
LEFT JOIN `tm_org` o ON o.name=t.name AND o.is_demo=1
WHERE o.id IS NULL;

UPDATE `tm_org` o
JOIN `tmp_national_demo_org` t ON t.name=o.name
SET o.is_demo=1,o.demo_score=t.score,o.demo_level_code=t.level_code,o.demo_level_name=t.level_name,
    o.demo_team_cnt=t.team_cnt,o.demo_sample_cnt=t.sample_cnt,o.demo_eval_cnt=t.eval_cnt,
    o.demo_scored_at='2026-08-31 18:00:00',o.industry=t.industry,o.province=t.province,o.city=t.city,
    o.lng=t.lng,o.lat=t.lat,o.status=1,o.deleted_at=NULL
WHERE o.is_demo=1;

SELECT COUNT(*) AS demo_company_count FROM `tm_org` WHERE `is_demo`=1 AND `deleted_at` IS NULL;

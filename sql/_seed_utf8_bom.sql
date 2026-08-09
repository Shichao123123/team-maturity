USE team_maturity;
SET NAMES utf8mb4;
DELETE FROM tm_empower_measure;
DELETE FROM tm_level_threshold;
INSERT INTO tm_level_threshold (level_code, level_name, min_score, max_score, feature_desc, sort_no, status) VALUES
('passive','被动型',0.00,69.99,'被动整改阶段，依靠作业本能开展现场操作、安全处置仅事后整改，无前置风险防控、安全责任完全依托上级管理、班组自主履职缺失、缺少班组常态化安全管理机制，事故隐患频发',1,1),
('dependent','依赖型',70.00,79.99,'外部驱动阶段，依靠管理层监督、制度纪律约束作业行为、安全执行动力来自考核、处罚等受雇约束条件、依托作业规程、现场监督管控岗位风险配套安全培训、目标考核倒逼合规操作',2,1),
('independent','自立型',80.00,90.00,'自主管控阶段，熟知岗位安全知识、标准并主动践行承诺、安全要求内化形成个人习惯，主动辨识消除风险以自我安全价值为导向，自觉关注岗位风险自主开展隐患上报、风险管控个人安全贡献得到认可',3,1),
('essential','本质安全型',90.01,NULL,'系统共治阶段，主动留心、协助身边同事遵守安全规范、建立班组互查互保机制，关注全员作业安全状态、以团队安全贡献、集体安全荣誉为核心导向、多维度系统治理风险根源，实现班组零违章、零隐患、零事故',4,1);
INSERT INTO tm_empower_measure (level_code, indicator_id, title, content, sort_no, status) VALUES
('passive',NULL,'强化基础制度宣贯','开展班前会标准化、岗位安全职责再培训，建立每日隐患排查打卡。',1,1),
('dependent',NULL,'完善自主管理机制','明确班组长安全履职清单，推行周复盘与薄弱环节专项改进。',1,1),
('independent',NULL,'深化风险预控','围绕薄弱指标开展专题攻关，形成班组自查-自改-自验闭环。',1,1),
('essential',NULL,'打造示范班组','固化优秀实践，开展跨班组经验分享与标准化输出。',1,1);
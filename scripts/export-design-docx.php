<?php
/**
 * 生成技术方案/设计方案 Word
 * 用法：php scripts/export-design-docx.php
 */
$out = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR
    . '技术方案设计方案.docx';

function e($s)
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function p($text, $style = 'Normal')
{
    $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="21"/><w:szCs w:val="21"/></w:rPr>';
    $pPr = '<w:pPr><w:spacing w:after="60" w:line="360" w:lineRule="auto"/></w:pPr>';
    if ($style === 'Title') {
        $pPr = '<w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="200"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="36"/><w:szCs w:val="36"/></w:rPr>';
    } elseif ($style === 'Subtitle') {
        $pPr = '<w:pPr><w:jc w:val="center"/><w:spacing w:after="200"/></w:pPr>';
        $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:color w:val="666666"/></w:rPr>';
    } elseif ($style === 'H1') {
        $pPr = '<w:pPr><w:spacing w:before="280" w:after="120"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr>';
    } elseif ($style === 'H2') {
        $pPr = '<w:pPr><w:spacing w:before="200" w:after="80"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr>';
    } elseif ($style === 'H3') {
        $pPr = '<w:pPr><w:spacing w:before="160" w:after="60"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>';
    } elseif ($style === 'Code') {
        $rPr = '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:eastAsia="宋体"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr>';
        $pPr = '<w:pPr><w:spacing w:after="20" w:line="276" w:lineRule="auto"/></w:pPr>';
    } elseif ($style === 'Note') {
        $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="666666"/></w:rPr>';
    }
    return '<w:p>' . $pPr . '<w:r>' . $rPr . '<w:t xml:space="preserve">' . e($text) . '</w:t></w:r></w:p>';
}

function emptyP()
{
    return '<w:p><w:r><w:t></w:t></w:r></w:p>';
}

/** 简单两列表格 */
function table2($rows, $header = true)
{
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:color="999999"/>'
        . '<w:left w:val="single" w:sz="4" w:color="999999"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="999999"/>'
        . '<w:right w:val="single" w:sz="4" w:color="999999"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>'
        . '<w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>'
        . '</w:tblBorders></w:tblPr>';
    foreach ($rows as $i => $row) {
        $bold = ($header && $i === 0);
        $xml .= '<w:tr>';
        foreach ($row as $cell) {
            $rPr = $bold
                ? '<w:rPr><w:b/><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="20"/></w:rPr>'
                : '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="19"/></w:rPr>';
            $shd = ($header && $i === 0) ? '<w:tcPr><w:shd w:val="clear" w:fill="D9E8FB"/></w:tcPr>' : '<w:tcPr/>';
            $xml .= '<w:tc>' . $shd . '<w:p><w:r>' . $rPr . '<w:t xml:space="preserve">' . e($cell) . '</w:t></w:r></w:p></w:tc>';
        }
        $xml .= '</w:tr>';
    }
    $xml .= '</w:tbl>';
    return $xml . emptyP();
}

$body = '';
$body .= p('班组安全成熟度测评系统', 'Title');
$body .= p('技术方案 / 设计方案', 'Title');
$body .= p('用于客户技术沟通与内部研发对齐 · 配套功能清单见《客户需求对账清单》', 'Subtitle');
$body .= emptyP();

$body .= p('1. 建设目标与定位', 'H1');
$body .= p('1.1 业务目标', 'H2');
$body .= p('以班组四级成熟度模型（被动型 → 依赖型 → 自立型 → 本质安全型）为标尺，实现：线上测评、分级画像、精准赋能、分析看板，支撑班组持续升级。');
$body .= p('1.2 端侧分工（PC First）', 'H2');
$body .= table2([
    ['端', '职责', '不做'],
    ['PC 管理端', '组织人员、题库与阈值、赋能措施、发起批次、算分判级、结果与看板、权限', '现场扫码答题（可用模拟做题联调）'],
    ['微信小程序', '登录绑定、扫码进批次、答题提交、看本人/本班结果与建议', '组织配置、题库维护、批次管理、看板'],
]);

$body .= p('2. 总体架构', 'H1');
$body .= p('2.1 逻辑分层', 'H2');
$body .= table2([
    ['层', '说明'],
    ['展示层', 'Pear 管理后台；微信小程序'],
    ['应用层', 'ThinkPHP Controller（Tm* / TmMini*）'],
    ['领域服务', '抽题、算分、判级、导入、日志'],
    ['数据层', 'MySQL tm_* 表；Session（PC 登录 / 模拟试卷）'],
]);
$body .= p('2.2 技术栈', 'H2');
$body .= p('PC：ThinkPHP 5 + Pear/Layui；小程序：微信端答题；数据库：MySQL 5.7+，库名 team_maturity，表前缀 tm_。');
$body .= p('2.3 功能模块关联（文字示意）', 'H2');
$body .= p('组织档案 → 班组 → 成员 → 个人答卷', 'Code');
$body .= p('指标版本 → 指标树 → 题目/选项；指标 → 赋能措施', 'Code');
$body .= p('指标版本 + 班组 → 测评批次 → 开测快照 → 答卷/答题明细', 'Code');
$body .= p('答卷 → 个人分 → 班组结果；等级阈值 → 判级；措施 → 赋能建议 → 工作台/看板', 'Code');
$body .= p('关联解读：没有组织人员无法真实开测；没有题库无法抽题；没有阈值无法判级；没有措施则结果只能出分无法精准帮扶；小程序只消费「批次+成员+快照」，配置全在 PC。');

$body .= p('3. 端到端工作流程', 'H1');
$body .= p('3.1 主流程概览', 'H2');
$body .= p('① PC 维护组织/班组/成员 → ② 维护并发布指标版本与题库 → ③ 配置等级阈值与赋能措施 → ④ 创建批次并绑定班组 → ⑤ 开始批次（每三级抽 1 题写入快照）→ ⑥ 成员小程序答题提交 → ⑦ 个人/班组算分与判级 → ⑧ 薄弱指标推送措施 → ⑨ 结果查询与看板分析。');
$body .= p('3.2 分阶段说明', 'H2');
$body .= p('阶段 A：主数据准备', 'H3');
$body .= p('维护组织树（可变层级）；建班组；录入/导入成员（手机号唯一）；确认参评角色范围。');
$body .= p('阶段 B：测评标准准备', 'H3');
$body .= p('建立指标版本（一级权重合计建议 100）；维护题目选项；维护四级阈值与特征；按「指标×四等级」维护赋能措施。');
$body .= p('阶段 C：开测固化试卷', 'H3');
$body .= p('创建批次、绑班组、开始批次；QuestionPickService 抽题写入 tm_batch_indicator_snap；之后答题只读快照，与题库后续修改隔离。');
$body .= p('阶段 D：作答', 'H3');
$body .= p('生成个人答卷 tm_paper；写入 tm_paper_answer；提交后锁定不可改。');
$body .= p('阶段 E：算分判级与赋能', 'H3');
$body .= p('个人分 = Σ(快照权重 × 选项比例)；班组分 = 已提交个人分平均值；判级匹配 tm_level_threshold；薄弱指标关联 tm_empower_measure。');
$body .= p('阶段 F：分析应用', 'H3');
$body .= p('结果查询、工作台/看板、导出报表。');
$body .= p('3.3 模拟做题（联调演示）', 'H2');
$body .= p('PC 选版本即时抽题（Session），交卷后按同口径个人算分/判级/措施；不写正式批次与答卷表，不影响生产测评数据。');

$body .= p('4. 功能模块设计与关联', 'H1');
$body .= table2([
    ['模块', '输入依赖', '产出/服务对象', '关键关联'],
    ['组织档案', '—', '班组挂载点', '组织→班组'],
    ['班组管理', '组织', '批次绑定、成员容器', '班组→成员；批次↔班组'],
    ['成员管理', '班组', '答卷主体、小程序绑定', '成员↔微信用户；成员→答卷'],
    ['指标版本/题库', '—', '批次抽题来源', '版本→指标→题目→选项'],
    ['等级阈值', '—', '判级规则', '阈值←班组结果.level_code'],
    ['赋能措施', '指标、等级', '结果页建议', '措施.indicator_id→三级指标'],
    ['测评批次', '版本、班组', '快照、答卷、结果', '批次是测评事务聚合根'],
    ['模拟做题', '版本、阈值、措施', '演示结果', '复用抽题/算分/判级服务'],
    ['结果/看板', '班组结果', '管理决策', '读 tm_team_result 等'],
    ['系统管理', '—', '权限与审计', '角色→菜单；操作写日志'],
]);

$body .= p('5. 数据模型设计', 'H1');
$body .= p('5.1 主题域划分', 'H2');
$body .= table2([
    ['域', '主要表', '说明'],
    ['权限', 'tm_admin / tm_role / tm_power / tm_role_power', 'PC 登录与菜单授权'],
    ['组织人员', 'tm_org / tm_team / tm_member / tm_wx_user', '档案与小程序身份'],
    ['标准题库', 'tm_indicator_version / tm_indicator / tm_question / tm_option', '版本化指标体系'],
    ['测评运行', 'tm_batch / tm_batch_team / tm_batch_indicator_snap / tm_paper / tm_paper_answer', '批次与答卷'],
    ['结果赋能', 'tm_team_result / tm_level_threshold / tm_empower_measure', '判级与措施'],
    ['运维', 'tm_import_job / tm_op_log', '导入与操作审计'],
]);
$body .= p('5.2 关键逻辑关联', 'H2');
$body .= table2([
    ['从表', '关联字段', '主表', '说明'],
    ['tm_team', 'org_id', 'tm_org', '班组挂任意组织节点'],
    ['tm_member', 'team_id', 'tm_team', '成员属班组'],
    ['tm_member / tm_wx_user', '双向绑定', '彼此', '手机号/OpenID 绑定档案'],
    ['tm_indicator', 'version_id / parent_id', 'version / indicator', '版本内指标树'],
    ['tm_question', 'indicator_id', 'tm_indicator', '题挂三级，可 1:N 题池'],
    ['tm_option', 'question_id', 'tm_question', '选项含 score_ratio'],
    ['tm_empower_measure', 'indicator_id + level_code', '指标 + 等级', '建议每指标四等级各一条'],
    ['tm_batch', 'version_id', 'tm_indicator_version', '批次采用哪套标准'],
    ['tm_batch_team', 'batch_id + team_id', 'batch + team', '参评班组'],
    ['tm_batch_indicator_snap', 'batch_id', 'tm_batch', '开测题集快照（一份）'],
    ['tm_paper', 'batch + member + team', '—', '一人一批次一卷'],
    ['tm_paper_answer', 'paper_id', 'tm_paper', '答题明细'],
    ['tm_team_result', 'batch + team', '—', '一班组一批次一条结果'],
    ['tm_admin', 'role_id', 'tm_role', '管理员角色'],
    ['tm_role_power', 'role_id + power_id', 'role + power', '角色菜单'],
]);
$body .= p('5.3 算分与判级数据流', 'H2');
$body .= p('快照题目.weight × 所选选项.score_ratio → 小题得分', 'Code');
$body .= p('Σ 小题得分 → 个人总分（tm_paper）', 'Code');
$body .= p('AVG(已提交个人总分) → 班组总分（tm_team_result）', 'Code');
$body .= p('班组总分 ∩ tm_level_threshold → level_code', 'Code');
$body .= p('薄弱指标 → tm_empower_measure → 赋能建议', 'Code');
$body .= p('建议判级口径（可配置）：被动 [0,70)；依赖 [70,80)；自立 [80,90]；本质安全 (90,100]。');

$body .= p('6. 关键业务规则', 'H1');
$rules = [
    '手机号全局唯一，作为成员与小程序绑定凭证。',
    '停用班组不可加入新批次；进行中批次已绑定的可答完。',
    '已发布指标版本不宜直接改题；变更应复制新版本。',
    '发布校验建议：一级权重合计≈100；每三级至少 1 题；每题至少 2 选项。',
    '开测时每三级只抽 1 题进快照；答题只认快照。',
    '答卷提交后锁定，禁止改答。',
    '班组均分仅统计已提交；0 人提交则无有效班组结果。',
    '组织层级不强制 2/3 层；班组可挂任意组织节点。',
];
foreach ($rules as $i => $r) {
    $body .= p(($i + 1) . '. ' . $r);
}

$body .= p('7. 非功能设计要点', 'H1');
$body .= table2([
    ['类别', '方案要点'],
    ['安全', 'PC Session；密码 bcrypt；小程序 JWT；按角色菜单授权'],
    ['一致性', '开测快照隔离题库变更；答卷提交后不可改'],
    ['审计', '关键操作写 tm_op_log；导入写 tm_import_job'],
    ['性能', '看板可按批次缓存；列表分页；快照按批次一份'],
    ['扩展', '题池 1:N + 抽题策略；组织可变深；措施按等级扩展'],
    ['部署', '独立库 team_maturity；与旧系统业务隔离'],
]);

$body .= p('8. 与客户对齐时建议强调的设计点', 'H1');
$points = [
    'PC 配置、小程序答题的职责边界。',
    '批次快照机制：开测后改题不影响进行中测评。',
    '个人分 → 班组均分 → 判级 → 措施的业务闭环。',
    '一指标四等级措施如何支撑精准赋能。',
    '参评角色范围、分数边界需业务拍板。',
    '模拟做题仅用于演示联调，不替代正式批次数据。',
];
foreach ($points as $i => $t) {
    $body .= p(($i + 1) . '. ' . $t);
}

$body .= p('9. 附录：表清单一览', 'H1');
$body .= table2([
    ['表名', '中文名'],
    ['tm_admin / tm_role / tm_power / tm_role_power', '管理员 / 角色 / 菜单 / 角色权限'],
    ['tm_org / tm_team / tm_member / tm_wx_user', '组织 / 班组 / 成员 / 微信用户'],
    ['tm_indicator_version / tm_indicator / tm_question / tm_option', '版本 / 指标 / 题目 / 选项'],
    ['tm_batch / tm_batch_team / tm_batch_indicator_snap', '批次 / 批次班组 / 开测快照'],
    ['tm_paper / tm_paper_answer', '个人答卷 / 答题明细'],
    ['tm_team_result', '班组批次结果'],
    ['tm_level_threshold / tm_empower_measure', '等级阈值 / 赋能措施'],
    ['tm_import_job / tm_op_log', '导入任务 / 操作日志'],
]);

$body .= emptyP();
$body .= p('字段细节以 sql/01-schema.sql、sql/03-pc-admin.sql 为准。Markdown 完整版（含示意图）见 docs/技术方案设计方案.md。', 'Note');
$body .= p('客户确认人：____________　　日期：____________　　我方：____________', 'Note');

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    ' . $body . '
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1440" w:right="1134" w:bottom="1440" w:left="1134"/>
    </w:sectPr>
  </w:body>
</w:document>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';

$wordRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

if (file_exists($out)) {
    unlink($out);
}
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "无法创建: {$out}\n");
    exit(1);
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/_rels/document.xml.rels', $wordRels);
$zip->close();
echo "OK: {$out}\n";

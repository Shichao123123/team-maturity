<?php
/**
 * 生成客户需求对账清单 Word
 * 用法：php scripts/export-checklist-docx.php
 */
$out = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR
    . '客户需求对账清单.docx';

function e($s)
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function p($text, $style = 'Normal', $bold = false)
{
    $rPr = $bold ? '<w:rPr><w:b/><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>'
        : '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="21"/><w:szCs w:val="21"/></w:rPr>';
    $pPr = '';
    if ($style === 'Title') {
        $pPr = '<w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="200"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr>';
    } elseif ($style === 'Heading1') {
        $pPr = '<w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr>';
    } elseif ($style === 'Note') {
        $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="666666"/></w:rPr>';
    }
    return '<w:p>' . $pPr . '<w:r>' . $rPr . '<w:t xml:space="preserve">' . e($text) . '</w:t></w:r></w:p>';
}

function emptyP()
{
    return '<w:p><w:r><w:t></w:t></w:r></w:p>';
}

$body = '';
$body .= p('班组安全成熟度测评系统', 'Title');
$body .= p('功能清单（客户对账用）', 'Title');
$body .= p('用途：与客户逐条确认「系统要具备哪些能力」。', 'Note');
$body .= p('说明：本清单只列功能范围，不涉及开发进度。', 'Note');
$body .= emptyP();

$sections = [
    '一、成熟度模型与判级' => [
        '1. 班组四级成熟度：被动型 / 依赖型 / 自立型 / 本质安全型',
        '2. 各等级分数区间可配置',
        '3. 各等级阶段特征描述可配置与展示',
        '4. 按班组测评总分自动判定成熟度等级',
        '待确认：分数边界是否按「被动＜70、依赖 70～80、自立 80～90、本质安全＞90」执行。',
    ],
    '二、组织与人员档案（PC）' => [
        '5. 组织架构管理（树形，层级不强制固定为 2 层或 3 层）',
        '6. 班组管理（班组挂靠所属组织）',
        '7. 成员管理（姓名、手机号、岗位、所属班组、角色、状态）',
        '8. 成员角色：班组长、组长、安全员、兼职安全员、普通成员',
        '9. 组织 / 班组 / 成员支持 Excel 模板下载与批量导入',
        '10. 导入任务记录与失败明细查询',
        '待确认：普通成员是否参与测评（或仅管理岗位参评）。',
    ],
    '三、评价指标与题库（PC）' => [
        '11. 指标版本管理（可维护多套指标体系版本）',
        '12. 三级指标体系：一级 / 二级 / 三级指标（含权重）',
        '13. 标准测评题库：题干、选项、与三级指标对应',
        '14. 题目按「指标权重 × 选项得分比例」计分',
        '15. 题库浏览与查看（按指标树定位题目）',
        '16. 支持同一三级指标挂多道题；开测时每个三级指标抽取 1 道题作答',
    ],
    '四、等级与精准赋能（PC）' => [
        '17. 等级阈值维护（名称、最低分、最高分、阶段特征）',
        '18. 赋能 / 建设措施维护',
        '19. 每个三级指标对应四个等级的措施建议（被动 / 依赖 / 自立 / 本质安全）',
        '20. 测评后按薄弱指标推送对应建设措施',
    ],
    '五、测评业务' => [
        '21. 创建测评批次（选择指标版本、测评周期等）',
        '22. 批次绑定参评班组',
        '23. 批次开始 / 进行中 / 结束状态管理',
        '24. 开测时按规则抽题并固化本次试卷（避免中途改题影响）',
        '25. 查看批次答题进度（已答 / 未答）',
        '26. 个人作答与提交（提交后锁定，不可再改）',
        '27. 个人得分计算',
        '28. 班组得分计算（参与成员已提交成绩的平均分）',
        '29. 班组自动判级',
        '30. 结果查询：班组汇总、个人答卷明细',
        '31. 薄弱指标清单 + 赋能建议查看',
        '32. 结果导出（如班组结果、排行榜 Excel）',
        '33. PC 端模拟做题（联调 / 演示用：抽题、作答、出分、判级、看建议）',
    ],
    '六、小程序答题端' => [
        '34. 微信登录',
        '35. 成员身份绑定（如手机号绑定档案）',
        '36. 扫码进入指定测评批次',
        '37. 在线答题与提交',
        '38. 查看本人得分与本班组成熟度结果',
        '39. 查看薄弱项与改进建议',
    ],
    '七、统计分析与工作台（PC）' => [
        '40. 工作台总览：班组总数、平均成熟得分、本质安全型班组数、被动型班组数',
        '41. 成熟度等级分布（环形图等）',
        '42. 等级明细清单（各等级数量、占比、管理建议）',
        '43. 成熟度看板（按组织等维度查看分布）',
        '44. 班组成长趋势（跨批次得分变化）',
        '45. 班组排行榜',
    ],
    '八、系统管理（PC）' => [
        '46. 管理员账号管理（新增、编辑、启停、重置密码）',
        '47. 角色管理',
        '48. 菜单 / 功能权限分配（按角色授权）',
        '49. 操作日志查询',
        '50. PC 管理端登录与退出',
    ],
];

foreach ($sections as $title => $items) {
    $body .= p($title, 'Heading1');
    foreach ($items as $line) {
        $isConfirm = (mb_strpos($line, '待确认') === 0);
        $body .= p($line, 'Normal', $isConfirm);
    }
}

$body .= p('九、建议当场拍板的事项', 'Heading1');
$body .= p('A. 四级分数边界是否按上文区间　　客户意见：____________');
$body .= p('B. 普通成员是否参评　　客户意见：____________');
$body .= p('C. 小程序是否本期必做（或先 PC 批次 + 模拟，小程序二期）　　客户意见：____________');
$body .= p('D. 看板 / 排行 / 导出是否本期必做　　客户意见：____________');
$body .= p('E. 题库是否允许后期「一指标多题」随机抽题　　客户意见：____________');
$body .= p('F. 赋能措施是否按「每指标 × 四等级」维护　　客户意见：____________');

$body .= p('十、对账勾选（可打印）', 'Heading1');
$body .= p('请在确认纳入本期范围的条目前打勾：');
$checks = [
    '□ 一、成熟度模型与判级（1～4）',
    '□ 二、组织与人员档案（5～10）',
    '□ 三、评价指标与题库（11～16）',
    '□ 四、等级与精准赋能（17～20）',
    '□ 五、测评业务（21～33）',
    '□ 六、小程序答题端（34～39）',
    '□ 七、统计分析与工作台（40～45）',
    '□ 八、系统管理（46～50）',
];
foreach ($checks as $c) {
    $body .= p($c);
}

$body .= emptyP();
$body .= p('客户确认人：____________　　日期：____________');
$body .= p('我方确认人：____________　　日期：____________');

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:body>
    ' . $body . '
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708"/>
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
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>';

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

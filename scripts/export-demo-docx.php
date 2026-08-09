<?php
/**
 * 生成客户演示《系统功能与方案说明》Word（含关系图 / 流程图）
 * 用法：php scripts/export-demo-docx.php
 */

$root = dirname(__DIR__);
$docs = $root . DIRECTORY_SEPARATOR . 'docs';
$mediaDir = $docs . DIRECTORY_SEPARATOR . 'demo-diagrams';
$out = isset($argv[1]) && $argv[1] !== ''
    ? $argv[1]
    : ($docs . DIRECTORY_SEPARATOR . '客户演示-系统功能与方案说明.docx');
if (!preg_match('/[\/\\\\]/', $out)) {
    $out = $docs . DIRECTORY_SEPARATOR . $out;
}
$font = 'C:\\Windows\\Fonts\\simhei.ttf';
if (!is_file($font)) {
    $font = 'C:\\Windows\\Fonts\\msyh.ttc';
}
if (!is_dir($mediaDir)) {
    mkdir($mediaDir, 0777, true);
}

/* ========== GD 绘图工具 ========== */

function hexColor($im, $hex)
{
    $hex = ltrim($hex, '#');
    return imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

function canvas($w, $h, $bg = '#F7F9FC')
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);
    imagefilledrectangle($im, 0, 0, $w, $h, hexColor($im, $bg));
    return $im;
}

function drawRoundRect($im, $x1, $y1, $x2, $y2, $r, $fill, $border = null)
{
    $fillC = hexColor($im, $fill);
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $fillC);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $fillC);
    imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $fillC);
    imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $fillC);
    imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $fillC);
    imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $fillC);
    if ($border) {
        $bc = hexColor($im, $border);
        imagerectangle($im, $x1, $y1, $x2, $y2, $bc);
    }
}

function ttfCenter($im, $font, $size, $text, $cx, $cy, $color)
{
    $bbox = imagettfbbox($size, 0, $font, $text);
    $w = $bbox[2] - $bbox[0];
    $h = $bbox[1] - $bbox[7];
    $x = (int) ($cx - $w / 2);
    $y = (int) ($cy + $h / 2);
    imagettftext($im, $size, 0, $x, $y, hexColor($im, $color), $font, $text);
}

function ttfLeft($im, $font, $size, $text, $x, $y, $color)
{
    imagettftext($im, $size, 0, $x, $y, hexColor($im, $color), $font, $text);
}

function arrowH($im, $x1, $y, $x2, $color = '#4B5563')
{
    $c = hexColor($im, $color);
    imageline($im, $x1, $y, $x2, $y, $c);
    $dir = $x2 >= $x1 ? 1 : -1;
    imagefilledpolygon($im, [
        $x2, $y,
        $x2 - 8 * $dir, $y - 5,
        $x2 - 8 * $dir, $y + 5,
    ], 3, $c);
}

function arrowV($im, $x, $y1, $y2, $color = '#4B5563')
{
    $c = hexColor($im, $color);
    imageline($im, $x, $y1, $x, $y2, $c);
    $dir = $y2 >= $y1 ? 1 : -1;
    imagefilledpolygon($im, [
        $x, $y2,
        $x - 5, $y2 - 8 * $dir,
        $x + 5, $y2 - 8 * $dir,
    ], 3, $c);
}

function box($im, $font, $x, $y, $w, $h, $title, $fill, $border, $titleColor = '#111827', $size = 13)
{
    drawRoundRect($im, $x, $y, $x + $w, $y + $h, 8, $fill, $border);
    ttfCenter($im, $font, $size, $title, $x + $w / 2, $y + $h / 2, $titleColor);
}

function savePng($im, $path)
{
    imagepng($im, $path);
    imagedestroy($im);
    echo "IMG: {$path}\n";
}

/* ========== 图 1：总体架构 ========== */

function makeArch($font, $path)
{
    $im = canvas(1100, 620);
    ttfLeft($im, $font, 18, '图1  系统总体架构', 30, 36, '#111827');
    ttfLeft($im, $font, 12, 'PC 管理端负责配置与分析，小程序负责现场答题，共用后台服务与数据库', 30, 62, '#6B7280');

    // PC group
    drawRoundRect($im, 30, 90, 360, 520, 12, '#E8F1FF', '#93C5FD');
    ttfCenter($im, $font, 15, 'PC 管理端', 195, 118, '#1D4ED8');
    $pcItems = ['组织 / 班组（人员事后同步）', '指标版本 / 题库', '等级阈值 / 赋能措施', '测评批次管理', '结果查询 / 工作台看板', '系统管理（权限）'];
    $yy = 150;
    foreach ($pcItems as $t) {
        box($im, $font, 55, $yy, 260, 42, $t, '#FFFFFF', '#60A5FA', '#1E3A8A', 12);
        $yy += 54;
    }

    // Core
    drawRoundRect($im, 400, 160, 700, 430, 12, '#ECFDF5', '#6EE7B7');
    ttfCenter($im, $font, 15, '领域服务', 550, 190, '#047857');
    box($im, $font, 430, 220, 240, 44, '抽题服务', '#FFFFFF', '#34D399', '#065F46', 13);
    box($im, $font, 430, 285, 240, 44, '个人算分服务', '#FFFFFF', '#34D399', '#065F46', 13);
    box($im, $font, 430, 350, 240, 44, '班组判级服务', '#FFFFFF', '#34D399', '#065F46', 13);

    // Mini
    drawRoundRect($im, 740, 140, 1070, 380, 12, '#FFF7ED', '#FDBA74');
    ttfCenter($im, $font, 15, '微信小程序', 905, 170, '#C2410C');
    box($im, $font, 770, 205, 250, 42, '登录 / 身份确认', '#FFFFFF', '#FB923C', '#9A3412', 12);
    box($im, $font, 770, 265, 250, 42, '扫码答题 / 提交', '#FFFFFF', '#FB923C', '#9A3412', 12);
    box($im, $font, 770, 325, 250, 42, '结果 / 改进建议', '#FFFFFF', '#FB923C', '#9A3412', 12);

    // DB
    box($im, $font, 400, 500, 300, 50, 'MySQL 业务库', '#FEF3C7', '#F59E0B', '#92400E', 14);

    arrowH($im, 360, 280, 400, '#2563EB');
    arrowH($im, 700, 300, 740, '#EA580C');
    arrowV($im, 550, 430, 500, '#D97706');
    arrowV($im, 195, 520, 560, '#2563EB');
    imageline($im, 195, 560, 400, 560, hexColor($im, '#2563EB'));
    imageline($im, 400, 560, 400, 525, hexColor($im, '#2563EB'));
    imageline($im, 905, 380, 905, 560, hexColor($im, '#EA580C'));
    imageline($im, 905, 560, 700, 560, hexColor($im, '#EA580C'));
    imageline($im, 700, 560, 700, 525, hexColor($im, '#EA580C'));

    savePng($im, $path);
}

/* ========== 图 2：功能模块逻辑关系 ========== */

function makeModules($font, $path)
{
    $im = canvas(1180, 560);
    ttfLeft($im, $font, 18, '图2  功能模块逻辑关系', 30, 36, '#111827');
    ttfLeft($im, $font, 12, '说明系统各功能如何串联：档案与标准准备 → 开测 → 作答 → 判级赋能 → 分析', 30, 62, '#6B7280');

    $nodes = [
        ['组织档案', 40, 120, '#DBEAFE', '#2563EB'],
        ['班组', 220, 120, '#DBEAFE', '#2563EB'],
        ['成员', 360, 120, '#DBEAFE', '#2563EB'],
        ['指标版本', 40, 240, '#D1FAE5', '#059669'],
        ['指标树/题库', 220, 240, '#D1FAE5', '#059669'],
        ['赋能措施', 430, 240, '#D1FAE5', '#059669'],
        ['测评批次', 220, 360, '#FEF3C7', '#D97706'],
        ['开测快照', 430, 360, '#FEF3C7', '#D97706'],
        ['个人答卷', 640, 240, '#FFEDD5', '#EA580C'],
        ['个人得分', 820, 240, '#FFEDD5', '#EA580C'],
        ['班组结果', 820, 360, '#FCE7F3', '#DB2777'],
        ['等级阈值', 640, 360, '#FCE7F3', '#DB2777'],
        ['工作台/看板', 1000, 300, '#E0E7FF', '#4F46E5'],
    ];
    foreach ($nodes as $n) {
        box($im, $font, $n[1], $n[2], 140, 48, $n[0], $n[3], $n[4], '#111827', 12);
    }

    // arrows
    arrowH($im, 180, 144, 220);
    arrowH($im, 360, 144, 360); // noop placeholder avoided
    arrowH($im, 360, 144, 360);
    // 组织->班组 already, 班组->成员
    imageline($im, 360, 144, 360, 144, hexColor($im, '#4B5563'));
    arrowH($im, 360, 144, 360);
    // fix: 班组 right edge 360, member left 360 - wait member is at 360
    // 班组 is at 220 w=140 -> right 360; member at 360 - overlapping. Fix positions in redraw...
    // Actually member x=360 touches. Let me redraw with better spacing by regenerating carefully.

    // Cleaner: redraw image with better layout
    imagedestroy($im);
    $im = canvas(1200, 620);
    ttfLeft($im, $font, 18, '图2  功能模块逻辑关系', 30, 36, '#111827');
    ttfLeft($im, $font, 12, '档案与标准准备 → 开测固化试卷 → 作答计分 → 判级赋能 → 分析应用', 30, 62, '#6B7280');

    // Row 1 档案
    box($im, $font, 40, 110, 150, 50, '组织档案', '#DBEAFE', '#2563EB');
    box($im, $font, 230, 110, 130, 50, '班组', '#DBEAFE', '#2563EB');
    box($im, $font, 400, 110, 150, 50, '成员(事后同步)', '#DBEAFE', '#2563EB');
    arrowH($im, 190, 135, 230);
    // 成员不再是开测前置，由答卷提交后回写

    // Row 2 标准
    box($im, $font, 40, 220, 150, 50, '指标版本', '#D1FAE5', '#059669');
    box($im, $font, 230, 220, 150, 50, '指标树/题库', '#D1FAE5', '#059669');
    box($im, $font, 420, 220, 150, 50, '赋能措施', '#D1FAE5', '#059669');
    box($im, $font, 610, 220, 150, 50, '等级阈值', '#FCE7F3', '#DB2777');
    arrowH($im, 190, 245, 230);
    arrowH($im, 380, 245, 420);

    // Row 3 运行
    box($im, $font, 230, 340, 150, 50, '测评批次', '#FEF3C7', '#D97706');
    box($im, $font, 420, 340, 150, 50, '开测快照', '#FEF3C7', '#D97706');
    box($im, $font, 610, 340, 150, 50, '个人答卷', '#FFEDD5', '#EA580C');
    arrowH($im, 380, 365, 420);
    arrowH($im, 570, 365, 610);

    // links from above
    arrowV($im, 295, 160, 220); // 班组 down? actually version/team both feed batch
    arrowV($im, 115, 270, 365);
    imageline($im, 115, 365, 230, 365, hexColor($im, '#4B5563'));
    arrowV($im, 295, 270, 340);
    arrowV($im, 465, 160, 340); // 成员 -> 答卷 area
    imageline($im, 465, 340, 610, 365, hexColor($im, '#4B5563'));
    // simpler vertical from member
    // 成员 to 个人答卷
    imageline($im, 465, 160, 465, 365, hexColor($im, '#4B5563'));
    imageline($im, 465, 365, 610, 365, hexColor($im, '#4B5563'));

    // Row 4 结果
    box($im, $font, 610, 460, 150, 50, '个人得分', '#FFEDD5', '#EA580C');
    box($im, $font, 800, 460, 150, 50, '班组结果', '#FCE7F3', '#DB2777');
    box($im, $font, 990, 460, 160, 50, '工作台/看板', '#E0E7FF', '#4F46E5');
    arrowV($im, 685, 390, 460);
    arrowH($im, 760, 485, 800);
    arrowH($im, 950, 485, 990);
    arrowV($im, 685, 270, 245); // noop
    // 阈值 -> 班组结果
    imageline($im, 685, 270, 875, 270, hexColor($im, '#DB2777'));
    imageline($im, 875, 270, 875, 460, hexColor($im, '#DB2777'));
    // 措施 -> 班组结果
    imageline($im, 495, 270, 495, 510, hexColor($im, '#059669'));
    imageline($im, 495, 510, 800, 510, hexColor($im, '#059669'));
    imageline($im, 800, 510, 800, 510, hexColor($im, '#059669'));
    arrowH($im, 760, 510, 800, '#059669');

    // legend
    ttfLeft($im, $font, 11, '蓝：组织人员　绿：测评标准　橙：测评运行　粉：判级赋能　紫：分析应用', 40, 580, '#6B7280');

    savePng($im, $path);
}

/* ========== 图 3：端到端系统流程 ========== */

function makeFlow($font, $path)
{
    $im = canvas(1180, 720);
    ttfLeft($im, $font, 18, '图3  正式测评端到端流程', 30, 36, '#111827');
    ttfLeft($im, $font, 12, '从主数据准备到分析应用的完整业务闭环', 30, 62, '#6B7280');

    $steps = [
        ['A', '主数据准备', "维护组织树\n建立班组\n不必预导人员\n绑定参评班组", '#DBEAFE', '#2563EB'],
        ['B', '标准准备', "发布指标版本\n维护题库权重\n配置等级阈值\n维护赋能措施", '#D1FAE5', '#059669'],
        ['C', '开测固化', "创建测评批次\n绑定参评班组\n开始批次抽题\n写入试卷快照", '#FEF3C7', '#D97706'],
        ['D', '扫码作答', "扫码进入批次\n选择单位\n填写姓名做题\n提交后同步入库", '#FFEDD5', '#EA580C'],
        ['E', '算分判级', "计算个人得分\n计算班组均分\n自动判定等级\n匹配薄弱措施", '#FCE7F3', '#DB2777'],
        ['F', '分析应用', "结果明细查询\n工作台总览\n分布/趋势/排行\n报表导出", '#E0E7FF', '#4F46E5'],
    ];

    $x = 40;
    foreach ($steps as $i => $s) {
        drawRoundRect($im, $x, 100, $x + 170, 340, 10, $s[3], $s[4]);
        // badge
        imagefilledellipse($im, $x + 28, 128, 34, 34, hexColor($im, $s[4]));
        ttfCenter($im, $font, 13, $s[0], $x + 28, 128, '#FFFFFF');
        ttfLeft($im, $font, 14, $s[1], $x + 52, 135, '#111827');
        $lines = explode("\n", $s[2]);
        $ly = 180;
        foreach ($lines as $line) {
            ttfLeft($im, $font, 12, '· ' . $line, $x + 18, $ly, '#374151');
            $ly += 32;
        }
        if ($i < count($steps) - 1) {
            arrowH($im, $x + 170, 220, $x + 190, $s[4]);
        }
        $x += 190;
    }

    // bottom swim roles
    drawRoundRect($im, 40, 380, 1140, 680, 12, '#FFFFFF', '#E5E7EB');
    ttfLeft($im, $font, 14, '角色分工示意', 60, 415, '#111827');

    box($im, $font, 60, 450, 320, 180, '', '#EFF6FF', '#93C5FD');
    ttfLeft($im, $font, 13, 'PC 管理员', 80, 480, '#1D4ED8');
    $pcLines = ['阶段 A / B / C：配置与开测', '阶段 E / F：算分、结果与看板', '可用「模拟做题」快速演示'];
    $ly = 515;
    foreach ($pcLines as $line) {
        ttfLeft($im, $font, 12, '· ' . $line, 80, $ly, '#1F2937');
        $ly += 28;
    }

    box($im, $font, 420, 450, 320, 180, '', '#FFF7ED', '#FDBA74');
    ttfLeft($im, $font, 13, '班组成员（小程序）', 440, 480, '#C2410C');
    $mLines = ['扫码→选单位→填姓名→做题', '提交后同步人员/答卷', '查看本人与本班结果建议'];
    $ly = 515;
    foreach ($mLines as $line) {
        ttfLeft($im, $font, 12, '· ' . $line, 440, $ly, '#1F2937');
        $ly += 28;
    }

    box($im, $font, 780, 450, 320, 180, '', '#ECFDF5', '#6EE7B7');
    ttfLeft($im, $font, 13, '系统自动完成', 800, 480, '#047857');
    $sLines = ['开测抽题并固化快照', '个人分 / 班组均分', '自动判级 + 措施匹配'];
    $ly = 515;
    foreach ($sLines as $line) {
        ttfLeft($im, $font, 12, '· ' . $line, 800, $ly, '#1F2937');
        $ly += 28;
    }

    savePng($im, $path);
}

/* ========== 图 4：计分判级赋能数据流 ========== */

function makeScore($font, $path)
{
    $im = canvas(1100, 420);
    ttfLeft($im, $font, 18, '图4  计分 · 判级 · 赋能数据流', 30, 36, '#111827');
    ttfLeft($im, $font, 12, '统一计分口径，支撑自动判级与精准措施推送', 30, 62, '#6B7280');

    $boxes = [
        [40, 120, 200, 70, "快照题目权重\n× 选项得分比例", '#DBEAFE', '#2563EB'],
        [300, 120, 180, 70, "小题得分\n汇总", '#D1FAE5', '#059669'],
        [540, 120, 180, 70, "个人总分", '#FEF3C7', '#D97706'],
        [780, 120, 240, 70, "班组总分\n= 已提交均分", '#FFEDD5', '#EA580C'],
        [300, 260, 220, 70, "落入等级区间\n自动判级", '#FCE7F3', '#DB2777'],
        [580, 260, 260, 70, "薄弱指标\n→ 赋能措施建议", '#E0E7FF', '#4F46E5'],
    ];
    foreach ($boxes as $b) {
        drawRoundRect($im, $b[0], $b[1], $b[0] + $b[2], $b[1] + $b[3], 10, $b[5], $b[6]);
        $lines = explode("\n", $b[4]);
        $startY = $b[1] + ($b[3] / 2) - ((count($lines) - 1) * 12);
        foreach ($lines as $i => $line) {
            ttfCenter($im, $font, 13, $line, $b[0] + $b[2] / 2, $startY + $i * 24, '#111827');
        }
    }
    arrowH($im, 240, 155, 300);
    arrowH($im, 480, 155, 540);
    arrowH($im, 720, 155, 780);
    arrowV($im, 900, 190, 295);
    imageline($im, 900, 295, 840, 295, hexColor($im, '#4B5563'));
    // from 班组 to 判级
    imageline($im, 900, 190, 900, 295, hexColor($im, '#4B5563'));
    imageline($im, 900, 295, 520, 295, hexColor($im, '#4B5563'));
    // arrow tip into 判级 right? boxes[4] is at 300-520. Come from right.
    imagefilledpolygon($im, [520, 295, 528, 290, 528, 300], 3, hexColor($im, '#4B5563'));
    arrowH($im, 520, 295, 580, '#4F46E5');

    ttfLeft($im, $font, 11, '建议区间（可配置）：被动 [0,70) · 依赖 [70,80) · 自立 [80,90] · 本质安全 (90,100]', 40, 380, '#6B7280');
    savePng($im, $path);
}

/* ========== 图 5：PC / 小程序职责边界 ========== */

function makeBoundary($font, $path)
{
    $im = canvas(1100, 480);
    ttfLeft($im, $font, 18, '图5  PC 与小程序职责边界', 30, 36, '#111827');
    ttfLeft($im, $font, 12, '配置与管理在 PC，现场测评在小程序，两端共用同一套计分与判级规则', 30, 62, '#6B7280');

    drawRoundRect($im, 40, 100, 500, 420, 12, '#EFF6FF', '#60A5FA');
    ttfCenter($im, $font, 16, 'PC 管理端（做）', 270, 135, '#1D4ED8');
    $left = [
        '组织 / 班组档案（必维）',
        '人员不必事先导入',
        '指标版本、题库、权重',
        '等级阈值与赋能措施',
        '创建批次、开测、结果',
        '工作台 / 看板 / 权限',
        '事后查看同步的人员答卷',
    ];
    $y = 175;
    foreach ($left as $t) {
        ttfLeft($im, $font, 13, '✓  ' . $t, 70, $y, '#1F2937');
        $y += 32;
    }

    drawRoundRect($im, 600, 100, 1060, 420, 12, '#FFF7ED', '#FB923C');
    ttfCenter($im, $font, 16, '微信小程序（做）', 830, 135, '#C2410C');
    $right = [
        '扫码进入指定批次',
        '选择单位（组织/班组）',
        '填写姓名开始做题',
        '提交后同步到数据库',
        '查看本人/本班结果',
        '查看薄弱项与建议',
    ];
    $y = 175;
    foreach ($right as $t) {
        ttfLeft($im, $font, 13, '✓  ' . $t, 630, $y, '#1F2937');
        $y += 32;
    }
    ttfLeft($im, $font, 12, '不做：组织配置 / 题库 / 批次 / 看板', 630, 390, '#9A3412');

    // center connector
    arrowH($im, 500, 260, 600, '#6B7280');
    ttfCenter($im, $font, 11, '同一规则', 550, 245, '#6B7280');

    savePng($im, $path);
}

/* ========== 图 6：正式测评时序图 ========== */

function seqArrow($im, $font, $x1, $x2, $y, $label, $color = '#2563EB', $dashed = false)
{
    $c = hexColor($im, $color);
    $gray = hexColor($im, '#9CA3AF');
    if ($dashed) {
        $dx = ($x2 > $x1) ? 8 : -8;
        for ($x = $x1; ($dx > 0 ? $x < $x2 : $x > $x2); $x += $dx) {
            imageline($im, (int) $x, $y, (int) ($x + $dx * 0.55), $y, $c);
        }
    } else {
        imageline($im, $x1, $y, $x2, $y, $c);
    }
    $dir = $x2 >= $x1 ? 1 : -1;
    imagefilledpolygon($im, [
        $x2, $y,
        $x2 - 9 * $dir, $y - 5,
        $x2 - 9 * $dir, $y + 5,
    ], 3, $c);
    // label above arrow
    $mid = (int) (($x1 + $x2) / 2);
    $bbox = imagettfbbox(11, 0, $font, $label);
    $tw = $bbox[2] - $bbox[0];
    $lx = (int) ($mid - $tw / 2);
    // light background for readability
    imagefilledrectangle($im, $lx - 4, $y - 20, $lx + $tw + 4, $y - 4, hexColor($im, '#F7F9FC'));
    imagettftext($im, 11, 0, $lx, $y - 7, hexColor($im, '#111827'), $font, $label);
}

function makeSequence($font, $path)
{
    $im = canvas(1180, 960);
    ttfLeft($im, $font, 18, '图6  正式测评时序图', 30, 36, '#111827');
    ttfLeft($im, $font, 12, '展示 PC 管理员、后台服务、数据库、小程序用户之间的交互顺序（人员提交后同步入库）', 30, 62, '#6B7280');

    // participants
    $actors = [
        ['PC管理员', 140, '#DBEAFE', '#2563EB'],
        ['后台服务', 420, '#D1FAE5', '#059669'],
        ['数据库', 700, '#FEF3C7', '#D97706'],
        ['小程序用户', 980, '#FFEDD5', '#EA580C'],
    ];
    $lifeTop = 130;
    $lifeBottom = 920;
    foreach ($actors as $a) {
        box($im, $font, $a[1] - 70, 80, 140, 42, $a[0], $a[2], $a[3], '#111827', 13);
        // lifeline
        $x = $a[1];
        imagesetstyle($im, [hexColor($im, '#9CA3AF'), hexColor($im, '#9CA3AF'), hexColor($im, '#9CA3AF'), IMG_COLOR_TRANSPARENT, IMG_COLOR_TRANSPARENT]);
        for ($y = $lifeTop; $y < $lifeBottom; $y += 10) {
            imageline($im, $x, $y, $x, min($y + 5, $lifeBottom), hexColor($im, '#9CA3AF'));
        }
        // bottom box
        box($im, $font, $a[1] - 70, $lifeBottom, 140, 30, $a[0], $a[2], $a[3], '#111827', 11);
    }

    $A = 140; // Admin
    $S = 420; // Sys
    $D = 700; // DB
    $U = 980; // User

    $msgs = [
        [$A, $S, '1 维护组织/班组（不必预导人员）', '#2563EB', false],
        [$S, $D, '2 写入组织班组档案', '#D97706', false],
        [$A, $S, '3 维护/发布指标版本与题库', '#2563EB', false],
        [$S, $D, '4 写入标准题库', '#D97706', false],
        [$A, $S, '5 配置等级阈值与赋能措施', '#2563EB', false],
        [$A, $S, '6 创建批次并绑定班组', '#2563EB', false],
        [$A, $S, '7 开始批次（触发抽题）', '#2563EB', false],
        [$S, $S, '8 每三级指标抽 1 题', '#059669', false],
        [$S, $D, '9 写入开测快照', '#D97706', false],
        [$U, $S, '10 扫码进入批次', '#EA580C', false],
        [$U, $S, '11 选择单位并填写姓名', '#EA580C', false],
        [$U, $S, '12 按快照答题并提交', '#EA580C', false],
        [$S, $D, '13 同步人员/答卷并锁定', '#D97706', false],
        [$A, $S, '14 触发/自动算分判级', '#2563EB', false],
        [$S, $D, '15 写个人分/班组结果/薄弱项', '#D97706', false],
        [$A, $S, '16 查看结果/看板', '#2563EB', false],
        [$U, $S, '17 查看本人与本班结果、建议', '#EA580C', false],
    ];

    $y = 155;
    $step = 42;
    foreach ($msgs as $m) {
        list($x1, $x2, $label, $color, $dashed) = $m;
        if ($x1 === $x2) {
            // self-call loop on 后台服务
            $c = hexColor($im, $color);
            imageline($im, $x1, $y, $x1 + 70, $y, $c);
            imageline($im, $x1 + 70, $y, $x1 + 70, $y + 18, $c);
            imageline($im, $x1 + 70, $y + 18, $x1, $y + 18, $c);
            imagefilledpolygon($im, [
                $x1, $y + 18,
                $x1 + 9, $y + 13,
                $x1 + 9, $y + 23,
            ], 3, $c);
            imagettftext($im, 11, 0, $x1 + 78, $y + 14, hexColor($im, '#111827'), $font, $label);
            $y += $step;
            continue;
        }
        seqArrow($im, $font, $x1, $x2, $y, $label, $color, $dashed);
        $y += $step;
    }

    savePng($im, $path);
}

/* ========== 生成全部图片 ========== */

$images = [
    '01-architecture.png' => 'makeArch',
    '02-modules.png' => 'makeModules',
    '03-flow.png' => 'makeFlow',
    '04-score.png' => 'makeScore',
    '05-boundary.png' => 'makeBoundary',
    '06-sequence.png' => 'makeSequence',
];
$imagePaths = [];
foreach ($images as $name => $fn) {
    $p = $mediaDir . DIRECTORY_SEPARATOR . $name;
    $fn($font, $p);
    $imagePaths[$name] = $p;
}

/* ========== Word 工具 ========== */

function e($s)
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function p($text, $style = 'Normal')
{
    $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="21"/><w:szCs w:val="21"/></w:rPr>';
    $pPr = '<w:pPr><w:spacing w:after="60" w:line="360" w:lineRule="auto"/></w:pPr>';
    if ($style === 'Title') {
        $pPr = '<w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="160"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="36"/><w:szCs w:val="36"/></w:rPr>';
    } elseif ($style === 'Subtitle') {
        $pPr = '<w:pPr><w:jc w:val="center"/><w:spacing w:after="200"/></w:pPr>';
        $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:color w:val="666666"/></w:rPr>';
    } elseif ($style === 'H1') {
        $pPr = '<w:pPr><w:spacing w:before="280" w:after="120"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr>';
    } elseif ($style === 'H2') {
        $pPr = '<w:pPr><w:spacing w:before="200" w:after="80"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr>';
    } elseif ($style === 'H3') {
        $pPr = '<w:pPr><w:spacing w:before="160" w:after="60"/></w:pPr>';
        $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>';
    } elseif ($style === 'Caption') {
        $pPr = '<w:pPr><w:jc w:val="center"/><w:spacing w:before="40" w:after="160"/></w:pPr>';
        $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="555555"/></w:rPr>';
    } elseif ($style === 'Note') {
        $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="666666"/></w:rPr>';
    } elseif ($style === 'Code') {
        $rPr = '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:eastAsia="宋体"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr>';
        $pPr = '<w:pPr><w:spacing w:after="20" w:line="276" w:lineRule="auto"/></w:pPr>';
    }
    return '<w:p>' . $pPr . '<w:r>' . $rPr . '<w:t xml:space="preserve">' . e($text) . '</w:t></w:r></w:p>';
}

function emptyP()
{
    return '<w:p><w:r><w:t></w:t></w:r></w:p>';
}

function tableN($rows, $header = true)
{
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/><w:tblBorders>'
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

/**
 * 嵌入图片。EMU: 914400 per inch. A4 content ~6.5 inch wide ≈ 5943600 EMU
 * @param int $rid relationship id
 * @param int $pxW image pixel width
 * @param int $pxH image pixel height
 * @param int $displayWEmu display width in EMU
 */
function drawing($rid, $pxW, $pxH, $displayWEmu = 5486400, $name = 'diagram')
{
    $displayHEmu = (int) ($displayWEmu * $pxH / $pxW);
    $cx = $displayWEmu;
    $cy = $displayHEmu;
    return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r>
      <w:drawing>
        <wp:inline distT="0" distB="0" distL="0" distR="0"
          xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
          xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
          xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
          <wp:extent cx="' . $cx . '" cy="' . $cy . '"/>
          <wp:docPr id="' . $rid . '" name="' . e($name) . '"/>
          <a:graphic>
            <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
              <pic:pic>
                <pic:nvPicPr>
                  <pic:cNvPr id="' . $rid . '" name="' . e($name) . '"/>
                  <pic:cNvPicPr/>
                </pic:nvPicPr>
                <pic:blipFill>
                  <a:blip r:embed="rId' . $rid . '"/>
                  <a:stretch><a:fillRect/></a:stretch>
                </pic:blipFill>
                <pic:spPr>
                  <a:xfrm>
                    <a:off x="0" y="0"/>
                    <a:ext cx="' . $cx . '" cy="' . $cy . '"/>
                  </a:xfrm>
                  <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                </pic:spPr>
              </pic:pic>
            </a:graphicData>
          </a:graphic>
        </wp:inline>
      </w:drawing>
    </w:r></w:p>';
}

function imgSize($path)
{
    $info = getimagesize($path);
    return [$info[0], $info[1]];
}

/* ========== 组装正文 ========== */

$body = '';
$body .= p('班组安全成熟度测评系统', 'Title');
$body .= p('功能与方案说明（客户演示用）', 'Title');
$body .= p('含方案设计、功能范围说明与关键逻辑关系图 · 不含开发进度', 'Subtitle');
$body .= emptyP();

$body .= p('1. 系统是什么', 'H1');
$body .= p('本系统以班组四级成熟度模型（被动型 → 依赖型 → 自立型 → 本质安全型）为标尺，把线下成熟度评估搬到线上，形成「测评 → 判级 → 赋能 → 分析」闭环。');
$body .= tableN([
    ['建设目标', '说明'],
    ['线上测评', '按标准题库统一作答、统一计分'],
    ['分级画像', '按班组总分自动判定成熟度等级，并展示阶段特征'],
    ['精准赋能', '针对薄弱指标推送建设措施与改进建议'],
    ['分析看板', '等级分布、成长趋势、排行等，支撑管理决策'],
]);

$body .= p('2. 端侧分工与职责边界', 'H1');
$body .= p('系统采用 PC 管理端为主、微信小程序为辅：配置与标准在 PC，现场测评在小程序，两端共用同一套计分与判级规则。');
list($w, $h) = imgSize($imagePaths['05-boundary.png']);
$body .= drawing(5, $w, $h, 5486400, '职责边界');
$body .= p('图5  PC 与小程序职责边界', 'Caption');

$body .= p('3. 总体方案设计', 'H1');
$body .= p('3.1 系统总体架构', 'H2');
list($w, $h) = imgSize($imagePaths['01-architecture.png']);
$body .= drawing(1, $w, $h, 5486400, '总体架构');
$body .= p('图1  系统总体架构', 'Caption');
$body .= tableN([
    ['层', '作用'],
    ['展示层', 'PC 管理后台页面；微信小程序页面'],
    ['应用层', '业务功能入口（组织、题库、批次、结果、看板等）'],
    ['领域服务', '抽题、个人算分、班组判级、导入与审计'],
    ['数据层', '独立业务库，组织/题库/批次/答卷/结果等数据隔离存储'],
]);

$body .= p('3.2 功能模块逻辑关系', 'H2');
$body .= p('各功能不是孤立菜单，而是按业务依赖串联。没有组织人员无法真实开测；没有题库无法抽题；没有阈值无法判级；没有措施则结果只能出分、无法精准帮扶。');
list($w, $h) = imgSize($imagePaths['02-modules.png']);
$body .= drawing(2, $w, $h, 5486400, '功能模块关系');
$body .= p('图2  功能模块逻辑关系', 'Caption');
$body .= tableN([
    ['模块', '输入依赖', '产出/服务对象', '关键关联'],
    ['组织档案', '—', '班组挂载点', '组织 → 班组'],
    ['班组管理', '组织', '批次绑定、成员容器', '班组 → 成员；批次 ↔ 班组'],
    ['成员管理', '班组', '答卷主体、小程序绑定', '成员 ↔ 微信用户；成员 → 答卷'],
    ['指标版本/题库', '—', '批次抽题来源', '版本 → 指标 → 题目 → 选项'],
    ['等级阈值', '—', '判级规则', '阈值 ← 班组结果等级'],
    ['赋能措施', '指标、等级', '结果页建议', '措施 → 三级指标'],
    ['测评批次', '版本、班组', '快照、答卷、结果', '批次是测评事务中心'],
    ['模拟做题', '版本、阈值、措施', '演示结果', '复用抽题/算分/判级'],
    ['结果/看板', '班组结果', '管理决策', '分布 / 趋势 / 排行'],
    ['系统管理', '—', '权限与审计', '角色 → 菜单；操作写日志'],
]);

$body .= p('3.3 正式测评端到端流程', 'H2');
list($w, $h) = imgSize($imagePaths['03-flow.png']);
$body .= drawing(3, $w, $h, 5486400, '端到端流程');
$body .= p('图3  正式测评端到端流程', 'Caption');
$body .= p('模拟做题说明：PC 选择指标版本即时抽题作答，按同口径出分/判级/看建议，不写入正式批次数据，适合联调与现场快速演示。');

$body .= p('3.4 正式测评时序图', 'H2');
$body .= p('下图按交互顺序展示四方协作：PC 管理员完成配置与开测，小程序成员答题提交，后台服务完成抽题、算分、判级，数据库持久化快照、答卷与结果。');
list($w, $h) = imgSize($imagePaths['06-sequence.png']);
$body .= drawing(6, $w, $h, 5486400, '正式测评时序图');
$body .= p('图6  正式测评时序图', 'Caption');

$body .= p('3.5 计分、判级与赋能', 'H2');
list($w, $h) = imgSize($imagePaths['04-score.png']);
$body .= drawing(4, $w, $h, 5486400, '计分判级赋能');
$body .= p('图4  计分 · 判级 · 赋能数据流', 'Caption');
$body .= tableN([
    ['等级', '区间约定（满分 100，可配置）'],
    ['被动型', '[0, 70)'],
    ['依赖型', '[70, 80)'],
    ['自立型', '[80, 90]'],
    ['本质安全型', '(90, 100]'],
]);

$body .= p('3.6 关键业务规则', 'H2');
$rules = [
    '答卷须归属到明确的班组与作答人；小程序侧身份确认方式待业务拍板（见第 6 节事项 G），可选手机号绑定档案，或扫码后选择组织/班组并填写姓名等轻量方式。',
    '若采用手机号绑定方案，建议手机号在成员档案中全局唯一。',
    '开测时每个三级指标抽取 1 道题进入快照；答题只认快照。',
    '答卷提交后锁定，不可再改。',
    '班组均分仅统计已提交成员；无人提交则无有效班组结果。',
    '组织层级不强制固定为 2 层或 3 层；班组可挂靠任意组织节点。',
    '已发布指标版本不宜直接改题；重大变更建议复制新版本，保证历史可比。',
];
foreach ($rules as $i => $r) {
    $body .= p(($i + 1) . '. ' . $r);
}

$body .= p('3.7 非功能设计要点', 'H2');
$body .= tableN([
    ['类别', '方案要点'],
    ['安全', 'PC 登录鉴权与角色菜单授权；小程序在身份确认后访问对应班组/本人结果数据'],
    ['一致性', '开测快照隔离题库后续修改；答卷提交后不可改'],
    ['审计', '关键操作可追溯；批量导入保留任务与失败明细'],
    ['扩展', '支持一指标多题与抽题策略；组织深度可变；措施可按等级扩展'],
]);

$body .= p('4. 系统功能说明', 'H1');
$body .= p('以下按业务模块说明系统具备的能力（共 50 项）。每项包含功能名称与能力说明，便于理解「能做什么、解决什么问题」。本说明不涉及开发进度。');

$sections = [
    '4.1 成熟度模型与判级' => [
        ['1. 班组四级成熟度模型', '系统内置并贯穿全流程使用四级成熟度标尺：被动型、依赖型、自立型、本质安全型。测评结果不是简单的「及格/不及格」，而是把班组定位到可理解的成长阶段，便于横向对比与纵向跟踪。'],
        ['2. 各等级分数区间可配置', '四级对应的分数上下限可在管理端维护（建议默认：被动＜70、依赖 70～80、自立 80～90、本质安全＞90）。企业可根据自身标准微调边界，无需改程序。'],
        ['3. 各等级阶段特征描述可配置与展示', '每个等级可配置阶段特征文案（如管理特点、风险表现、改进方向）。结果页、工作台等位置展示这些描述，帮助管理者快速读懂「这个等级意味着什么」。'],
        ['4. 按班组测评总分自动判定成熟度等级', '班组得分计算完成后，系统自动匹配分数区间并给出等级，减少人工判读差异，保证同一批次、同一标准下口径一致。'],
    ],
    '4.2 组织与人员档案（PC）' => [
        ['5. 组织架构管理（树形，层级不强制固定）', '支持树形维护企业组织（如公司—厂区—车间—工段等），不强制只能做 2 层或 3 层。可新增、编辑、调整上下级关系，作为班组挂靠与后续按组织分析的基础。'],
        ['6. 班组管理（班组挂靠所属组织）', '在组织节点下维护班组档案（名称、所属组织、状态等）。班组是参评单元：测评批次绑定班组、算分判级以班组为单位汇总。停用班组可限制其加入新批次。'],
        ['7. 成员管理（姓名、手机号、岗位、所属班组、角色、状态）', '维护班组成员基础信息。姓名、所属班组为基本字段；手机号可作为联系方式或可选绑定凭证（非强制唯一入口）。所属班组决定成绩归入哪个班组；状态可区分在岗/停用等。'],
        ['8. 成员角色区分', '预置班组长、组长、安全员、兼职安全员、普通成员等角色标签，便于识别参评岗位范围（例如是否允许普通成员参评，可由业务规则确认后配置执行）。'],
        ['9. 组织 / 班组 / 成员 Excel 模板下载与批量导入', '提供标准导入模板，支持批量导入组织、班组、成员，降低大量建档的手工录入成本，适合项目初始化或年度人员调整。'],
        ['10. 导入任务记录与失败明细查询', '每次导入形成任务记录，可查看成功/失败情况及失败原因明细，便于修正数据后再次导入，保证建档过程可追溯。'],
    ],
    '4.3 评价指标与题库（PC）' => [
        ['11. 指标版本管理（多套指标体系）', '支持维护多套指标版本（如 V1.0、年度修订版）。正式测评时指定使用哪一版本，历史批次仍按开测时版本口径解释，避免标准升级后「旧账新算」。'],
        ['12. 三级指标体系（含权重）', '按一级 / 二级 / 三级维护指标树，并为指标设置权重。一级权重合计建议为 100；三级指标权重直接参与题目计分，保证不同能力维度在总分中的贡献可控。'],
        ['13. 标准测评题库', '每道题包含题干、选项，并挂靠到具体三级指标。选项体现不同成熟度表现，使测评内容与指标体系一一对应，而不是零散问卷。'],
        ['14. 「指标权重 × 选项得分比例」计分', '统一计分口径：小题得分 = 该题对应三级指标权重 × 所选选项得分比例（如 1.0 / 0.75 / 0.5 / 0.25）。个人总分由小题得分累加得到，规则透明、可复核。'],
        ['15. 题库浏览与按指标树定位', '管理端可按指标树展开浏览题目与选项，便于审核题库完整性、核对某指标下是否有题、选项比例是否合理。'],
        ['16. 一指标多题 + 开测抽 1 题', '同一三级指标可挂多道备选题，形成题池；开测时每个三级指标抽取 1 道进入本批次试卷。既保证覆盖全部三级指标，又为后续轮换出题留扩展空间。'],
    ],
    '4.4 等级与精准赋能（PC）' => [
        ['17. 等级阈值维护', '维护各等级名称、最低分、最高分及阶段特征；保存时可做区间合理性校验（如避免重叠）。这是自动判级的规则来源。'],
        ['18. 赋能 / 建设措施维护', '维护针对薄弱点的建设措施文案（做什么、怎么改）。措施与指标体系关联，使测评结果能落到可执行改进动作，而不是只给一个分数。'],
        ['19. 每指标 × 四等级措施建议', '建议按「每个三级指标 × 四个成熟度等级」维护措施内容。同一薄弱点在不同等级下可给出差异化帮扶建议，支撑「精准赋能」。'],
        ['20. 测评后按薄弱指标推送措施', '答卷与算分完成后，系统识别未拿满分或失分较多的薄弱指标，自动匹配对应建设措施，在结果页向管理员与成员展示改进建议。'],
    ],
    '4.5 测评业务' => [
        ['21. 创建测评批次', '管理员创建一次正式测评事务：选择指标版本、设定测评周期/时间窗、填写批次名称等。批次是后续绑班组、抽题、答题、出结果的统一容器。'],
        ['22. 批次绑定参评班组', '将需要参加本次测评的班组加入批次。未绑定班组不参评；同一批次可覆盖多个班组，便于集中组织与横向对比。'],
        ['23. 批次状态管理（开始 / 进行中 / 结束）', '批次具备明确生命周期。开始后进入可答状态；结束后停止新答或收口统计。状态清晰，便于现场组织和进度管控。'],
        ['24. 开测抽题并固化试卷快照', '点击开始批次时，系统按规则抽题并生成「本批次试卷快照」。此后成员只答快照中的题目；即使后台再改题库，也不影响本次进行中的测评，保证公平与可追溯。'],
        ['25. 查看批次答题进度（已答 / 未答）', '管理端可查看各班组/成员答题进度，掌握谁已提交、谁未完成，便于催办与掌握收卷节奏。'],
        ['26. 个人作答与提交锁定', '成员逐题作答并提交；提交后答卷锁定，不可再改，避免事后改分争议，保证结果严肃性。'],
        ['27. 个人得分计算', '按统一公式自动计算每位已提交成员的个人总分，并保留小题得分明细，支持结果复核与薄弱点分析。'],
        ['28. 班组得分计算（已提交均分）', '班组总分 = 该班组已提交个人总分的平均值；未提交成员不计入。无人提交时不产生有效班组结果，避免「空数据假均分」。'],
        ['29. 班组自动判级', '根据班组总分自动匹配等级阈值，给出成熟度等级及阶段特征，减少人工判定主观差异。'],
        ['30. 结果查询（班组汇总 + 个人明细）', '管理端可查询本批次班组成绩汇总，并下钻查看个人答卷、选项与小题得分，满足「既看整体、也看个体」的管理需要。'],
        ['31. 薄弱指标清单 + 赋能建议', '结果中列出薄弱指标排序及对应建设措施，形成「哪里弱 → 怎么改」的闭环信息，支撑班组整改与帮扶。'],
        ['32. 结果导出', '支持将班组结果、排行等信息导出为 Excel 等格式，方便线下汇报、存档或二次分析。'],
        ['33. PC 端模拟做题', '无需创建正式批次即可选择指标版本抽题、作答、出分、判级并查看建议。用于培训演示、口径确认与联调验证；不写入正式批次数据，不影响真实测评台账。'],
    ],
    '4.6 小程序答题端' => [
        ['34. 微信登录', '成员使用微信身份进入小程序，降低现场账号密码门槛，适合班组现场快速开测。'],
        ['35. 成员身份确认（方式待定）', '进入测评前需确认「谁在答、归属哪个班组」。可选：A 手机号等与 PC 档案绑定；B（倾向）扫码后选择组织/班组并填写姓名即可作答。无论哪种方式，答卷须可归属到明确班组，支撑班组均分与结果追溯。'],
        ['36. 扫码进入指定测评批次', '扫描批次二维码直接进入对应测评，无需在复杂列表中查找，适合集中组织考试场景。'],
        ['37. 在线答题与提交', '按开测快照展示题目与选项，支持逐题作答并提交。提交流程与 PC 侧锁定规则一致。'],
        ['38. 查看本人得分与本班组成熟度结果', '提交并完成算分后，成员可查看个人得分，以及本班组总分、成熟度等级等结果，增强参与感与反馈及时性。'],
        ['39. 查看薄弱项与改进建议', '成员可查看本班/本人相关薄弱指标及改进建议，使测评结果直接转化为班组改进动作指引。'],
    ],
    '4.7 统计分析与工作台（PC）' => [
        ['40. 工作台总览 KPI', '首页集中展示关键管理指标：如班组总数、平均成熟得分、本质安全型班组数、被动型班组数等，帮助管理层「一眼看现状」。'],
        ['41. 成熟度等级分布图', '以环形图等方式展示各等级班组数量分布，直观呈现整体结构（高成熟度占比、低成熟度占比）。'],
        ['42. 等级明细清单', '列出各等级数量、占比及对应管理建议/阶段特征，把图表信息落到可阅读、可汇报的清单。'],
        ['43. 成熟度看板（按组织等维度）', '支持按组织等维度查看成熟度分布，便于发现薄弱单位、组织帮扶资源，而不只看全公司平均数。'],
        ['44. 班组成长趋势（跨批次）', '对比同一班组在不同测评批次中的得分变化，观察提升或回落，支撑持续改进与复盘。'],
        ['45. 班组排行榜', '按得分或等级对班组排序，形成可比激励视图，并可与导出能力结合用于通报或竞赛场景。'],
    ],
    '4.8 系统管理（PC）' => [
        ['46. 管理员账号管理', '维护后台管理员账号：新增、编辑资料、启用/停用、重置密码等，保障管理端人员可管可控。'],
        ['47. 角色管理', '定义不同管理角色（如系统管理员、业务管理员、只读查看等），为权限分配提供载体。'],
        ['48. 菜单 / 功能权限分配', '按角色授权可见菜单与可操作功能，实现职责分离：例如有人只维护档案、有人只看结果、有人可改题库。'],
        ['49. 操作日志查询', '记录关键操作痕迹，支持事后审计与问题追溯（谁在何时做了什么），提升系统合规与可管理性。'],
        ['50. PC 管理端登录与退出', '提供安全的管理端登录认证与退出能力，保障未授权人员无法进入配置与结果数据区域。'],
    ],
];
foreach ($sections as $title => $items) {
    $body .= p($title, 'H2');
    foreach ($items as $item) {
        $body .= p($item[0], 'H3');
        $body .= p($item[1]);
    }
}

$body .= p('5. 演示时建议强调的设计亮点', 'H1');
$points = [
    'PC 配置、小程序答题 — 职责清晰，现场操作轻、管理侧能力完整。',
    '批次快照机制 — 开测后题库调整不影响进行中的测评，保证公平与可追溯。',
    '个人分 → 班组均分 → 自动判级 → 措施推送 — 形成完整管理闭环。',
    '一指标 × 四等级措施 — 结果不只是分数，还能落到可执行的建设建议。',
    '模拟做题 — 无正式批次时也能完整演示计分、判级与赋能效果。',
];
foreach ($points as $i => $t) {
    $body .= p(($i + 1) . '. ' . $t);
}

$body .= p('6. 建议与客户当场确认的事项', 'H1');
$body .= tableN([
    ['#', '事项', '客户意见'],
    ['A', '四级分数边界是否按：被动＜70、依赖 70～80、自立 80～90、本质安全＞90', ''],
    ['B', '普通成员是否参与测评（或仅管理岗位参评）', ''],
    ['C', '小程序是否纳入本期范围（或先 PC 批次 + 模拟做题，小程序二期）', ''],
    ['D', '看板 / 排行 / 导出是否纳入本期范围', ''],
    ['E', '题库是否允许后期「一指标多题」随机抽题', ''],
    ['F', '赋能措施是否按「每指标 × 四等级」维护', ''],
    ['G', '小程序身份确认方式：手机号绑定档案，或扫码后选部门/班组并填姓名（可不强制手机号）', ''],
]);

$body .= p('7. 功能范围确认（可打印勾选）', 'H1');
$body .= p('请在确认纳入范围的模块前打勾：');
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
$body .= p('本文用于客户演示与功能/方案说明，不描述开发进度与内部实现状态。', 'Note');
$body .= p('客户确认人：____________　　日期：____________　　我方：____________', 'Note');

/* ========== 打包 docx ========== */

$ridMap = [
    1 => '01-architecture.png',
    2 => '02-modules.png',
    3 => '03-flow.png',
    4 => '04-score.png',
    5 => '05-boundary.png',
    6 => '06-sequence.png',
];

$relsItems = '';
foreach ($ridMap as $rid => $file) {
    $relsItems .= '<Relationship Id="rId' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $file . '"/>';
}

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
 xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
 xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
 xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
  <w:body>
    ' . $body . '
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/>
    </w:sectPr>
  </w:body>
</w:document>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="png" ContentType="image/png"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';

$wordRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
' . $relsItems . '
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
foreach ($ridMap as $rid => $file) {
    $zip->addFile($imagePaths[$file], 'word/media/' . $file);
}
$zip->close();
echo "OK: {$out}\n";

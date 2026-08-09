<?php
/**
 * 在用户已编辑的《客户演示-系统功能与方案说明.docx》上，
 * 仅修补「无需预导人员 / 扫码选单位填姓名 / 事后同步」相关表述，保留其余人工删改。
 *
 * 用法：php scripts/patch-demo-docx-scan-flow.php
 */

$root = dirname(__DIR__);
$docx = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . '客户演示-系统功能与方案说明.docx';
$work = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . '_docx_patch_work';
$mediaDir = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'demo-diagrams';

if (!is_file($docx)) {
    fwrite(STDERR, "missing docx\n");
    exit(1);
}

function rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

rrmdir($work);
mkdir($work, 0777, true);
$zipCopy = $work . DIRECTORY_SEPARATOR . 'src.zip';
copy($docx, $zipCopy);

$zip = new ZipArchive();
if ($zip->open($zipCopy) !== true) {
    fwrite(STDERR, "open zip fail\n");
    exit(2);
}
$unz = $work . DIRECTORY_SEPARATOR . 'unz';
$zip->extractTo($unz);
$zip->close();

$xmlPath = $unz . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml';
$xml = file_get_contents($xmlPath);

$replacements = [
    // 3.2 模块串联说明
    '各功能不是孤立菜单，而是按业务依赖串联。没有组织人员无法真实开测；没有题库无法抽题；没有阈值无法判级；没有措施则结果只能出分、无法精准帮扶。'
        => '各功能不是孤立菜单，而是按业务依赖串联。开测前需维护组织/班组与题库标准；人员不必事先导入——成员扫码后选择单位并填写姓名即可作答，提交后再同步入库。没有题库无法抽题；没有阈值无法判级；没有措施则结果只能出分、无法精准帮扶。',

    // 模块表：成员相关
    '答卷主体、小程序绑定'
        => '现场作答后归集同步',
    '成员 ↔ 微信用户；成员 → 答卷'
        => '扫码选单位填姓名 → 提交后同步成员/答卷',

    // 3.4 时序说明
    '下图按交互顺序展示四方协作：PC 管理员完成配置与开测，小程序成员答题提交，后台服务完成抽题、算分、判级，数据库持久化快照、答卷与结果。'
        => '下图按交互顺序展示四方协作：PC 管理员维护组织/班组与标准并开测；小程序用户扫码后选择单位、填写姓名并答题提交；后台完成抽题、算分、判级；人员与答卷数据在提交后同步入库。',

    // 业务规则 1（保留你已改的轻量方向，补完整口径）
    '答卷须归属到明确的班组与作答人扫码后选择组织/班组并填写姓名等轻量方式。'
        => '不必事先导入人员。用户扫码进入批次后，选择单位（组织/班组）并填写姓名即可开始做题；提交后将作答人与答卷数据同步到数据库，保证答卷归属明确班组，支撑班组均分与结果追溯。',

    // 功能 7：成员管理（保留你删掉手机号等细节后的条目，改成事后同步口径）
    '7. 成员管理（）'
        => '7. 成员管理（事后同步，非开测前置）',
    '维护班组成员基础信息.所属班组决定成绩归入哪个班组；状态可区分在岗/停用等。'
        => '正式测评不要求事先导入人员名单。成员在小程序扫码作答时选择单位并填写姓名；提交后系统将人员与答卷同步入库，所属班组决定成绩归入哪个班组。PC 端可事后查看、补全或维护成员信息。',

    // 功能 9：导入（你已删掉 Excel 模板条目，保留导入任务；补充说明导入非必须）
    '每次导入形成任务记录，可查看成功/失败情况及失败原因明细，便于修正数据后再次导入，保证建档过程可追溯。'
        => '如需批量维护组织/班组等基础数据，导入任务可记录成功/失败及失败明细。人员名单不是开测前置条件；测评人员以扫码现场填写并事后同步为主。',

    // 功能 35：定为现行逻辑
    '35. 成员身份确认（方式待定）'
        => '35. 扫码选单位并填写姓名（无需预导人员）',
    '进入测评前需确认「谁在答、归属哪个班组」。扫码后选择组织/班组并填写姓名即可作答。，答卷须归属到明确班组，支撑班组均分与结果追溯。'
        => '现行逻辑：不事先导入人员。用户扫码进入指定测评批次后，选择单位（组织/班组）、输入姓名，即可开始做题；提交后将人员与答卷数据同步到数据库，保证答卷归属明确班组，支撑班组均分与结果追溯。',

    // 功能 36：与 35 衔接
    '扫描批次二维码直接进入对应测评，无需在复杂列表中查找，适合集中组织考试场景。'
        => '扫描批次二维码直接进入对应测评；进入后选择单位并填写姓名即可作答。无需事先导人员、也无需在复杂列表中查找批次，适合集中组织考试场景。',

    // 功能 25/26 附近：个人作答描述（你已删进度项，编号为 25）
    '成员逐题作答并提交；提交后答卷锁定，不可再改，避免事后改分争议，保证结果严肃性。'
        => '用户现场填写身份信息后逐题作答并提交；提交后答卷锁定不可再改，同时将作答人与答卷同步入库，避免事后改分争议，保证结果严肃性。',
];

$miss = [];
foreach ($replacements as $from => $to) {
    if (strpos($xml, $from) === false) {
        $miss[] = $from;
        continue;
    }
    $xml = str_replace($from, $to, $xml);
    echo "OK replace: " . mb_substr($from, 0, 24) . "...\n";
}

if ($miss) {
    echo "MISS count=" . count($miss) . "\n";
    foreach ($miss as $m) {
        echo "MISS: " . mb_substr($m, 0, 40) . "...\n";
    }
}

file_put_contents($xmlPath, $xml);

// 若 demo-diagrams 下已有更新后的图，覆盖 docx 内嵌 media
$mediaMap = [
    '03-flow.png',
    '05-boundary.png',
    '06-sequence.png',
    '02-modules.png',
];
$mediaWord = $unz . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'media';
foreach ($mediaMap as $name) {
    $src = $mediaDir . DIRECTORY_SEPARATOR . $name;
    $dst = $mediaWord . DIRECTORY_SEPARATOR . $name;
    if (is_file($src) && is_file($dst)) {
        copy($src, $dst);
        echo "MEDIA: {$name}\n";
    }
}

$outTmp = $work . DIRECTORY_SEPARATOR . 'out.docx';
if (is_file($outTmp)) {
    unlink($outTmp);
}
$zipOut = new ZipArchive();
if ($zipOut->open($outTmp, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "create out fail\n");
    exit(3);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($unz, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $full = $file->getPathname();
    $local = substr($full, strlen($unz) + 1);
    $local = str_replace('\\', '/', $local);
    $zipOut->addFile($full, $local);
}
$zipOut->close();

// copy over target (fallback alt name if locked)
$targets = [$docx, $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . '客户演示-系统功能与方案说明-已更新扫码流程.docx'];
$written = null;
foreach ($targets as $t) {
    if (@copy($outTmp, $t)) {
        $written = $t;
        echo "WRITTEN: {$t}\n";
        break;
    }
    echo "LOCK/FAIL: {$t}\n";
}
if (!$written) {
    exit(4);
}

echo "DONE\n";
if ($miss) {
    exit(5);
}

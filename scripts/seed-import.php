<?php
/**
 * 生成样例 Excel，并直接调用导入服务写入 team_maturity
 * 用法：
 *   D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe scripts\seed-import.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);
$backend = $projectRoot . DIRECTORY_SEPARATOR . 'backend';

// 先生成 Excel
require $projectRoot . '/scripts/make-sample-import.php';

// 引导 ThinkPHP（仅加载框架与配置，便于使用 Db / Service）
define('APP_PATH', $backend . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $backend . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
require $backend . '/thinkphp/base.php';

// 注册应用命名空间 + 公共函数
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
// 加载数据库配置
\think\Config::set(include APP_PATH . 'database.php', 'database');

use app\index\service\ExcelHelper;
use app\index\service\ImportService;

$sampleDir = $projectRoot . '/scripts/sample-import';
$files = [
    'org'    => $sampleDir . '/01-org.xls',
    'team'   => $sampleDir . '/02-team.xls',
    'member' => $sampleDir . '/03-member.xls',
];

foreach ($files as $type => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "缺少文件: $path\n");
        exit(1);
    }
    list(, $rows) = ExcelHelper::readAssoc($path);
    echo "==> 导入 {$type}，行数=" . count($rows) . "\n";
    if ($type === 'org') {
        $result = ImportService::importOrgs($rows, 1);
    } elseif ($type === 'team') {
        $result = ImportService::importTeams($rows, 1);
    } else {
        $result = ImportService::importMembers($rows, 1);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    if ($result['fail_cnt'] > 0 && $result['success_cnt'] === 0) {
        fwrite(STDERR, "导入失败: {$type}\n");
        exit(2);
    }
}

$orgCnt = \think\Db::name('tm_org')->whereNull('deleted_at')->count();
$teamCnt = \think\Db::name('tm_team')->whereNull('deleted_at')->count();
$memberCnt = \think\Db::name('tm_member')->whereNull('deleted_at')->count();
echo "校验：组织={$orgCnt} 班组={$teamCnt} 成员={$memberCnt}\n";
echo "ALL_DONE\n";

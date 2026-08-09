<?php
// 仅连接本机 team_maturity；密码等敏感项优先读环境变量，未设置时用本地默认值
$dbPass = getenv('TM_DB_PASSWORD');
if ($dbPass === false || $dbPass === '') {
    $dbPass = '123456';
}
$dbHost = getenv('TM_DB_HOST');
if ($dbHost === false || $dbHost === '') {
    $dbHost = '127.0.0.1';
}
$dbName = getenv('TM_DB_DATABASE');
if ($dbName === false || $dbName === '') {
    $dbName = 'team_maturity';
}
$dbUser = getenv('TM_DB_USERNAME');
if ($dbUser === false || $dbUser === '') {
    $dbUser = 'root';
}
$appDebugEnv = getenv('TM_APP_DEBUG');
$dbDebug = ($appDebugEnv === false || $appDebugEnv === '')
    ? true
    : filter_var($appDebugEnv, FILTER_VALIDATE_BOOLEAN);

return [
    'type'            => 'mysql',
    'hostname'        => $dbHost,
    'database'        => $dbName,
    'username'        => $dbUser,
    'password'        => $dbPass,
    'hostport'        => '3306',
    'dsn'             => '',
    'params'          => [],
    'charset'         => 'utf8mb4',
    'prefix'          => '',
    'debug'           => $dbDebug,
    'deploy'          => 0,
    'rw_separate'     => false,
    'master_num'      => 1,
    'slave_no'        => '',
    'read_master'     => false,
    'fields_strict'   => true,
    'resultset_type'  => 'array',
    'auto_timestamp'  => false,
    'datetime_format' => 'Y-m-d H:i:s',
    'sql_explain'     => false,
];

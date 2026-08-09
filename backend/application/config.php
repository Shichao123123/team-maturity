<?php

// 敏感项优先环境变量：TM_APP_DEBUG / TM_JWT_SECRET / TM_COOKIE_SECURE
$tmAppDebugEnv = getenv('TM_APP_DEBUG');
$tmAppDebug = ($tmAppDebugEnv === false || $tmAppDebugEnv === '')
    ? true
    : filter_var($tmAppDebugEnv, FILTER_VALIDATE_BOOLEAN);
$tmJwtSecret = getenv('TM_JWT_SECRET');
if ($tmJwtSecret === false || $tmJwtSecret === '') {
    $tmJwtSecret = 'tm_mini_dev_secret_change_me';
}
$tmCookieSecureEnv = getenv('TM_COOKIE_SECURE');
if ($tmCookieSecureEnv !== false && $tmCookieSecureEnv !== '') {
    $tmCookieSecure = filter_var($tmCookieSecureEnv, FILTER_VALIDATE_BOOLEAN);
} else {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $https = $https || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $https = $https || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $tmCookieSecure = $https;
}
// 本地私密覆盖（不入库）：application/config.local.php
// 也可直接设置环境变量 TM_WX_APPID / TM_WX_SECRET
$tmLocal = [];
$tmLocalFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
if (is_file($tmLocalFile)) {
    $loaded = include $tmLocalFile;
    if (is_array($loaded)) {
        $tmLocal = $loaded;
    }
}
// 小程序 AppID 可公开；Secret 只用环境变量或 config.local.php
$tmWxAppid = getenv('TM_WX_APPID');
if ($tmWxAppid === false || $tmWxAppid === '') {
    $tmWxAppid = isset($tmLocal['wx_appid']) ? (string)$tmLocal['wx_appid'] : 'wxc23577d86aaf957c';
}
$tmWxSecret = getenv('TM_WX_SECRET');
if ($tmWxSecret === false || $tmWxSecret === '') {
    $tmWxSecret = isset($tmLocal['wx_secret']) ? (string)$tmLocal['wx_secret'] : '';
}
// 本地联调开关：config.local.php 的 dev_login，或环境变量 TM_WX_DEV_LOGIN
$tmWxDevLoginEnv = getenv('TM_WX_DEV_LOGIN');
if ($tmWxDevLoginEnv !== false && $tmWxDevLoginEnv !== '') {
    $tmWxDevLogin = filter_var($tmWxDevLoginEnv, FILTER_VALIDATE_BOOLEAN);
} elseif (array_key_exists('dev_login', $tmLocal)) {
    $tmWxDevLogin = (bool)$tmLocal['dev_login'];
} else {
    $tmWxDevLogin = false;
}

return [
    'app_debug'              => $tmAppDebug,
    'app_trace'              => false,
    'app_status'             => '',
    'app_multi_module'       => true,
    'auto_bind_module'       => true,
    'root_namespace'         => [],
    'extra_file_list'        => [THINK_PATH . 'helper' . EXT],
    'default_return_type'    => 'html',
    'default_ajax_return'    => 'json',
    'default_jsonp_handler'  => 'jsonpReturn',
    'var_jsonp_handler'      => 'callback',
    'default_timezone'       => 'PRC',
    'lang_switch_on'         => false,
    'default_filter'         => '',
    'default_lang'           => 'zh-cn',
    'class_suffix'           => false,
    'controller_suffix'      => false,

    'default_module'         => 'index',
    'deny_module_list'       => ['common'],
    'default_controller'     => 'Login',
    'default_action'         => 'index',
    'default_validate'       => '',
    'empty_controller'       => 'Error',
    'action_suffix'          => '',

    'var_pathinfo'           => 's',
    'pathinfo_fetch'         => ['ORIG_PATH_INFO', 'REDIRECT_PATH_INFO', 'REDIRECT_URL'],
    'pathinfo_depr'          => '/',
    'url_html_suffix'        => 'html',
    'url_common_param'       => false,
    'url_param_type'         => 0,
    'url_route_on'           => true,
    'route_complete_match'   => false,
    'route_config_file'      => ['route'],
    'url_route_must'         => false,
    'url_domain_deploy'      => false,
    'url_domain_root'        => '',
    // 关闭自动转换，保留 TmOrg / TmTeam 等驼峰控制器名
    'url_convert'            => false,
    'url_controller_layer'   => 'controller',
    'var_method'             => '_method',
    'var_ajax'               => '_ajax',
    'var_pjax'               => '_pjax',
    'request_cache'          => false,
    'request_cache_expire'   => null,
    'request_cache_except'   => [],

    'template'               => [
        'type'         => 'Think',
        'auto_rule'    => 1,
        'view_path'    => '',
        'view_suffix'  => 'html',
        'view_depr'    => DS,
        'tpl_begin'    => '{',
        'tpl_end'      => '}',
        'taglib_begin' => '{',
        'taglib_end'   => '}',
    ],

    'view_replace_str'       => [],
    'dispatch_success_tmpl'  => THINK_PATH . 'tpl' . DS . 'dispatch_jump.tpl',
    'dispatch_error_tmpl'    => THINK_PATH . 'tpl' . DS . 'dispatch_jump.tpl',
    'exception_tmpl'         => THINK_PATH . 'tpl' . DS . 'think_exception.tpl',
    'error_message'          => '页面错误！请稍后再试～',
    'show_error_msg'         => true,
    'exception_handle'       => '',

    'log' => [
        'type'  => 'File',
        'path'  => LOG_PATH,
        'level' => [],
    ],

    'cache' => [
        'type'   => 'File',
        'path'   => CACHE_PATH,
        'prefix' => 'tm_',
        'expire' => 0,
    ],

    'cookie' => [
        'prefix'    => 'tm_',
        'expire'    => 0,
        'path'      => '/',
        'domain'    => '',
        // HTTPS 下自动 true；可用 TM_COOKIE_SECURE=0/1 强制
        'secure'    => $tmCookieSecure,
        'httponly'  => true,
        'setcookie' => true,
    ],

    // 本地开发用文件 Session，避免依赖 Redis
    'session' => [
        'id'             => '',
        'var_session_id' => '',
        'prefix'         => 'tm_admin',
        'type'           => '',
        'auto_start'     => true,
        'expire'         => 3600 * 24 * 7,
    ],

    'paginate' => [
        'type'      => 'bootstrap',
        'var_page'  => 'page',
        'list_rows' => 15,
    ],

    'productname' => '班组安全成熟度测评系统',
    'redirecturl' => '/Login',
    'version'     => 'v0.1.0_dev',

    // 小程序：扫码自助登记（选班组+姓名入库），不依赖预导人员
    'mini' => [
        // 上线务必设置环境变量 TM_JWT_SECRET
        'jwt_secret' => $tmJwtSecret,
        'jwt_ttl'    => 604800,
        'wx_appid'   => $tmWxAppid,
        'wx_secret'  => $tmWxSecret,
        // 生产必须为 false；本地联调在 config.local.php 设 dev_login=true
        'dev_login'  => $tmWxDevLogin,
        // PC 批次二维码内容前缀（小程序页面路径）；扫码后带 batch_id
        'qr_path'    => 'pages/exam/index',
        // 正式小程序码环境：develop / trial / release；空则按 dev_login 推断
        'wxacode_env' => isset($tmLocal['wxacode_env']) ? (string)$tmLocal['wxacode_env'] : '',
    ],
];

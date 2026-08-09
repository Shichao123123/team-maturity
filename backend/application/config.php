<?php

return [
    'app_debug'              => true,
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
        'secure'    => false,
        'httponly'  => '',
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
        'jwt_secret' => 'tm_mini_dev_secret_change_me',
        'jwt_ttl'    => 604800,
        'wx_appid'   => '',
        'wx_secret'  => '',
        // true：未配置微信时，用 code 模拟 open_id（本地联调）
        'dev_login'  => true,
    ],
];

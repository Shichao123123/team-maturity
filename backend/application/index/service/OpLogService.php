<?php
namespace app\index\service;

use think\Db;
use think\Request;
use think\Session;

class OpLogService
{
    public static function write($module, $action, $bizId = null, $detail = null, $adminId = null)
    {
        if ($adminId === null) {
            $user = Session::get('tm_user');
            $adminId = $user && isset($user['id']) ? (int)$user['id'] : null;
        }
        if (is_array($detail) || is_object($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        $ip = '';
        try {
            $ip = Request::instance()->ip();
        } catch (\Exception $e) {
            $ip = '';
        }
        return Db::name('tm_op_log')->insertGetId([
            'admin_id'   => $adminId,
            'module'     => (string)$module,
            'action'     => (string)$action,
            'biz_id'     => $bizId === null || $bizId === '' ? null : (string)$bizId,
            'detail'     => $detail,
            'ip'         => $ip,
            'created_at' => tmNow(),
        ]);
    }
}

<?php
namespace app\index\service;

use think\Db;
use think\Request;
use think\Session;

/**
 * 管理端操作日志写入。
 */
class OpLogService
{
    /**
     * 写入一条操作日志到 tm_op_log。
     *
     * @param string      $module  业务模块标识
     * @param string      $action  操作动作标识
     * @param mixed       $bizId   关联业务 ID（可为 null）
     * @param mixed       $detail  详情；数组/对象会自动 JSON 编码
     * @param int|null    $adminId 管理员 ID；null 时从 Session tm_user 读取
     * @return int 新插入日志记录的 ID
     */
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

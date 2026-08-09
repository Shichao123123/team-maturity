<?php
namespace app\index\controller;

use think\Controller;
use think\Session;

/**
 * 后台控制器基类，统一会话校验与登录跳转。
 */
class Base extends Controller
{
    /** @var array|null 当前登录管理员信息 */
    protected $currentAdmin;

    /**
     * 控制器初始化：校验会话，未登录时跳转或返回 401。
     *
     * @return void
     */
    public function _initialize()
    {
        if ($this->checkSession()) {
            $this->currentAdmin = Session::get('tm_user');
            $this->assign('currentAdmin', $this->currentAdmin);
            return;
        }

        $url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (stripos($url, 'ajax') !== false || $this->request->isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(DefaultResponse(401, false, '会话超时，请重新登录'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $this->redirect(config('redirecturl'));
    }

    /**
     * 检查 tm_user 会话是否存在。
     *
     * @return bool 已登录返回 true
     */
    private function checkSession()
    {
        return !empty(Session::get('tm_user'));
    }
}

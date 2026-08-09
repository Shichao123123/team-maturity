<?php
namespace app\index\controller;

use think\Controller;
use think\Session;

class Base extends Controller
{
    protected $currentAdmin;

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

    private function checkSession()
    {
        return !empty(Session::get('tm_user'));
    }
}

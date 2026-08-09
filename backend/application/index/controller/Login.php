<?php
namespace app\index\controller;

use app\index\model\AdminModel;
use app\index\service\OpLogService;
use think\Controller;
use think\Request;
use think\Session;

class Login extends Controller
{
    public function index()
    {
        if (Session::get('tm_user')) {
            $this->redirect('/Index/index');
        }
        return $this->fetch('login/index');
    }

    public function login()
    {
        $request = Request::instance();
        if (!$request->isPost()) {
            return json(DefaultResponse(405, false, '请使用 POST 登录'));
        }

        $username = $request->param('username', '', 'trim');
        $password = $request->param('password', '', 'trim');
        // 兼容旧壳字段名
        if ($username === '') {
            $username = $request->param('name', '', 'trim');
        }
        if ($password === '') {
            $password = $request->param('pwsd', '', 'trim');
        }

        if ($username === '' || $password === '') {
            if ($request->isAjax()) {
                return json(DefaultResponse(400, false, '请输入账号和密码'));
            }
            $this->error('请输入账号和密码', '/Login');
        }

        $model = new AdminModel();
        $admin = $model->getByUsername($username);
        if (empty($admin)) {
            if ($request->isAjax()) {
                return json(DefaultResponse(404, false, '账号不存在'));
            }
            $this->error('账号不存在', '/Login');
        }

        if ((int)$admin['status'] !== 1) {
            if ($request->isAjax()) {
                return json(DefaultResponse(403, false, '账号已停用'));
            }
            $this->error('账号已停用', '/Login');
        }

        if (!password_verify($password, $admin['password_hash'])) {
            if ($request->isAjax()) {
                return json(DefaultResponse(401, false, '密码错误'));
            }
            $this->error('密码错误', '/Login');
        }

        unset($admin['password_hash']);
        Session::set('tm_user', $admin);
        $model->touchLogin($admin['id']);
        OpLogService::write('login', 'login', $admin['id'], ['username' => $admin['username']], $admin['id']);

        if ($request->isAjax()) {
            return json(DefaultResponse(0, true, '登录成功', ['redirect' => '/Index/index']));
        }
        $this->success('登录成功', '/Index/index');
    }

    public function logout()
    {
        $user = Session::get('tm_user');
        if ($user && isset($user['id'])) {
            OpLogService::write('login', 'logout', $user['id'], ['username' => isset($user['username']) ? $user['username'] : ''], $user['id']);
        }
        Session::clear();
        $this->redirect('/Login');
    }
}

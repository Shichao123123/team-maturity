<?php
namespace app\index\controller;

use app\index\model\AdminModel;
use app\index\service\OpLogService;
use think\Cache;
use think\Controller;
use think\Request;
use think\Session;

/**
 * 后台登录控制器（账号密码登录、登出）。
 */
class Login extends Controller
{
    /** 同一账号+IP 连续失败上限 */
    const FAIL_MAX = 5;
    /** 锁定秒数 */
    const FAIL_LOCK_SECONDS = 900;

    /**
     * 登录页；已登录则跳转首页。
     *
     * @return mixed 渲染 login/index 视图或重定向
     */
    public function index()
    {
        if (Session::get('tm_user')) {
            $this->redirect('/Index/index');
        }
        $this->assign('loginError', Session::get('login_error') ?: '');
        return $this->fetch('login/index');
    }

    /**
     * 登录提交 API（支持 Ajax 与表单，兼容旧字段名）。
     *
     * @return \think\response\Json|void 成功返回 redirect 地址，失败返回错误信息
     */
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
            return $this->loginFailResponse($request, '请输入账号和密码', 400);
        }

        $lockKey = $this->loginFailKey($username, $request->ip());
        $failInfo = Cache::get($lockKey);
        if (is_array($failInfo) && isset($failInfo['count']) && (int)$failInfo['count'] >= self::FAIL_MAX) {
            $remain = isset($failInfo['locked_until']) ? ((int)$failInfo['locked_until'] - time()) : self::FAIL_LOCK_SECONDS;
            if ($remain > 0) {
                $mins = (int)ceil($remain / 60);
                return $this->loginFailResponse($request, '登录失败次数过多，请 ' . $mins . ' 分钟后再试', 429);
            }
            Cache::rm($lockKey);
        }

        $model = new AdminModel();
        $admin = $model->getByUsername($username);
        if (empty($admin)) {
            $this->recordLoginFail($lockKey);
            return $this->loginFailResponse($request, '账号或密码错误', 401);
        }

        if (!password_verify($password, $admin['password_hash'])) {
            $this->recordLoginFail($lockKey);
            return $this->loginFailResponse($request, '账号或密码错误', 401);
        }

        // 停用与错误密码统一文案，避免暴露账号是否存在/已停用
        if ((int)$admin['status'] !== 1) {
            $this->recordLoginFail($lockKey);
            return $this->loginFailResponse($request, '账号或密码错误', 401);
        }

        Cache::rm($lockKey);
        unset($admin['password_hash']);
        Session::set('tm_user', $admin);
        $model->touchLogin($admin['id']);
        OpLogService::write('login', 'login', $admin['id'], ['username' => $admin['username']], $admin['id']);

        if ($request->isAjax()) {
            return json(DefaultResponse(0, true, '登录成功', ['redirect' => '/Index/index']));
        }
        // 直接进入主界面，不展示 ThinkPHP 默认「登录成功」跳转页
        $this->redirect('/Index/index');
    }

    /**
     * 登出：清除会话并跳转登录页。
     *
     * @return void 重定向至 /Login
     */
    public function logout()
    {
        $user = Session::get('tm_user');
        if ($user && isset($user['id'])) {
            OpLogService::write('login', 'logout', $user['id'], ['username' => isset($user['username']) ? $user['username'] : ''], $user['id']);
        }
        Session::clear();
        $this->redirect('/Login');
    }

    /**
     * 登录失败缓存键（账号 + IP）。
     *
     * @param string $username
     * @param string $ip
     * @return string
     */
    private function loginFailKey($username, $ip)
    {
        return 'login_fail_' . md5(strtolower($username) . '|' . $ip);
    }

    /**
     * 记录一次登录失败，达到上限则锁定。
     *
     * @param string $lockKey
     * @return void
     */
    private function recordLoginFail($lockKey)
    {
        $info = Cache::get($lockKey);
        $count = (is_array($info) && isset($info['count'])) ? ((int)$info['count'] + 1) : 1;
        $data = [
            'count'        => $count,
            'locked_until' => $count >= self::FAIL_MAX ? (time() + self::FAIL_LOCK_SECONDS) : 0,
        ];
        Cache::set($lockKey, $data, self::FAIL_LOCK_SECONDS);
    }

    /**
     * 统一登录失败响应（Ajax JSON / 表单跳转）。
     *
     * @param Request $request
     * @param string  $msg
     * @param int     $code
     * @return \think\response\Json|void
     */
    private function loginFailResponse(Request $request, $msg, $code)
    {
        if ($request->isAjax()) {
            return json(DefaultResponse($code, false, $msg));
        }
        // 失败也直接回登录页，避免中间跳转页
        Session::flash('login_error', $msg);
        $this->redirect('/Login');
    }
}

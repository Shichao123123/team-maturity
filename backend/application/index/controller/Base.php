<?php
namespace app\index\controller;

use app\index\model\PowerModel;
use think\Controller;
use think\Session;

/**
 * 后台控制器基类，统一会话校验、登录跳转与菜单级接口鉴权。
 */
class Base extends Controller
{
    /** @var array|null 当前登录管理员信息 */
    protected $currentAdmin;

    /**
     * 无需菜单权限的控制器/方法（框架页、工作台、登出等）。
     * 键为控制器短名，值为 ['*'] 或 action 列表。
     *
     * @var array
     */
    protected $authExcept = [
        'Index' => ['*'],
    ];

    /**
     * 多菜单入口控制器：action => 所需菜单路径。
     * 未列出的 action 默认拒绝（避免漏配越权）。
     *
     * @var array
     */
    protected $actionPowerMap = [
        'TmDashboard' => [
            'index'      => '/TmDashboard/index',
            'leveldist'  => '/TmDashboard/index',
            'trend'      => '/TmDashboard/trend',
            'trenddata'  => '/TmDashboard/trend',
            'rank'       => '/TmDashboard/rank',
            'rankdata'   => '/TmDashboard/rank',
            'exportrank' => '/TmDashboard/rank',
        ],
    ];

    /**
     * 控制器初始化：校验会话，再按角色菜单权限拦截接口。
     *
     * @return void
     */
    public function _initialize()
    {
        if ($this->checkSession()) {
            $this->currentAdmin = Session::get('tm_user');
            $this->assign('currentAdmin', $this->currentAdmin);
            if (!$this->checkPower()) {
                $this->denyPower();
            }
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

    /**
     * 按当前 controller/action 对照角色菜单权限（与侧栏同源）。
     *
     * @return bool
     */
    protected function checkPower()
    {
        $controller = $this->request->controller();
        // action 统一小写，避免 exportRank / exportrank 映射不一致
        $action = strtolower($this->request->action());

        if ($this->isAuthExcept($controller, $action)) {
            return true;
        }

        $roleId = isset($this->currentAdmin['role_id']) ? (int)$this->currentAdmin['role_id'] : 0;
        if ($roleId <= 0) {
            return false;
        }

        $path = $this->resolveRequiredPath($controller, $action);
        if ($path === '') {
            return false;
        }

        $model = new PowerModel();
        return $model->roleCanAccessPath($roleId, $path);
    }

    /**
     * 是否在免鉴权白名单内。
     *
     * @param string $controller
     * @param string $action
     * @return bool
     */
    protected function isAuthExcept($controller, $action)
    {
        if (!isset($this->authExcept[$controller])) {
            return false;
        }
        $list = $this->authExcept[$controller];
        return in_array('*', $list, true) || in_array($action, $list, true);
    }

    /**
     * 解析本次请求需要的菜单路径。
     *
     * @param string $controller
     * @param string $action
     * @return string
     */
    protected function resolveRequiredPath($controller, $action)
    {
        if (isset($this->actionPowerMap[$controller][$action])) {
            return $this->actionPowerMap[$controller][$action];
        }
        // 默认：以该控制器 index 菜单页代表模块权限
        return '/' . $controller . '/index';
    }

    /**
     * 无权限时返回 403 JSON 或错误页。
     *
     * @return void
     */
    protected function denyPower()
    {
        $url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (stripos($url, 'ajax') !== false || $this->request->isAjax() || $this->request->isPost()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(DefaultResponse(403, false, '无权限访问该功能'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $this->error('无权限访问该功能', '/Index/welcome');
    }
}

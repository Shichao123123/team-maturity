<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\model\PowerModel;
use app\index\service\DashboardService;
use app\index\service\OpLogService;
use think\Request;
use think\Session;

class Index extends Base
{
    /**
     * 后台首页入口，渲染主框架页面。
     *
     * @return mixed 渲染 index/index 视图
     */
    public function index()
    {
        if (!empty($this->currentAdmin['org_id'])) {
            $this->redirect('/TmNational/company?org_id='.(int)$this->currentAdmin['org_id']);
            return;
        }
        return $this->fetch('index/index');
    }

    /**
     * 工作台入口：跳转全国运营看板（系统首页）。
     *
     * @return void
     */
    public function welcome()
    {
        if (!empty($this->currentAdmin['org_id'])) {
            $this->redirect('/TmNational/company?org_id='.(int)$this->currentAdmin['org_id']);
            return;
        }
        $roleId = isset($this->currentAdmin['role_id']) ? (int)$this->currentAdmin['role_id'] : 0;
        if ($roleId > 0 && (new PowerModel())->roleCanAccessPath($roleId, '/TmNational/index')) {
            $this->redirect('/TmNational/index');
            return;
        }
        $this->assign('admin', $this->currentAdmin);
        $this->assign('batches', DashboardService::listBatchesWithResult());
        $this->assign('orgs', (new OrgModel())->getSelectOptions());
        return $this->fetch('index/welcome');
    }

    /**
     * 工作台统计 API：支持批次 + 组织筛选（复用看板口径）。
     *
     * @return \think\response\Json 返回班组总数、均分、等级分布等统计 JSON
     */
    public function workbenchStats()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        if (!empty($this->currentAdmin['org_id'])) $orgId=(int)$this->currentAdmin['org_id'];
        $data = DashboardService::levelDist($batchId, $orgId, 1, 1);
        return json(DefaultResponse(0, true, 'ok', [
            'team_total'      => $data['team_total'],
            'avg_score'       => $data['avg_score'],
            'avg_score_text'  => $data['avg_score'] === null ? null : (string)$data['avg_score'],
            'essential_cnt'   => $data['essential_cnt'],
            'passive_cnt'     => $data['passive_cnt'],
            'result_team_cnt' => $data['result_team_cnt'],
            'batch_id'        => $data['batch_id'],
            'batch_name'      => $data['batch_name'],
            'scored_at'       => $data['scored_at'],
            'org_id'          => $data['org_id'],
            'levels'          => $data['levels'],
        ]));
    }

    /**
     * Pear 侧栏菜单 API（按当前管理员角色权限过滤）。
     *
     * @return \think\response\Json 返回菜单树 JSON 数组
     */
    public function menu()
    {
        $admin = $this->currentAdmin;
        if (!empty($admin['org_id'])) return json([['id'=>34,'title'=>'企业详情','icon'=>'layui-icon-chart-screen','type'=>1,'href'=>'/TmNational/company?org_id='.(int)$admin['org_id']]]);
        $model = new PowerModel();
        $list = $model->getMenuByRoleId($admin['role_id']);
        return json($list);
    }

    /**
     * 退出后台登录，写操作日志并清除 Session。
     *
     * @return void 重定向至登录页
     */
    public function backsys()
    {
        $user = Session::get('tm_user');
        if ($user && isset($user['id'])) {
            OpLogService::write('login', 'logout', $user['id'], [
                'username' => isset($user['username']) ? $user['username'] : '',
            ], $user['id']);
        }
        Session::clear();
        // 直接回登录页，不展示 ThinkPHP 默认跳转页
        $this->redirect('/Login');
    }
}

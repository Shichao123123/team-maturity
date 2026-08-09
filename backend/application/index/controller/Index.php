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
    public function index()
    {
        return $this->fetch('index/index');
    }

    public function welcome()
    {
        $admin = $this->currentAdmin;
        $this->assign('admin', $admin);
        $this->assign('batches', DashboardService::listBatchesWithResult());
        $this->assign('orgs', (new OrgModel())->getSelectOptions());
        return $this->fetch('index/welcome');
    }

    /**
     * 工作台统计：支持批次 + 组织筛选（复用看板口径）
     */
    public function workbenchStats()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        $data = DashboardService::levelDist($batchId, $orgId);
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
     * Pear 侧栏菜单（按角色权限）
     */
    public function menu()
    {
        $admin = $this->currentAdmin;
        $model = new PowerModel();
        $list = $model->getMenuByRoleId($admin['role_id']);
        return json($list);
    }

    public function backsys()
    {
        $user = Session::get('tm_user');
        if ($user && isset($user['id'])) {
            OpLogService::write('login', 'logout', $user['id'], [
                'username' => isset($user['username']) ? $user['username'] : '',
            ], $user['id']);
        }
        Session::clear();
        $this->success('您已退出', '/Login');
    }
}

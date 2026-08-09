<?php
namespace app\index\controller;

use app\index\model\MemberModel;
use app\index\model\TeamModel;
use app\index\service\ExcelHelper;
use app\index\service\ImportService;
use app\index\service\OpLogService;
use think\Db;
use think\Request;

class TmMember extends Base
{
    public function index()
    {
        $teams = Db::name('tm_team')->whereNull('deleted_at')->order('id desc')->select();
        $this->assign('teams', $teams);
        $this->assign('roles', tmRoleLabels());
        return $this->fetch('tm_member/index');
    }

    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $teamId = $req->param('teamId', null);
        $keyword = $req->param('keyword', '', 'trim');
        $roleCode = $req->param('roleCode', '', 'trim');
        $status = $req->param('status', null);
        list($count, $list) = (new MemberModel())->getPage($teamId, $keyword, $roleCode, $status, $page, $limit);
        $labels = tmRoleLabels();
        foreach ($list as &$row) {
            $row['role_name'] = isset($labels[$row['role_code']]) ? $labels[$row['role_code']] : $row['role_code'];
        }
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    public function detail()
    {
        $id = (int)Request::instance()->param('id', 0);
        $row = (new MemberModel())->find($id);
        if (!$row) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', $row));
    }

    public function add()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $data = [
            'team_id'   => (int)$req->param('team_id', 0),
            'name'      => $req->param('name', '', 'trim'),
            'mobile'    => $req->param('mobile', '', 'trim'),
            'post_name' => $req->param('post_name', '', 'trim'),
            'role_code' => $req->param('role_code', 'member', 'trim'),
            'status'    => (int)$req->param('status', 1),
        ];
        if ($data['team_id'] <= 0 || $data['name'] === '' || $data['mobile'] === '') {
            return json(DefaultResponse(400, false, '请填写班组、姓名、手机号'));
        }
        if (!preg_match('/^1\d{10}$/', $data['mobile'])) {
            return json(DefaultResponse(400, false, '手机号格式不正确'));
        }
        $model = new MemberModel();
        if ($model->existsMobile($data['mobile'])) {
            return json(DefaultResponse(400, false, '手机号已存在'));
        }
        if (!(new TeamModel())->find($data['team_id'])) {
            return json(DefaultResponse(400, false, '班组不存在'));
        }
        $id = $model->addRow($data);
        return json(DefaultResponse(0, true, '新增成功', ['id' => $id]));
    }

    public function edit()
    {
        $req = Request::instance();
        $id = (int)$req->param('id', 0);
        $model = new MemberModel();
        if (!$model->find($id)) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        $data = [
            'team_id'   => (int)$req->param('team_id', 0),
            'name'      => $req->param('name', '', 'trim'),
            'mobile'    => $req->param('mobile', '', 'trim'),
            'post_name' => $req->param('post_name', '', 'trim'),
            'role_code' => $req->param('role_code', 'member', 'trim'),
            'status'    => (int)$req->param('status', 1),
        ];
        if ($data['team_id'] <= 0 || $data['name'] === '' || $data['mobile'] === '') {
            return json(DefaultResponse(400, false, '请填写班组、姓名、手机号'));
        }
        if (!preg_match('/^1\d{10}$/', $data['mobile'])) {
            return json(DefaultResponse(400, false, '手机号格式不正确'));
        }
        if ($model->existsMobile($data['mobile'], $id)) {
            return json(DefaultResponse(400, false, '手机号已存在'));
        }
        $model->editRow($id, $data);
        return json(DefaultResponse(0, true, '保存成功'));
    }

    public function setStatus()
    {
        $req = Request::instance();
        $id = (int)$req->param('id', 0);
        $status = (int)$req->param('status', 1);
        $model = new MemberModel();
        if (!$model->find($id)) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        $model->editRow($id, ['status' => $status]);
        return json(DefaultResponse(0, true, '状态已更新'));
    }

    public function delete()
    {
        $id = (int)Request::instance()->param('id', 0);
        (new MemberModel())->softDelete($id);
        return json(DefaultResponse(0, true, '已删除'));
    }

    public function importTemplate()
    {
        ExcelHelper::download('成员导入模板.xls', [
            '姓名', '手机号', '所属班组', '岗位', '角色', '状态',
        ], [
            ['李强', '13900001111', '甲班', '班组长', '班组长', '启用'],
            ['王芳', '13900001112', '甲班', '安全员', '安全员', '启用'],
        ]);
    }

    public function import()
    {
        list($ok, $pathOrMsg) = ImportService::saveUpload('file');
        if (!$ok) {
            return json(DefaultResponse(400, false, $pathOrMsg));
        }
        list(, $rows) = ExcelHelper::readAssoc($pathOrMsg);
        if (empty($rows)) {
            return json(DefaultResponse(400, false, '文件无有效数据行'));
        }
        $adminId = isset($this->currentAdmin['id']) ? $this->currentAdmin['id'] : 0;
        $result = ImportService::importMembers($rows, $adminId);
        OpLogService::write('import', 'member', isset($result['job_id']) ? $result['job_id'] : null, $result);
        $msg = "导入完成：成功 {$result['success_cnt']}，失败 {$result['fail_cnt']}";
        return json(DefaultResponse(0, true, $msg, $result));
    }
}

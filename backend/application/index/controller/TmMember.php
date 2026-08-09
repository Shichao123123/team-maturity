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
    /**
     * 成员管理页，加载班组与角色选项。
     *
     * @return mixed 渲染 tm_member/index 视图
     */
    public function index()
    {
        $teams = Db::name('tm_team')->whereNull('deleted_at')->order('id desc')->select();
        $this->assign('teams', $teams);
        $this->assign('roles', tmRoleLabels());
        return $this->fetch('tm_member/index');
    }

    /**
     * 成员分页列表 API（含答题摘要：最近得分、批次、等级等）。
     *
     * @return \think\response\Json Layui 分页格式的成员列表
     */
    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $teamId = $req->param('teamId', null);
        $keyword = $req->param('keyword', '', 'trim');
        $roleCode = $req->param('roleCode', '', 'trim');
        $status = $req->param('status', null);
        $model = new MemberModel();
        list($count, $list) = $model->getPage($teamId, $keyword, $roleCode, $status, $page, $limit);
        $labels = tmRoleLabels();
        foreach ($list as &$row) {
            $row['role_name'] = isset($labels[$row['role_code']]) ? $labels[$row['role_code']] : $row['role_code'];
        }
        unset($row);
        $list = $model->attachPaperSummary($list);
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    /**
     * 成员答题记录列表 API。
     *
     * @return \think\response\Json 返回 member_id 及该成员各批次答卷摘要列表
     */
    public function paperList()
    {
        $memberId = (int)Request::instance()->param('member_id', 0);
        $model = new MemberModel();
        if (!$model->find($memberId)) {
            return json(DefaultResponse(404, false, '成员不存在'));
        }
        $list = $model->listPapers($memberId);
        return json(DefaultResponse(0, true, 'ok', ['member_id' => $memberId, 'list' => $list]));
    }

    /**
     * 单份答卷详情 API：结果摘要 + 逐题作答明细。
     *
     * @return \think\response\Json 返回 paper 摘要与 answers 逐题列表
     */
    public function paperDetail()
    {
        $req = Request::instance();
        $paperId = (int)$req->param('paper_id', 0);
        $memberId = (int)$req->param('member_id', 0);
        $detail = (new MemberModel())->paperDetail($paperId, $memberId);
        if (!$detail) {
            return json(DefaultResponse(404, false, '答卷不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', $detail));
    }

    /**
     * 成员详情 API。
     *
     * @return \think\response\Json 单条成员记录
     */
    public function detail()
    {
        $id = (int)Request::instance()->param('id', 0);
        $row = (new MemberModel())->find($id);
        if (!$row) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', $row));
    }

    /**
     * 新增成员 API（POST，PC 端补录，手机号可选）。
     *
     * @return \think\response\Json 成功返回新成员 id
     */
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
        if ($data['team_id'] <= 0 || $data['name'] === '') {
            return json(DefaultResponse(400, false, '请填写班组、姓名'));
        }
        // 人员主路径为小程序自助登记；PC 手机号仅作补录，可空
        if ($data['mobile'] === '') {
            $data['mobile'] = null;
        } elseif (!preg_match('/^1\d{10}$/', $data['mobile'])) {
            return json(DefaultResponse(400, false, '手机号格式不正确'));
        }
        $model = new MemberModel();
        if ($data['mobile'] !== null && $model->existsMobile($data['mobile'])) {
            return json(DefaultResponse(400, false, '手机号已存在'));
        }
        if (!(new TeamModel())->find($data['team_id'])) {
            return json(DefaultResponse(400, false, '班组不存在'));
        }
        $id = $model->addRow($data);
        return json(DefaultResponse(0, true, '新增成功', ['id' => $id]));
    }

    /**
     * 编辑成员 API（POST）。
     *
     * @return \think\response\Json 保存结果
     */
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
        if ($data['team_id'] <= 0 || $data['name'] === '') {
            return json(DefaultResponse(400, false, '请填写班组、姓名'));
        }
        if ($data['mobile'] === '') {
            $data['mobile'] = null;
        } elseif (!preg_match('/^1\d{10}$/', $data['mobile'])) {
            return json(DefaultResponse(400, false, '手机号格式不正确'));
        }
        if ($data['mobile'] !== null && $model->existsMobile($data['mobile'], $id)) {
            return json(DefaultResponse(400, false, '手机号已存在'));
        }
        $model->editRow($id, $data);
        return json(DefaultResponse(0, true, '保存成功'));
    }

    /**
     * 更新成员启用/停用状态 API。
     *
     * @return \think\response\Json 状态更新结果
     */
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

    /**
     * 软删除成员 API。
     *
     * @return \think\response\Json 删除结果
     */
    public function delete()
    {
        $id = (int)Request::instance()->param('id', 0);
        (new MemberModel())->softDelete($id);
        return json(DefaultResponse(0, true, '已删除'));
    }

    /**
     * 下载成员批量导入 Excel 模板。
     *
     * @return void 直接输出 xls 文件下载
     */
    public function importTemplate()
    {
        ExcelHelper::download('成员导入模板.xls', [
            '姓名', '手机号', '所属班组', '岗位', '角色', '状态',
        ], [
            ['李强', '13900001111', '甲班', '班组长', '班组长', '启用'],
            ['王芳', '13900001112', '甲班', '安全员', '安全员', '启用'],
        ]);
    }

    /**
     * 批量导入成员 API（上传 Excel，POST）。
     *
     * @return \think\response\Json 返回成功/失败条数及明细
     */
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
        $result = ImportService::importMembers($rows, $adminId, $pathOrMsg);
        OpLogService::write('import', 'member', isset($result['job_id']) ? $result['job_id'] : null, $result);
        $msg = "导入完成：成功 {$result['success_cnt']}，失败 {$result['fail_cnt']}";
        return json(DefaultResponse(0, true, $msg, $result));
    }
}

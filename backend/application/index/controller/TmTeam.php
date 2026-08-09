<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\model\TeamModel;
use app\index\service\ExcelHelper;
use app\index\service\ImportService;
use app\index\service\OpLogService;
use think\Request;

class TmTeam extends Base
{
    /**
     * 班组管理页，加载组织路径下拉选项。
     *
     * @return mixed 渲染 tm_team/index 视图
     */
    public function index()
    {
        // 路径下拉：两层/三层/更深均可挂班组
        $this->assign('orgs', (new OrgModel())->getSelectOptions());
        return $this->fetch('tm_team/index');
    }

    /**
     * 班组分页列表 API。
     *
     * @return \think\response\Json Layui 分页格式的班组列表（含 org_name）
     */
    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $orgId = $req->param('orgId', null);
        $keyword = $req->param('keyword', '', 'trim');
        $status = $req->param('status', null);
        list($count, $list) = (new TeamModel())->getPage($orgId, $keyword, $status, $page, $limit);
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    /**
     * 班组详情 API。
     *
     * @return \think\response\Json 单条班组记录
     */
    public function detail()
    {
        $id = (int)Request::instance()->param('id', 0);
        $row = (new TeamModel())->find($id);
        if (!$row) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', $row));
    }

    /**
     * 新增班组 API。
     *
     * @return \think\response\Json 成功时返回新记录 id
     */
    public function add()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $data = [
            'org_id' => (int)$req->param('org_id', 0),
            'name'   => $req->param('name', '', 'trim'),
            'status' => (int)$req->param('status', 1),
        ];
        if ($data['org_id'] <= 0 || $data['name'] === '') {
            return json(DefaultResponse(400, false, '请填写所属组织与班组名称'));
        }
        $id = (new TeamModel())->addRow($data);
        return json(DefaultResponse(0, true, '新增成功', ['id' => $id]));
    }

    /**
     * 编辑班组 API。
     *
     * @return \think\response\Json 保存成功或校验失败提示
     */
    public function edit()
    {
        $req = Request::instance();
        $id = (int)$req->param('id', 0);
        $model = new TeamModel();
        if (!$model->find($id)) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        $data = [
            'org_id' => (int)$req->param('org_id', 0),
            'name'   => $req->param('name', '', 'trim'),
            'status' => (int)$req->param('status', 1),
        ];
        if ($data['org_id'] <= 0 || $data['name'] === '') {
            return json(DefaultResponse(400, false, '请填写所属组织与班组名称'));
        }
        $model->editRow($id, $data);
        return json(DefaultResponse(0, true, '保存成功'));
    }

    /**
     * 更新班组启用/停用状态 API。
     *
     * @return \think\response\Json 状态更新结果
     */
    public function setStatus()
    {
        $req = Request::instance();
        $id = (int)$req->param('id', 0);
        $status = (int)$req->param('status', 1);
        $model = new TeamModel();
        if (!$model->find($id)) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        $model->editRow($id, ['status' => $status]);
        return json(DefaultResponse(0, true, '状态已更新'));
    }

    /**
     * 软删除班组 API（存在成员时拒绝删除）。
     *
     * @return \think\response\Json 删除结果或失败原因
     */
    public function delete()
    {
        $id = (int)Request::instance()->param('id', 0);
        $res = (new TeamModel())->softDelete($id);
        if ($res !== true) {
            return json(DefaultResponse(400, false, $res));
        }
        return json(DefaultResponse(0, true, '已删除'));
    }

    /**
     * 下载班组 Excel 导入模板。
     *
     * @return void 直接输出文件流
     */
    public function importTemplate()
    {
        ExcelHelper::download('班组导入模板.xls', [
            '班组名称', '所属组织', '状态',
        ], [
            ['甲班', '生产一工段', '启用'],
            ['乙班', '生产一工段', '启用'],
        ]);
    }

    /**
     * 批量导入班组 API（上传 Excel 并写入数据库）。
     *
     * @return \think\response\Json 返回成功/失败条数及任务 id
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
        $result = ImportService::importTeams($rows, $adminId);
        OpLogService::write('import', 'team', isset($result['job_id']) ? $result['job_id'] : null, $result);
        $msg = "导入完成：成功 {$result['success_cnt']}，失败 {$result['fail_cnt']}";
        return json(DefaultResponse(0, true, $msg, $result));
    }
}

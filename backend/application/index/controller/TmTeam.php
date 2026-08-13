<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\model\TeamModel;
use app\index\service\ArchiveTreeService;
use app\index\service\ExcelHelper;
use app\index\service\ImportService;
use app\index\service\OpLogService;
use app\index\service\ResultService;
use think\Db;
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
        $orgSubtree = (int)$req->param('orgSubtree', 0);
        $orgIds = null;
        if ($orgSubtree && $orgId !== null && $orgId !== '') {
            $orgIds = ArchiveTreeService::orgSubtreeIdList((int)$orgId);
            if (!$orgIds) {
                return json(DefaultResponseList(0, 0, 'ok', []));
            }
            $orgId = null;
        }
        list($count, $list) = (new TeamModel())->getPage($orgId, $keyword, $status, $page, $limit, $orgIds);
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    /**
     * 左侧组织树（嵌套）。
     *
     * @return \think\response\Json
     */
    public function sideTree()
    {
        return json(DefaultResponse(0, true, 'ok', ArchiveTreeService::nestedOrgTree()));
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
     * 班组参评批次及得分摘要（班组管理「班组详情」入口）。
     *
     * @return \think\response\Json
     */
    public function batchResults()
    {
        $teamId = (int)Request::instance()->param('team_id', 0);
        list($ok, $msg, $data) = ResultService::teamBatchList($teamId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    /**
     * 班组某批次详细结果：得分、薄弱指标、成员答卷。
     *
     * @return \think\response\Json
     */
    public function resultDetail()
    {
        $req = Request::instance();
        $teamId = (int)$req->param('team_id', 0);
        $batchId = (int)$req->param('batch_id', 0);
        list($ok, $msg, $data) = ResultService::teamDetail($batchId, $teamId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    /**
     * 个人答卷详情（校验归属该班组）。
     *
     * @return \think\response\Json
     */
    public function paperDetail()
    {
        $req = Request::instance();
        $paperId = (int)$req->param('paper_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        list($ok, $msg, $detail) = ResultService::paperDetail($paperId);
        if (!$ok) {
            return json(DefaultResponse(404, false, $msg));
        }
        $paperTeamId = isset($detail['paper']['team_id']) ? (int)$detail['paper']['team_id'] : 0;
        if ($teamId > 0 && $paperTeamId !== $teamId) {
            return json(DefaultResponse(403, false, '答卷不属于该班组'));
        }
        return json(DefaultResponse(0, true, 'ok', $detail));
    }

    /**
     * 导出班组某批次详情 PDF。
     *
     * @return void|\think\response\Json
     */
    public function exportTeamDetailPdf()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        list($ok, $msg) = ResultService::exportTeamDetailPdf($batchId, $teamId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }

    /**
     * 导出个人答卷 PDF（校验归属该班组）。
     *
     * @return void|\think\response\Json
     */
    public function exportPaperPdf()
    {
        $req = Request::instance();
        $paperId = (int)$req->param('paper_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        if ($paperId <= 0) {
            return json(DefaultResponse(400, false, '参数错误'));
        }
        if ($teamId > 0) {
            $paperTeamId = (int)Db::name('tm_paper')->where('id', $paperId)->value('team_id');
            if ($paperTeamId !== $teamId) {
                return json(DefaultResponse(403, false, '答卷不属于该班组'));
            }
        }
        list($ok, $msg) = ResultService::exportPaperPdf($paperId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
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
        $result = ImportService::importTeams($rows, $adminId, $pathOrMsg);
        OpLogService::write('import', 'team', isset($result['job_id']) ? $result['job_id'] : null, $result);
        $msg = "导入完成：成功 {$result['success_cnt']}，失败 {$result['fail_cnt']}（误操作可点「撤回上次导入」）";
        return json(DefaultResponse(0, true, $msg, $result));
    }

    /**
     * 撤回最近一次班组导入。
     *
     * @return \think\response\Json
     */
    public function undoLastImport()
    {
        list($ok, $msg, $data) = ImportService::undoLastTeamImport();
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('import_undo', 'team', isset($data['job_id']) ? $data['job_id'] : null, $data);
        return json(DefaultResponse(0, true, $msg, $data));
    }
}

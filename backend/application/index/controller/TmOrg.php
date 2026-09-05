<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\service\ArchiveTreeService;
use app\index\service\ExcelHelper;
use app\index\service\ImportService;
use app\index\service\OpLogService;
use think\Request;

class TmOrg extends Base
{
    /**
     * 组织管理页，加载组织下拉选项。
     *
     * @return mixed 渲染 tm_org/index 视图
     */
    public function index()
    {
        $this->assign('orgs', (new OrgModel())->getSelectOptions());
        return $this->fetch('tm_org/index');
    }

    /**
     * 组织树形结构 API（递归构建父子层级）。
     *
     * @return \think\response\Json 返回组织树节点列表
     */
    public function tree()
    {
        $model = new OrgModel();
        $rows = $model->getAllActive();
        $tree = $this->buildTree($rows, 0);
        return json(DefaultResponse(0, true, 'ok', $tree));
    }

    /**
     * 组织分页列表 API。
     *
     * @return \think\response\Json Layui 分页格式的组织列表
     */
    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $parentId = $req->param('parentId', null);
        $keyword = $req->param('keyword', '', 'trim');
        $model = new OrgModel();
        list($count, $list) = $model->getPage($parentId, $keyword, $page, $limit);
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    /**
     * 组织树表数据（扁平 id/pid，供 treetable）。
     *
     * @return \think\response\Json
     */
    public function getTreeList()
    {
        $keyword = Request::instance()->param('keyword', '', 'trim');
        $list = ArchiveTreeService::orgFlat($keyword);
        return json(DefaultResponse(0, true, 'ok', $list));
    }

    /**
     * 新增组织 API。
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
            'parent_id' => (int)$req->param('parent_id', 0),
            'name'      => $req->param('name', '', 'trim'),
            'org_type'  => (int)$req->param('org_type', 1),
            'industry'  => $req->param('industry', '', 'trim'),
            'province'  => $req->param('province', '', 'trim'),
            'city'      => $req->param('city', '', 'trim'),
            'lng'       => $req->param('lng', '', 'trim'),
            'lat'       => $req->param('lat', '', 'trim'),
            'sort_no'   => (int)$req->param('sort_no', 0),
            'status'    => (int)$req->param('status', 1),
        ];
        if ($data['name'] === '') {
            return json(DefaultResponse(400, false, '组织名称不能为空'));
        }
        if (!in_array($data['org_type'], [0, 1, 2, 3], true)) {
            $data['org_type'] = 0;
        }
        $data['lng'] = ($data['lng'] === '') ? null : round((float)$data['lng'], 6);
        $data['lat'] = ($data['lat'] === '') ? null : round((float)$data['lat'], 6);
        $id = (new OrgModel())->addRow($data);
        return json(DefaultResponse(0, true, '新增成功', ['id' => $id]));
    }

    /**
     * 编辑组织 API。
     *
     * @return \think\response\Json 保存成功或校验失败提示
     */
    public function edit()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        $model = new OrgModel();
        if (!$model->find($id)) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        $data = [
            'parent_id' => (int)$req->param('parent_id', 0),
            'name'      => $req->param('name', '', 'trim'),
            'org_type'  => (int)$req->param('org_type', 1),
            'industry'  => $req->param('industry', '', 'trim'),
            'province'  => $req->param('province', '', 'trim'),
            'city'      => $req->param('city', '', 'trim'),
            'lng'       => $req->param('lng', '', 'trim'),
            'lat'       => $req->param('lat', '', 'trim'),
            'sort_no'   => (int)$req->param('sort_no', 0),
            'status'    => (int)$req->param('status', 1),
        ];
        if ($data['name'] === '') {
            return json(DefaultResponse(400, false, '组织名称不能为空'));
        }
        if (!in_array($data['org_type'], [0, 1, 2, 3], true)) {
            $data['org_type'] = 0;
        }
        if ($data['parent_id'] === $id) {
            return json(DefaultResponse(400, false, '上级不能是自己'));
        }
        $data['lng'] = ($data['lng'] === '') ? null : round((float)$data['lng'], 6);
        $data['lat'] = ($data['lat'] === '') ? null : round((float)$data['lat'], 6);
        $model->editRow($id, $data);
        return json(DefaultResponse(0, true, '保存成功'));
    }

    /**
     * 更新组织启用/停用状态 API。
     *
     * @return \think\response\Json 状态更新结果
     */
    public function setStatus()
    {
        $req = Request::instance();
        $id = (int)$req->param('id', 0);
        $status = (int)$req->param('status', 1);
        $model = new OrgModel();
        if (!$model->find($id)) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        $model->editRow($id, ['status' => $status]);
        return json(DefaultResponse(0, true, '状态已更新'));
    }

    /**
     * 软删除组织 API（存在下级或班组时拒绝删除）。
     *
     * @return \think\response\Json 删除结果或失败原因
     */
    public function delete()
    {
        $id = (int)Request::instance()->param('id', 0);
        $res = (new OrgModel())->softDelete($id);
        if ($res !== true) {
            return json(DefaultResponse(400, false, $res));
        }
        return json(DefaultResponse(0, true, '已删除'));
    }

    /**
     * 下载组织 Excel 导入模板。
     *
     * @return void 直接输出文件流
     */
    public function importTemplate()
    {
        // 状态导入时固定为启用，模板不要求填；组织类型、排序均可留空
        ExcelHelper::download('组织导入模板.csv', [
            '组织名称', '上级组织', '组织类型', '排序',
        ], [
            ['示例公司', '', '', ''],
            ['一厂', '示例公司', '', ''],
            ['生产一工段', '一厂', '', ''],
        ]);
    }

    /**
     * 批量导入组织 API（上传 Excel 并写入数据库）。
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
        $result = ImportService::importOrgs($rows, $adminId, $pathOrMsg);
        OpLogService::write('import', 'org', isset($result['job_id']) ? $result['job_id'] : null, $result);
        $msg = "导入完成：成功 {$result['success_cnt']}，失败 {$result['fail_cnt']}（误操作可点「撤回上次导入」）";
        return json(DefaultResponse(0, true, $msg, $result));
    }

    /**
     * 撤回最近一次组织导入（删除本次新增、还原本次更新）。
     *
     * @return \think\response\Json
     */
    public function undoLastImport()
    {
        list($ok, $msg, $data) = ImportService::undoLastOrgImport();
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('import_undo', 'org', isset($data['job_id']) ? $data['job_id'] : null, $data);
        return json(DefaultResponse(0, true, $msg, $data));
    }

    /**
     * 递归构建组织树形结构。
     *
     * @param array $rows     全部组织行
     * @param int   $parentId 父级组织 id
     * @return array 含 children 子节点的树形数组
     */
    private function buildTree($rows, $parentId)
    {
        $tree = [];
        foreach ($rows as $row) {
            if ((int)$row['parent_id'] !== (int)$parentId) {
                continue;
            }
            $row['children'] = $this->buildTree($rows, $row['id']);
            $tree[] = $row;
        }
        return $tree;
    }
}

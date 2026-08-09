<?php
namespace app\index\controller;

use think\Db;
use think\Request;

class TmImport extends Base
{
    private $typeNames = [
        'org'    => '组织',
        'team'   => '班组',
        'member' => '成员',
    ];

    private $statusNames = [
        0 => '处理中',
        1 => '成功',
        2 => '部分失败',
        3 => '失败',
    ];

    /**
     * 导入任务记录页，加载导入类型名称映射。
     *
     * @return mixed 渲染 tm_import/index 视图
     */
    public function index()
    {
        $this->assign('typeNames', $this->typeNames);
        return $this->fetch('tm_import/index');
    }

    /**
     * 导入任务分页列表 API（含操作人、类型/状态中文名）。
     *
     * @return \think\response\Json Layui 分页格式的导入任务列表
     */
    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $type = $req->param('type', '', 'trim');
        $status = $req->param('status', '');

        $q = Db::name('tm_import_job')->alias('j')
            ->join('tm_admin a', 'a.id = j.created_by', 'LEFT')
            ->field('j.*, a.name as admin_name, a.username as admin_username');

        if ($type !== '') {
            $q->where('j.type', $type);
        }
        if ($status !== '' && $status !== null) {
            $q->where('j.status', (int)$status);
        }

        $countQ = Db::name('tm_import_job');
        if ($type !== '') {
            $countQ->where('type', $type);
        }
        if ($status !== '' && $status !== null) {
            $countQ->where('status', (int)$status);
        }
        $count = $countQ->count();
        $list = $q->order('j.id desc')->page($page, $limit)->select();

        foreach ($list as &$row) {
            $row['type_name'] = isset($this->typeNames[$row['type']])
                ? $this->typeNames[$row['type']]
                : $row['type'];
            $row['status_name'] = isset($this->statusNames[(int)$row['status']])
                ? $this->statusNames[(int)$row['status']]
                : (string)$row['status'];
            $row['file_name'] = $row['file_path'] ? basename($row['file_path']) : '';
            unset($row['file_path']);
            if (empty($row['admin_name'])) {
                $row['admin_name'] = $row['admin_username'] ?: '';
            }
        }
        unset($row);

        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    /**
     * 导入任务详情 API（含错误明细列表）。
     *
     * @return \think\response\Json 单条导入任务详情
     */
    public function detail()
    {
        $id = (int)Request::instance()->param('id', 0);
        if ($id <= 0) {
            return json(DefaultResponse(400, false, '参数错误'));
        }
        $row = Db::name('tm_import_job')->where('id', $id)->find();
        if (!$row) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        $errors = [];
        if (!empty($row['error_json'])) {
            $decoded = json_decode($row['error_json'], true);
            if (is_array($decoded)) {
                $errors = $decoded;
            }
        }
        $row['type_name'] = isset($this->typeNames[$row['type']])
            ? $this->typeNames[$row['type']]
            : $row['type'];
        $row['status_name'] = isset($this->statusNames[(int)$row['status']])
            ? $this->statusNames[(int)$row['status']]
            : (string)$row['status'];
        $row['errors'] = $errors;
        $row['file_name'] = $row['file_path'] ? basename($row['file_path']) : '';
        unset($row['error_json'], $row['file_path']);

        return json(DefaultResponse(0, true, 'ok', $row));
    }
}

<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\service\BatchService;
use app\index\service\OpLogService;
use app\index\service\WxMiniService;
use think\Db;
use think\Request;

class TmBatch extends Base
{
    /**
     * 测评批次管理页，加载已发布指标版本与可绑定班组列表。
     *
     * @return mixed 渲染 tm_batch/index 视图
     */
    public function index()
    {
        $versions = Db::name('tm_indicator_version')
            ->where('status', 1)
            ->order('id desc')
            ->field('id, name, version_no')
            ->select();
        $orgModel = new OrgModel();
        $orgs = $orgModel->getAllActive();
        $orgList = [];
        foreach ($orgs as $o) {
            $orgList[] = [
                'id'        => (int)$o['id'],
                'parent_id' => (int)$o['parent_id'],
                'name'      => (string)$o['name'],
                'sort_no'   => (int)$o['sort_no'],
            ];
        }
        $teams = Db::name('tm_team')->alias('t')
            ->join('tm_org o', 'o.id = t.org_id', 'LEFT')
            ->whereNull('t.deleted_at')
            ->where('t.status', 1)
            ->field('t.id, t.name, t.org_id, o.name as org_name')
            ->order('t.id asc')
            ->select();
        $orgPathMap = [];
        foreach ($orgModel->getSelectOptions() as $o) {
            $orgPathMap[(int)$o['id']] = $o['path'];
        }
        $teamList = [];
        foreach ($teams as $t) {
            $oid = (int)$t['org_id'];
            $teamList[] = [
                'id'       => (int)$t['id'],
                'name'     => (string)$t['name'],
                'org_id'   => $oid,
                'org_name' => (string)($t['org_name'] ?: ''),
                'org_path' => isset($orgPathMap[$oid]) ? $orgPathMap[$oid] : (string)($t['org_name'] ?: ''),
            ];
        }

        $this->assign('versions', $versions);
        $this->assign('orgList', $orgList);
        $this->assign('teamList', $teamList);
        return $this->fetch('tm_batch/index');
    }

    /**
     * 批次分页列表 API。
     *
     * @return \think\response\Json 返回 Layui 分页格式的批次列表 JSON
     */
    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $status = $req->param('status', null);
        $keyword = $req->param('keyword', '', 'trim');
        list($count, $list) = BatchService::getPage($status, $keyword, $page, $limit);
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    /**
     * 批次详情 API。
     *
     * @return \think\response\Json 返回单条批次完整信息，不存在时 404
     */
    public function detail()
    {
        $id = (int)Request::instance()->param('id', 0);
        $row = BatchService::detail($id);
        if (!$row) {
            return json(DefaultResponse(404, false, '批次不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', $row));
    }

    /**
     * 输出 / 下载批次正式微信小程序码（PNG）。
     * getwxacodeunlimit 生成的码长期有效，可打印下发给员工；download=1 时附件下载。
     *
     * @return mixed
     */
    public function wxacode()
    {
        $req = Request::instance();
        $id = (int)$req->param('id', 0);
        $refresh = (int)$req->param('refresh', 0) === 1;
        $download = (int)$req->param('download', 0) === 1;
        $width = (int)$req->param('width', $download ? 860 : 280);
        $row = BatchService::detail($id);
        if ($id <= 0 || !$row) {
            return json(DefaultResponse(404, false, '批次不存在'));
        }
        list($ok, $binOrMsg, $meta) = WxMiniService::getBatchWxacode($id, $refresh, $width);
        if (!$ok) {
            return json(DefaultResponse(400, false, $binOrMsg, $meta ?: null));
        }
        $safeName = preg_replace('/[\\\\\/:*?"<>|]+/', '_', (string)(isset($row['name']) ? $row['name'] : '测评小程序码'));
        $safeName = trim($safeName, ' ._') ?: '测评小程序码';
        $filename = $safeName . '.png';
        $headers = [
            // 码本身不过期；浏览器可缓存展示用图
            'Cache-Control' => $refresh ? 'no-store' : 'private, max-age=86400',
        ];
        if ($download) {
            $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
        }
        return \think\Response::create($binOrMsg, 'html', 200)
            ->contentType('image/png')
            ->header($headers);
    }

    /**
     * 新建测评批次 API（POST）。
     *
     * @return \think\response\Json 成功返回新批次 id
     */
    public function add()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $adminId = isset($this->currentAdmin['id']) ? $this->currentAdmin['id'] : 0;
        list($ok, $msg, $id) = BatchService::create([
            'name'               => $req->param('name', '', 'trim'),
            'version_id'         => (int)$req->param('version_id', 0),
            'start_time'         => $req->param('start_time', '', 'trim'),
            'end_time'           => $req->param('end_time', '', 'trim'),
            'question_pick_mode' => $req->param('question_pick_mode', '', 'trim'),
        ], $adminId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('batch', 'add', $id, ['name' => $req->param('name', '', 'trim')]);
        return json(DefaultResponse(0, true, $msg, ['id' => $id]));
    }

    /**
     * 编辑测评批次基本信息 API（POST）。
     *
     * @return \think\response\Json 成功或校验失败提示
     */
    public function edit()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = BatchService::update($id, [
            'name'               => $req->param('name', '', 'trim'),
            'version_id'         => (int)$req->param('version_id', 0),
            'start_time'         => $req->param('start_time', '', 'trim'),
            'end_time'           => $req->param('end_time', '', 'trim'),
            'question_pick_mode' => $req->param('question_pick_mode', '', 'trim'),
        ]);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('batch', 'edit', $id, ['name' => $req->param('name', '', 'trim')]);
        return json(DefaultResponse(0, true, $msg));
    }

    /**
     * 为批次绑定参评班组 API（POST）。
     *
     * @return \think\response\Json 绑定结果
     */
    public function bindTeams()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $batchId = (int)$req->param('batch_id', 0);
        $teamIds = $req->param('team_ids/a', []);
        if (empty($teamIds) && $req->param('team_ids', '') !== '') {
            $raw = $req->param('team_ids', '');
            $teamIds = is_array($raw) ? $raw : explode(',', $raw);
        }
        list($ok, $msg) = BatchService::bindTeams($batchId, $teamIds);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('batch', 'bindTeams', $batchId, ['team_ids' => $teamIds]);
        return json(DefaultResponse(0, true, $msg));
    }

    /**
     * 启动批次（生成指标快照、开放答题）API（POST）。
     *
     * @return \think\response\Json 启动结果及快照摘要
     */
    public function start()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg, $data) = BatchService::start($id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('batch', 'start', $id, $data);
        return json(DefaultResponse(0, true, $msg, $data));
    }

    /**
     * 结束批次（截止答题）API（POST）。
     *
     * @return \think\response\Json 结束结果
     */
    public function finish()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg, $data) = BatchService::finish($id, true);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('batch', 'finish', $id, $data);
        return json(DefaultResponse(0, true, $msg, $data));
    }

    /**
     * 对批次执行算分（班组得分与等级）API（POST）。
     *
     * @return \think\response\Json 算分结果摘要
     */
    public function score()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg, $data) = BatchService::score($id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg, $data));
        }
        OpLogService::write('batch', 'score', $id, $data);
        return json(DefaultResponse(0, true, $msg, $data));
    }

    /**
     * 批次答题进度 API（各班组提交人数等）。
     *
     * @return \think\response\Json 进度统计数据
     */
    public function progress()
    {
        $id = (int)Request::instance()->param('id', 0);
        if (!BatchService::find($id)) {
            return json(DefaultResponse(404, false, '批次不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', BatchService::progress($id)));
    }

    /**
     * 软删除批次 API。
     *
     * @return \think\response\Json 删除结果
     */
    public function delete()
    {
        $id = (int)Request::instance()->param('id', 0);
        list($ok, $msg) = BatchService::softDelete($id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('batch', 'delete', $id, null);
        return json(DefaultResponse(0, true, $msg));
    }
}

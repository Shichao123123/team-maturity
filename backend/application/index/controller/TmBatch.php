<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\service\BatchService;
use app\index\service\OpLogService;
use think\Db;
use think\Request;

class TmBatch extends Base
{
    public function index()
    {
        $versions = Db::name('tm_indicator_version')
            ->where('status', 1)
            ->order('id desc')
            ->field('id, name, version_no')
            ->select();
        $teams = Db::name('tm_team')->alias('t')
            ->join('tm_org o', 'o.id = t.org_id', 'LEFT')
            ->whereNull('t.deleted_at')
            ->where('t.status', 1)
            ->field('t.id, t.name, t.org_id, o.name as org_name')
            ->order('t.id asc')
            ->select();
        $orgOptions = (new OrgModel())->getSelectOptions();
        $orgPathMap = [];
        foreach ($orgOptions as $o) {
            $orgPathMap[(int)$o['id']] = $o['path'];
        }
        foreach ($teams as &$t) {
            $oid = (int)$t['org_id'];
            $t['org_path'] = isset($orgPathMap[$oid]) ? $orgPathMap[$oid] : ($t['org_name'] ?: '');
        }
        unset($t);

        $this->assign('versions', $versions);
        $this->assign('teams', $teams);
        return $this->fetch('tm_batch/index');
    }

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

    public function detail()
    {
        $id = (int)Request::instance()->param('id', 0);
        $row = BatchService::detail($id);
        if (!$row) {
            return json(DefaultResponse(404, false, '批次不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', $row));
    }

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

    public function finish()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = BatchService::finish($id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('batch', 'finish', $id, null);
        return json(DefaultResponse(0, true, $msg));
    }

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

    public function progress()
    {
        $id = (int)Request::instance()->param('id', 0);
        if (!BatchService::find($id)) {
            return json(DefaultResponse(404, false, '批次不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', BatchService::progress($id)));
    }

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

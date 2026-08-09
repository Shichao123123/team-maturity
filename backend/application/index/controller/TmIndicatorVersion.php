<?php
namespace app\index\controller;

use app\index\service\IndicatorService;
use app\index\service\OpLogService;
use think\Db;
use think\Request;

class TmIndicatorVersion extends Base
{
    public function index()
    {
        return $this->fetch('tm_indicator_version/index');
    }

    public function getList()
    {
        $page = (int)Request::instance()->param('page', 1);
        $limit = (int)Request::instance()->param('limit', 15);
        $count = Db::name('tm_indicator_version')->count();
        $list = Db::name('tm_indicator_version')->order('id desc')->page($page, $limit)->select();
        $modeMap = ['first' => '取首题', 'random' => '随机抽一', 'sequential' => '轮询抽一'];
        foreach ($list as &$row) {
            $vid = (int)$row['id'];
            $row['question_cnt'] = (int)Db::name('tm_question')->where('version_id', $vid)->count();
            $row['indicator_cnt'] = (int)Db::name('tm_indicator')->where('version_id', $vid)->where('status', 1)->count();
            $row['status_text'] = [0 => '草稿', 1 => '已发布', 2 => '停用'][(int)$row['status']] ?? '';
            $l1Sum = Db::name('tm_indicator')
                ->where('version_id', $vid)
                ->where('level_no', 1)
                ->where('status', 1)
                ->sum('weight');
            $row['l1_weight_sum'] = round((float)$l1Sum, 2);
            $mode = isset($row['question_pick_mode']) ? $row['question_pick_mode'] : 'random';
            $row['pick_mode_text'] = isset($modeMap[$mode]) ? $modeMap[$mode] : $mode;
            $row['can_edit'] = (int)$row['status'] === 0 ? 1 : 0;
        }
        unset($row);
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    public function add()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        list($ok, $msg, $id) = IndicatorService::createVersion([
            'name'               => $req->param('name', '', 'trim'),
            'version_no'         => $req->param('version_no', '', 'trim'),
            'remark'             => $req->param('remark', '', 'trim'),
            'question_pick_mode' => $req->param('question_pick_mode', 'random', 'trim'),
        ]);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'add', $id, ['name' => $req->param('name', '', 'trim')]);
        return json(DefaultResponse(0, true, $msg, ['id' => $id]));
    }

    public function edit()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = IndicatorService::updateVersion($id, [
            'name'               => $req->param('name', '', 'trim'),
            'version_no'         => $req->param('version_no', '', 'trim'),
            'remark'             => $req->param('remark', '', 'trim'),
            'question_pick_mode' => $req->param('question_pick_mode', 'random', 'trim'),
        ]);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'edit', $id, null);
        return json(DefaultResponse(0, true, $msg));
    }

    public function publish()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = IndicatorService::publish($id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'publish', $id, null);
        return json(DefaultResponse(0, true, $msg));
    }

    public function disable()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = IndicatorService::disable($id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'disable', $id, null);
        return json(DefaultResponse(0, true, $msg));
    }

    public function copy()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg, $newId) = IndicatorService::copyVersion(
            $id,
            $req->param('name', '', 'trim'),
            $req->param('version_no', '', 'trim')
        );
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'copy', $newId, ['from' => $id]);
        return json(DefaultResponse(0, true, $msg, ['id' => $newId]));
    }

    /** 发布前预检 */
    public function validatePublish()
    {
        $id = (int)Request::instance()->param('id', 0);
        $errors = IndicatorService::validateForPublish($id);
        return json(DefaultResponse(0, true, empty($errors) ? '校验通过' : '存在问题', [
            'ok'     => empty($errors),
            'errors' => $errors,
        ]));
    }
}

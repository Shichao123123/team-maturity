<?php
namespace app\index\controller;

use app\index\service\BatchService;
use app\index\service\ResultService;
use think\Db;
use think\Request;

class TmResult extends Base
{
    public function index()
    {
        $batches = ResultService::listBatches();
        foreach ($batches as &$b) {
            $b['status_text'] = BatchService::statusText($b['status']);
            $b['score_status_text'] = BatchService::scoreStatusText($b['score_status']);
        }
        unset($b);
        $levels = Db::name('tm_level_threshold')
            ->where('status', 1)
            ->order('sort_no asc,id asc')
            ->field('level_code, level_name')
            ->select();
        $this->assign('batches', $batches);
        $this->assign('levels', $levels);
        return $this->fetch('tm_result/index');
    }

    public function teamList()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $keyword = $req->param('keyword', '', 'trim');
        $levelCode = $req->param('level_code', '', 'trim');
        list($ok, $msg, $data) = ResultService::teamList($batchId, $keyword, $levelCode);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        // Layui table 兼容：同时给 list 与扁平 data
        return json([
            'code'    => 0,
            'success' => true,
            'msg'     => 'ok',
            'count'   => $data['count'],
            'data'    => $data['list'],
            'batch'   => $data['batch'],
        ]);
    }

    public function teamDetail()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        list($ok, $msg, $data) = ResultService::teamDetail($batchId, $teamId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    public function paperList()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        $keyword = $req->param('keyword', '', 'trim');
        $status = $req->param('status', null);
        list($ok, $msg, $data) = ResultService::paperList($batchId, $teamId, $keyword, $status);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        return json([
            'code'    => 0,
            'success' => true,
            'msg'     => 'ok',
            'count'   => $data['count'],
            'data'    => $data['list'],
        ]);
    }

    public function paperDetail()
    {
        $paperId = (int)Request::instance()->param('paper_id', 0);
        list($ok, $msg, $data) = ResultService::paperDetail($paperId);
        if (!$ok) {
            return json(DefaultResponse(404, false, $msg));
        }
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    public function exportTeam()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg) = ResultService::exportTeam($batchId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        // ExcelHelper::download 已 exit
    }

    public function exportRank()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg) = ResultService::exportRank($batchId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }
}

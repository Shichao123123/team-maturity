<?php
namespace app\index\controller;

use app\index\service\BatchService;
use app\index\service\ResultService;
use think\Db;
use think\Request;

class TmResult extends Base
{
    /**
     * 测评结果查询页，加载批次与成熟度等级选项。
     *
     * @return mixed 渲染 tm_result/index 视图
     */
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

    /**
     * 批次下班组结果列表 API（支持关键词、等级筛选）。
     *
     * @return \think\response\Json Layui table 格式，含 count、data、batch
     */
    public function teamList()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $keyword = $req->param('keyword', '', 'trim');
        $levelCode = $req->param('level_code', '', 'trim');
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 10);
        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 10;
        }
        list($ok, $msg, $data) = ResultService::teamList($batchId, $keyword, $levelCode, $page, $limit);
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

    /**
     * 单班组测评结果详情 API（得分、等级、薄弱指标等）。
     *
     * @return \think\response\Json 班组结果完整数据
     */
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

    /**
     * 批次下班组内成员答卷列表 API（支持关键词、状态筛选）。
     *
     * @return \think\response\Json Layui table 格式的答卷列表
     */
    public function paperList()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        $keyword = $req->param('keyword', '', 'trim');
        $status = $req->param('status', null);
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 10);
        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 10;
        }
        list($ok, $msg, $data) = ResultService::paperList($batchId, $teamId, $keyword, $status, $page, $limit);
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

    /**
     * 单份答卷详情 API（含逐题作答与得分）。
     *
     * @return \think\response\Json 答卷完整明细
     */
    public function paperDetail()
    {
        $paperId = (int)Request::instance()->param('paper_id', 0);
        list($ok, $msg, $data) = ResultService::paperDetail($paperId);
        if (!$ok) {
            return json(DefaultResponse(404, false, $msg));
        }
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    /**
     * 导出批次下班组结果 Excel。
     *
     * @return void|\think\response\Json 无数据时 JSON 错误，否则直接下载
     */
    public function exportTeam()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg) = ResultService::exportTeam($batchId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        // ExcelHelper::download 已 exit
    }

    /**
     * 导出批次班组排行榜 Excel。
     *
     * @return void|\think\response\Json 无数据时 JSON 错误，否则直接下载
     */
    public function exportRank()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg) = ResultService::exportRank($batchId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }

    /**
     * 导出批次班组汇总 PDF。
     *
     * @return void|\think\response\Json
     */
    public function exportTeamPdf()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg) = ResultService::exportTeamPdf($batchId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }

    /**
     * 导出批次班组排行 PDF。
     *
     * @return void|\think\response\Json
     */
    public function exportRankPdf()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg) = ResultService::exportRankPdf($batchId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }

    /**
     * 导出班组详情 PDF。
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
     * 导出个人答卷 PDF。
     *
     * @return void|\think\response\Json
     */
    public function exportPaperPdf()
    {
        $paperId = (int)Request::instance()->param('paper_id', 0);
        list($ok, $msg) = ResultService::exportPaperPdf($paperId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }

    /**
     * 导出批次班组汇总 Word。
     *
     * @return void|\think\response\Json
     */
    public function exportTeamDocx()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg) = ResultService::exportTeamDocx($batchId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }

    /**
     * 导出批次班组排行 Word。
     *
     * @return void|\think\response\Json
     */
    public function exportRankDocx()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg) = ResultService::exportRankDocx($batchId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }

    /**
     * 导出班组详情 Word。
     *
     * @return void|\think\response\Json
     */
    public function exportTeamDetailDocx()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        list($ok, $msg) = ResultService::exportTeamDetailDocx($batchId, $teamId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }

    /**
     * 导出个人答卷 Word。
     *
     * @return void|\think\response\Json
     */
    public function exportPaperDocx()
    {
        $paperId = (int)Request::instance()->param('paper_id', 0);
        list($ok, $msg) = ResultService::exportPaperDocx($paperId);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
    }
}

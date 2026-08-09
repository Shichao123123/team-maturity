<?php
namespace app\index\controller;

use app\index\service\OpLogService;
use app\index\service\QuestionPickService;
use app\index\service\ScoreService;
use think\Db;
use think\Request;
use think\Session;

class TmMockExam extends Base
{
    const SESSION_KEY = 'tm_mock_exam';

    /**
     * 模拟做题页面，加载已发布指标版本列表。
     *
     * @return mixed 渲染 tm_mock_exam/index 视图
     */
    public function index()
    {
        $versions = Db::name('tm_indicator_version')
            ->where('status', 1)
            ->order('id desc')
            ->field('id,name,version_no')
            ->select();
        $this->assign('versions', $versions);
        return $this->fetch('tm_mock_exam/index');
    }

    /**
     * 开始模拟做题 API（POST）：按版本抽题（每三级指标 1 题）写入 Session。
     *
     * @return \think\response\Json 返回 token、题目数量及抽题模式
     */
    public function start()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $versionId = (int)$req->param('version_id', 0);
        if ($versionId <= 0) {
            $versionId = (int)Db::name('tm_indicator_version')
                ->where('status', 1)
                ->order('id desc')
                ->value('id');
        }
        $ver = Db::name('tm_indicator_version')->where('id', $versionId)->where('status', 1)->find();
        if (!$ver) {
            return json(DefaultResponse(400, false, '请选择已启用的指标版本'));
        }

        $exam = QuestionPickService::buildExamSet($versionId);
        if (empty($exam['items'])) {
            return json(DefaultResponse(400, false, '该版本下没有可抽题目'));
        }

        $token = md5(uniqid('mock', true));
        $paper = [
            'token'       => $token,
            'version_id'  => $versionId,
            'version_name'=> $ver['name'],
            'pick_mode'   => $exam['pick_mode'],
            'started_at'  => tmNow(),
            'items'       => $exam['items'],
        ];
        Session::set(self::SESSION_KEY, $paper);
        OpLogService::write('mock', 'start', $versionId, [
            'question_cnt' => count($exam['items']),
            'pick_mode'    => $exam['pick_mode'],
        ]);

        return json(DefaultResponse(0, true, '已生成模拟试卷', [
            'token'        => $token,
            'version_id'   => $versionId,
            'version_name' => $ver['name'],
            'question_cnt' => count($exam['items']),
            'pick_mode'    => $exam['pick_mode'],
        ]));
    }

    /**
     * 获取当前模拟试卷 API（选项不含得分比例，防作弊）。
     *
     * @return \think\response\Json 返回题目列表及版本信息
     */
    public function paper()
    {
        $paper = Session::get(self::SESSION_KEY);
        if (!$paper || empty($paper['items'])) {
            return json(DefaultResponse(404, false, '请先开始模拟做题'));
        }
        $token = Request::instance()->param('token', '', 'trim');
        if ($token !== '' && $token !== $paper['token']) {
            return json(DefaultResponse(400, false, '试卷已失效，请重新开始'));
        }

        $questions = [];
        foreach ($paper['items'] as $idx => $item) {
            $opts = [];
            foreach ($item['options'] as $opt) {
                $opts[] = [
                    'id'      => (int)$opt['id'],
                    'content' => $opt['content'],
                    'sort_no' => (int)$opt['sort_no'],
                ];
            }
            $questions[] = [
                'seq'            => $idx + 1,
                'indicator_id'   => (int)$item['indicator']['id'],
                'indicator_name' => $item['indicator']['name'],
                'weight'         => (float)$item['indicator']['weight'],
                'question_id'    => (int)$item['question']['id'],
                'title'          => $item['question']['title'],
                'options'        => $opts,
            ];
        }

        return json(DefaultResponse(0, true, 'ok', [
            'token'        => $paper['token'],
            'version_id'   => $paper['version_id'],
            'version_name' => $paper['version_name'],
            'pick_mode'    => $paper['pick_mode'],
            'started_at'   => $paper['started_at'],
            'question_cnt' => count($questions),
            'questions'    => $questions,
        ]));
    }

    /**
     * 提交模拟答卷并算分判级 API（POST，不落库，仅 Session 模拟）。
     *
     * @return \think\response\Json 返回个人得分、等级及答题明细
     */
    public function submit()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $paper = Session::get(self::SESSION_KEY);
        if (!$paper || empty($paper['items'])) {
            return json(DefaultResponse(404, false, '请先开始模拟做题'));
        }
        $token = $req->param('token', '', 'trim');
        if ($token !== '' && $token !== $paper['token']) {
            return json(DefaultResponse(400, false, '试卷已失效，请重新开始'));
        }

        $raw = $req->param('answers');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $answers = [];
        foreach ($raw as $qid => $oid) {
            $answers[(int)$qid] = (int)$oid;
        }

        $result = ScoreService::scorePersonal($paper['items'], $answers);
        $result['version_id'] = $paper['version_id'];
        $result['version_name'] = $paper['version_name'];
        $result['pick_mode'] = $paper['pick_mode'];
        $result['is_mock'] = true;

        OpLogService::write('mock', 'submit', $paper['version_id'], [
            'total_score' => $result['total_score'],
            'level'       => $result['level'] ? $result['level']['level_code'] : null,
            'answered'    => $result['answered_cnt'],
        ]);

        // 交卷后清掉试卷，避免重复提交；结果随响应返回
        Session::delete(self::SESSION_KEY);

        return json(DefaultResponse(0, true, '提交成功', $result));
    }

    /**
     * 清空当前 Session 中的模拟试卷 API。
     *
     * @return \think\response\Json 操作结果
     */
    public function clear()
    {
        Session::delete(self::SESSION_KEY);
        return json(DefaultResponse(0, true, '已清空模拟试卷'));
    }
}

<?php
namespace app\index\service;

use think\Db;

/**
 * 小程序正式测评答卷：读批次快照拉题、保存答案、提交个人分、查结果。
 */
class MiniPaperService
{
    /**
     * 本地联调 / 测试账号：dev_login=true 时可重复测评，并放宽批次结束限制。
     *
     * @return bool
     */
    public static function allowRetake()
    {
        $cfg = config('mini');
        return !empty($cfg['dev_login']);
    }

    /**
     * 扫码进场分流：已做过题则无需再选班组填姓名。
     *
     * action:
     * - register  需登记
     * - continue  继续未提交答卷
     * - result    已提交，看个人结果
     * - home      批次已结束且仅有未完成答卷，回首页看历史
     * - blocked   批次不可进且无历史
     *
     * @param int $wxUserId
     * @param int $batchId
     * @return array [bool ok, string msg, array|null data, int code]
     */
    public static function entryStatus($wxUserId, $batchId)
    {
        $wxUserId = (int)$wxUserId;
        $batchId = (int)$batchId;
        if ($batchId <= 0) {
            return [false, '缺少 batch_id', null, 201];
        }
        $batch = Db::name('tm_batch')->where('id', $batchId)->whereNull('deleted_at')->find();
        if (!$batch) {
            return [false, '测评批次不存在', null, 201];
        }

        $paper = self::findUserPaper($wxUserId, $batchId);
        list($openOk, $openMsg) = self::assertBatchOpen($batchId);
        $member = null;
        if (!empty($paper['member_id'])) {
            $member = Db::name('tm_member')->where('id', (int)$paper['member_id'])->field('id,name,team_id')->find();
        } elseif (!empty($wxUserId)) {
            $mid = (int)Db::name('tm_wx_user')->where('id', $wxUserId)->value('member_id');
            if ($mid > 0) {
                $member = Db::name('tm_member')->where('id', $mid)->field('id,name,team_id')->find();
            }
        }
        $teamName = '';
        $teamId = $paper ? (int)$paper['team_id'] : ($member ? (int)$member['team_id'] : 0);
        if ($teamId > 0) {
            $teamName = (string)Db::name('tm_team')->where('id', $teamId)->value('name');
        }

        $base = [
            'batch_id'     => $batchId,
            'batch_name'   => $batch['name'],
            'batch_status' => (int)$batch['status'],
            'can_retake'   => self::allowRetake(),
            'has_paper'    => (bool)$paper,
            'paper_id'     => $paper ? (int)$paper['id'] : 0,
            'paper_status' => $paper ? (int)$paper['status'] : null,
            'team_id'      => $teamId,
            'team_name'    => $teamName,
            'member_id'    => $member ? (int)$member['id'] : 0,
            'member_name'  => $member ? (string)$member['name'] : '',
        ];

        if ($paper) {
            if ((int)$paper['status'] === 1) {
                $base['action'] = 'result';
                return [true, '已提交，可查看个人结果', $base, 200];
            }
            if ($openOk || self::allowRetake()) {
                $base['action'] = 'continue';
                return [true, '继续答题', $base, 200];
            }
            // 批次结束但有未完成答卷：回首页看历史，不必再登记
            $base['action'] = 'home';
            return [true, '测评已结束，可在首页查看历史', $base, 200];
        }

        // 测试模式：批次结束后仍可新开答卷
        if (!$openOk && !self::allowRetake()) {
            $base['action'] = 'blocked';
            $base['teams'] = [];
            return [true, $openMsg ?: '当前不可进入测评', $base, 200];
        }

        $base['action'] = 'register';
        $teams = MemberSelfRegisterService::listBatchTeams($batchId);
        $base['teams'] = $teams;
        $base['team_tree'] = MemberSelfRegisterService::listBatchTeamTree($batchId, $teams);
        return [true, 'ok', $base, 200];
    }

    /**
     * 测试账号重置答卷以便重复测评（仅 dev_login）。
     *
     * @param int $wxUserId
     * @param int $batchId
     * @return array [bool ok, string msg, array|null data, int code]
     */
    public static function resetPaper($wxUserId, $batchId)
    {
        if (!self::allowRetake()) {
            return [false, '当前环境不允许重复测评', null, 403];
        }
        $batchId = (int)$batchId;
        $paper = self::findUserPaper((int)$wxUserId, $batchId);
        if (!$paper) {
            return [false, '尚未开始本批次测评', null, 201];
        }
        list($ok, $msg) = self::assertBatchOpen($batchId);
        if (!$ok) {
            return [false, $msg, null, self::denyCode($msg)];
        }

        $wasSubmitted = ((int)$paper['status'] === 1);
        $now = tmNow();
        Db::startTrans();
        try {
            Db::name('tm_paper_answer')->where('paper_id', (int)$paper['id'])->delete();
            Db::name('tm_paper')->where('id', (int)$paper['id'])->update([
                'status'         => 0,
                'personal_score' => null,
                'submitted_at'   => null,
                'updated_at'     => $now,
            ]);
            if ($wasSubmitted) {
                $cnt = (int)Db::name('tm_batch_team')
                    ->where('batch_id', (int)$paper['batch_id'])
                    ->where('team_id', (int)$paper['team_id'])
                    ->value('submitted_cnt');
                if ($cnt > 0) {
                    Db::name('tm_batch_team')
                        ->where('batch_id', (int)$paper['batch_id'])
                        ->where('team_id', (int)$paper['team_id'])
                        ->setDec('submitted_cnt', 1);
                }
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return [false, '重置失败：' . $e->getMessage(), null, 201];
        }

        $paper = Db::name('tm_paper')->where('id', (int)$paper['id'])->find();
        list(, , $items) = self::loadSnapItems($batchId);
        return [true, '已重置，可重新作答', self::buildPaperPayload($paper, $items ?: []), 200];
    }

    /**
     * 开始或继续答题：确保成员与答卷，返回快照题目（不含权重/得分比例）。
     *
     * @param int    $wxUserId
     * @param int    $batchId
     * @param int    $teamId
     * @param string $name
     * @param int    $memberId  JWT 中已有则优先
     * @return array [bool ok, string msg, array|null data, int code]
     */
    public static function startPaper($wxUserId, $batchId, $teamId, $name, $memberId = 0)
    {
        $wxUserId = (int)$wxUserId;
        $batchId = (int)$batchId;
        $teamId = (int)$teamId;
        $memberId = (int)$memberId;
        $name = trim((string)$name);

        // 已有答卷：直接进入，无需再次选班组填姓名（批次结束也可看已提交卷）
        $existing = self::findUserPaper($wxUserId, $batchId);
        if ($existing) {
            list($ok, $msg, $items) = self::loadSnapItems($batchId);
            if (!$ok) {
                return [false, $msg, null, 201];
            }
            if (empty($existing['wx_user_id'])) {
                Db::name('tm_paper')->where('id', $existing['id'])->update([
                    'wx_user_id' => $wxUserId,
                    'updated_at' => tmNow(),
                ]);
                $existing['wx_user_id'] = $wxUserId;
            }
            $payload = self::buildPaperPayload($existing, $items);
            $payload['can_retake'] = self::allowRetake();
            if ((int)$existing['status'] === 1) {
                return [true, '答卷已提交，仅可查看', $payload, 202];
            }
            list($openOk, $openMsg) = self::assertBatchOpen($batchId);
            if (!$openOk) {
                return [false, '测评已结束，可在首页查看历史记录', [
                    'action'   => 'home',
                    'batch_id' => $batchId,
                    'paper_id' => (int)$existing['id'],
                ], 403];
            }
            return [true, 'ok', $payload, 200];
        }

        // 继续答题：允许只带 member_id，从档案补全班组与姓名
        if (($teamId <= 0 || $name === '') && $memberId <= 0) {
            $memberId = (int)Db::name('tm_wx_user')->where('id', $wxUserId)->value('member_id');
        }
        if ($memberId > 0 && ($teamId <= 0 || $name === '')) {
            $m = Db::name('tm_member')->where('id', $memberId)->whereNull('deleted_at')->find();
            if ($m) {
                if ($teamId <= 0) {
                    $teamId = (int)$m['team_id'];
                }
                if ($name === '') {
                    $name = trim((string)$m['name']);
                }
            }
        }

        list($ok, $msg, $batch) = self::assertBatchTeamForExam($batchId, $teamId);
        if (!$ok) {
            $code = self::denyCode($msg);
            return [false, $msg, null, $code];
        }

        list($ok, $msg, $items) = self::loadSnapItems($batchId);
        if (!$ok) {
            return [false, $msg, null, 201];
        }

        if ($name === '') {
            return [false, '请填写姓名', null, 201];
        }

        try {
            $member = MemberSelfRegisterService::ensureMember($wxUserId, $teamId, $name, $batchId);
            $memberId = (int)$member['id'];
            $teamId = (int)$member['team_id'];
        } catch (\Exception $e) {
            return [false, $e->getMessage(), null, 201];
        }

        $now = tmNow();
        $paper = Db::name('tm_paper')
            ->where('batch_id', $batchId)
            ->where('member_id', $memberId)
            ->find();

        if (!$paper) {
            $paperId = (int)Db::name('tm_paper')->insertGetId([
                'batch_id'       => $batchId,
                'team_id'        => $teamId,
                'member_id'      => $memberId,
                'wx_user_id'     => $wxUserId,
                'status'         => 0,
                'personal_score' => null,
                'submitted_at'   => null,
                'created_at'     => $now,
            ]);
            $paper = Db::name('tm_paper')->where('id', $paperId)->find();
        } else {
            if (empty($paper['wx_user_id'])) {
                Db::name('tm_paper')->where('id', $paper['id'])->update([
                    'wx_user_id' => $wxUserId,
                    'updated_at' => $now,
                ]);
                $paper['wx_user_id'] = $wxUserId;
            }
        }

        $payload = self::buildPaperPayload($paper, $items);
        $payload['can_retake'] = self::allowRetake();
        $code = ((int)$paper['status'] === 1) ? 202 : 200;
        $tip = $code === 202 ? '答卷已提交，仅可查看' : 'ok';
        return [true, $tip, $payload, $code];
    }

    /**
     * 保存单题答案（upsert）。
     *
     * @param int $wxUserId
     * @param int $paperId
     * @param int $questionId
     * @param int $optionId
     * @return array [bool ok, string msg, array|null data, int code]
     */
    public static function answer($wxUserId, $paperId, $questionId, $optionId)
    {
        $paper = self::ownedPaper((int)$paperId, (int)$wxUserId);
        if (!$paper) {
            return [false, '答卷不存在或无权操作', null, 403];
        }
        if ((int)$paper['status'] === 1) {
            return [false, '答卷已提交，不能修改', self::progressOnly($paper), 202];
        }

        // 答题热路径：只做轻量开测校验，避免每题重复查班组绑定
        list($ok, $msg) = self::assertBatchOpen((int)$paper['batch_id']);
        if (!$ok) {
            return [false, $msg, null, self::denyCode($msg)];
        }

        list($ok, $msg, $items) = self::loadSnapItems((int)$paper['batch_id']);
        if (!$ok) {
            return [false, $msg, null, 201];
        }

        $questionId = (int)$questionId;
        $optionId = (int)$optionId;
        $hit = self::findSnapOption($items, $questionId, $optionId, (int)$paper['batch_id']);
        if (!$hit) {
            return [false, '题目或选项不在本批次试卷中', null, 201];
        }

        $weight = (float)$hit['indicator']['weight'];
        $ratio = (float)$hit['option']['score_ratio'];
        $itemScore = round($weight * $ratio, 4);
        $now = tmNow();
        $exist = Db::name('tm_paper_answer')
            ->where('paper_id', (int)$paper['id'])
            ->where('question_id', $questionId)
            ->find();

        $row = [
            'option_id'    => $optionId,
            'indicator_id' => (int)$hit['indicator']['id'],
            'weight'       => $weight,
            'score_ratio'  => $ratio,
            'item_score'   => $itemScore,
            'answered_at'  => $now,
        ];
        if ($exist) {
            Db::name('tm_paper_answer')->where('id', $exist['id'])->update($row);
        } else {
            $row['paper_id'] = (int)$paper['id'];
            $row['question_id'] = $questionId;
            Db::name('tm_paper_answer')->insert($row);
        }

        // 热路径不再更新 paper.updated_at，减少一次写库
        $answered = (int)Db::name('tm_paper_answer')->where('paper_id', (int)$paper['id'])->count();
        return [true, 'ok', [
            'paper_id' => (int)$paper['id'],
            'answered' => $answered,
            'total'    => count($items),
        ], 200];
    }

    /**
     * 提交答卷：须全答；锁定并写个人分；幂等返回 202。
     *
     * @param int $wxUserId
     * @param int $paperId
     * @return array [bool ok, string msg, array|null data, int code]
     */
    public static function submit($wxUserId, $paperId)
    {
        $paper = self::ownedPaper((int)$paperId, (int)$wxUserId);
        if (!$paper) {
            return [false, '答卷不存在或无权操作', null, 403];
        }

        list($ok, $msg, $items) = self::loadSnapItems((int)$paper['batch_id']);
        if (!$ok) {
            return [false, $msg, null, 201];
        }
        $total = count($items);

        if ((int)$paper['status'] === 1) {
            return [true, '答卷已提交', self::submitResultPayload($paper, $items), 202];
        }

        // 与答题一致：用轻量开测校验（dev_login 下允许已结束批次提交）
        // 勿直接调 assertBatchTeam，否则测试账号会卡在「测评已结束」
        list($ok, $msg) = self::assertBatchOpen((int)$paper['batch_id']);
        if (!$ok) {
            return [false, $msg, null, self::denyCode($msg)];
        }

        $answers = Db::name('tm_paper_answer')->where('paper_id', (int)$paper['id'])->select();
        $answerMap = [];
        foreach ($answers as $a) {
            $answerMap[(int)$a['question_id']] = $a;
        }
        $missing = 0;
        foreach ($items as $item) {
            $qid = (int)$item['question']['id'];
            if (!isset($answerMap[$qid])) {
                $missing++;
            }
        }
        if ($missing > 0) {
            return [false, '还有 ' . $missing . ' 题未作答，请答完再提交', [
                'answered' => $total - $missing,
                'total'    => $total,
            ], 201];
        }

        $sum = 0.0;
        foreach ($answerMap as $a) {
            $sum += (float)$a['item_score'];
        }
        $personalScore = round($sum, 2);
        $now = tmNow();

        Db::startTrans();
        try {
            $locked = Db::name('tm_paper')
                ->where('id', (int)$paper['id'])
                ->where('status', 0)
                ->update([
                    'status'         => 1,
                    'personal_score' => $personalScore,
                    'submitted_at'   => $now,
                    'updated_at'     => $now,
                ]);
            if (!$locked) {
                // 并发下已被提交
                Db::rollback();
                $paper = Db::name('tm_paper')->where('id', (int)$paper['id'])->find();
                return [true, '答卷已提交', self::submitResultPayload($paper, $items), 202];
            }
            // 幂等：仅首次提交增加班组已交人数
            Db::name('tm_batch_team')
                ->where('batch_id', (int)$paper['batch_id'])
                ->where('team_id', (int)$paper['team_id'])
                ->setInc('submitted_cnt', 1);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return [false, '提交失败：' . $e->getMessage(), null, 201];
        }

        $paper = Db::name('tm_paper')->where('id', (int)$paper['id'])->find();
        return [true, '提交成功', self::submitResultPayload($paper, $items), 200];
    }

    /**
     * 我的测评结果（个人分 + 班组结果/措施）。
     *
     * @param int $wxUserId
     * @param int $batchId
     * @return array [bool ok, string msg, array|null data, int code]
     */
    public static function myResult($wxUserId, $batchId)
    {
        $batchId = (int)$batchId;
        if ($batchId <= 0) {
            return [false, '缺少 batch_id', null, 201];
        }
        $paper = self::findUserPaper($wxUserId, $batchId);
        if (!$paper) {
            return [false, '尚未开始本批次测评', null, 201];
        }
        if ((int)$paper['status'] !== 1) {
            return [false, '答卷尚未提交', [
                'paper_id' => (int)$paper['id'],
                'status'   => 0,
            ], 201];
        }
        return [true, 'ok', self::resultPayload($paper), 200];
    }

    /**
     * 当前用户相关批次列表（有答卷的批次）。
     *
     * @param int $wxUserId
     * @return array [bool ok, string msg, array data, int code]
     */
    public static function myBatches($wxUserId)
    {
        $wxUserId = (int)$wxUserId;
        $memberId = (int)Db::name('tm_wx_user')->where('id', $wxUserId)->value('member_id');
        $q = Db::name('tm_paper')->alias('p')
            ->join('tm_batch b', 'b.id = p.batch_id')
            ->join('tm_team t', 't.id = p.team_id', 'LEFT')
            ->whereNull('b.deleted_at')
            ->field('p.id as paper_id, p.batch_id, p.team_id, p.status, p.personal_score, p.submitted_at,
                b.name as batch_name, b.start_time, b.end_time, b.status as batch_status,
                t.name as team_name');
        if ($memberId > 0) {
            $q->where(function ($query) use ($wxUserId, $memberId) {
                $query->where('p.wx_user_id', $wxUserId)->whereOr('p.member_id', $memberId);
            });
        } else {
            $q->where('p.wx_user_id', $wxUserId);
        }
        $rows = $q->order('p.id desc')->select();
        $list = [];
        $seen = [];
        foreach ($rows as $r) {
            $bid = (int)$r['batch_id'];
            if (isset($seen[$bid])) {
                continue;
            }
            $seen[$bid] = 1;
            $status = (int)$r['status'];
            $batchStatus = (int)$r['batch_status'];
            $deadlineOk = empty($r['end_time']) || $r['end_time'] >= tmNow();
            // 与 assertBatchOpen 对齐；测试模式可忽略截止/结束
            $canContinue = ($status !== 1) && (
                self::allowRetake()
                || ($batchStatus === 1 && $deadlineOk)
            );
            $list[] = [
                'batch_id'          => $bid,
                'name'              => $r['batch_name'],
                'start_time'        => $r['start_time'],
                'end_time'          => $r['end_time'],
                'status'            => $batchStatus,
                'team_id'           => (int)$r['team_id'],
                'team_name'         => $r['team_name'] ?: '',
                'paper_id'          => (int)$r['paper_id'],
                'paper_status'      => $status,
                'paper_status_text' => $status === 1 ? '已提交' : '答题中',
                'personal_score'    => $status === 1 ? $r['personal_score'] : null,
                'can_continue'      => $canContinue,
                'can_retake'        => self::allowRetake() && $status === 1,
            ];
        }
        return [true, 'ok', $list, 200];
    }

    /**
     * 批次详情：批次信息 + 本人答卷/个人结果（不含班组成绩）。
     *
     * @param int $wxUserId
     * @param int $batchId
     * @return array [bool ok, string msg, array|null data, int code]
     */
    public static function batchDetail($wxUserId, $batchId)
    {
        $batchId = (int)$batchId;
        $batch = BatchService::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null, 201];
        }
        $paper = self::findUserPaper($wxUserId, $batchId);
        $data = [
            'batch' => [
                'batch_id'   => (int)$batch['id'],
                'name'       => $batch['name'],
                'start_time' => $batch['start_time'],
                'end_time'   => $batch['end_time'],
                'status'     => (int)$batch['status'],
                'status_text'=> BatchService::statusText($batch['status']),
            ],
            'paper'  => null,
            'result' => null,
        ];
        if ($paper) {
            $data['paper'] = [
                'paper_id'       => (int)$paper['id'],
                'team_id'        => (int)$paper['team_id'],
                'status'         => (int)$paper['status'],
                'personal_score' => $paper['personal_score'],
                'submitted_at'   => $paper['submitted_at'],
            ];
            if ((int)$paper['status'] === 1) {
                $data['result'] = self::resultPayload($paper);
            }
        }
        return [true, 'ok', $data, 200];
    }

    /**
     * @param int $batchId
     * @return array [bool ok, string msg, array items]
     */
    /** @var array 请求内快照缓存 batchId => [ok, msg, items] */
    private static $snapCache = [];

    /** @var array 请求内选项索引 batchId => [questionId => [optionId => hit]] */
    private static $optionIndex = [];

    public static function loadSnapItems($batchId)
    {
        $batchId = (int)$batchId;
        if (isset(self::$snapCache[$batchId])) {
            return self::$snapCache[$batchId];
        }

        $cacheDir = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'snap-cache';
        $snapId = (int)Db::name('tm_batch_indicator_snap')->where('batch_id', $batchId)->value('id');
        if ($snapId <= 0) {
            return self::$snapCache[$batchId] = [false, '本批次尚未生成试卷快照，请联系管理员开测', []];
        }

        // 跨请求文件缓存：命中后不再拉取/解析 MEDIUMTEXT
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'b' . $batchId . '_' . $snapId . '.json';
        $items = null;
        if (is_file($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['items']) && is_array($cached['items'])) {
                $items = $cached['items'];
            }
        }
        if ($items === null) {
            $snapJson = Db::name('tm_batch_indicator_snap')->where('id', $snapId)->value('snap_json');
            if ($snapJson === null || $snapJson === '') {
                return self::$snapCache[$batchId] = [false, '本批次尚未生成试卷快照，请联系管理员开测', []];
            }
            $json = json_decode($snapJson, true);
            if (!is_array($json) || empty($json['items']) || !is_array($json['items'])) {
                return self::$snapCache[$batchId] = [false, '试卷快照无效，请联系管理员', []];
            }
            $items = $json['items'];
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0777, true);
            }
            @file_put_contents($cacheFile, json_encode(['items' => $items], JSON_UNESCAPED_UNICODE));
        }

        self::buildOptionIndex($batchId, $items);
        return self::$snapCache[$batchId] = [true, 'ok', $items];
    }

    /**
     * @param int   $batchId
     * @param array $items
     * @return void
     */
    private static function buildOptionIndex($batchId, array $items)
    {
        $map = [];
        foreach ($items as $item) {
            $qid = (int)$item['question']['id'];
            $map[$qid] = [];
            foreach ($item['options'] as $opt) {
                $map[$qid][(int)$opt['id']] = [
                    'indicator' => $item['indicator'],
                    'question'  => $item['question'],
                    'option'    => $opt,
                ];
            }
        }
        self::$optionIndex[(int)$batchId] = $map;
    }

    /**
     * @param array      $paper
     * @param array|null $items
     * @return array
     */
    private static function buildPaperPayload(array $paper, array $items = null)
    {
        if ($items === null) {
            list($ok, $msg, $items) = self::loadSnapItems((int)$paper['batch_id']);
            if (!$ok) {
                $items = [];
            }
        }
        $selected = Db::name('tm_paper_answer')
            ->where('paper_id', (int)$paper['id'])
            ->column('option_id', 'question_id');

        $questions = [];
        $answered = 0;
        foreach ($items as $idx => $item) {
            $qid = (int)$item['question']['id'];
            $sel = isset($selected[$qid]) ? (int)$selected[$qid] : null;
            if ($sel) {
                $answered++;
            }
            $opts = [];
            foreach ($item['options'] as $opt) {
                $opts[] = [
                    'option_id' => (int)$opt['id'],
                    'content'   => $opt['content'],
                    'sort_no'   => isset($opt['sort_no']) ? (int)$opt['sort_no'] : 0,
                ];
            }
            $questions[] = [
                'question_id'        => $qid,
                'indicator_id'       => (int)$item['indicator']['id'],
                'indicator_name'     => $item['indicator']['name'],
                'title'              => $item['question']['title'],
                'remark'             => isset($item['question']['remark']) ? $item['question']['remark'] : '',
                'sort_no'            => $idx + 1,
                'selected_option_id' => $sel,
                'options'            => $opts,
            ];
        }

        return [
            'paper_id' => (int)$paper['id'],
            'batch_id' => (int)$paper['batch_id'],
            'team_id'  => (int)$paper['team_id'],
            'status'   => (int)$paper['status'],
            'total'    => count($questions),
            'answered' => $answered,
            'questions'=> $questions,
        ];
    }

    /**
     * @param array      $paper
     * @param array|null $items
     * @return array
     */
    private static function progressOnly(array $paper, array $items = null)
    {
        if ($items === null) {
            list($ok, , $items) = self::loadSnapItems((int)$paper['batch_id']);
            if (!$ok) {
                $items = [];
            }
        }
        $answered = (int)Db::name('tm_paper_answer')->where('paper_id', (int)$paper['id'])->count();
        return [
            'paper_id' => (int)$paper['id'],
            'answered' => $answered,
            'total'    => count($items),
        ];
    }

    /**
     * @param array $paper
     * @param array $items
     * @return array
     */
    private static function submitResultPayload(array $paper, array $items)
    {
        $result = self::resultPayload($paper);
        $result['paper_id'] = (int)$paper['id'];
        $result['total'] = count($items);
        $result['answered'] = count($items);
        return $result;
    }

    /**
     * 批次仍可作答的轻量校验（热路径用）。
     * 测试账号（dev_login）下允许已结束批次继续作答/重置。
     *
     * @param int $batchId
     * @return array [bool ok, string msg]
     */
    private static function assertBatchOpen($batchId)
    {
        $batch = Db::name('tm_batch')
            ->where('id', (int)$batchId)
            ->whereNull('deleted_at')
            ->field('id,status,end_time')
            ->find();
        if (!$batch) {
            return [false, '测评批次不存在'];
        }
        if ((int)$batch['status'] === 0) {
            return [false, '测评尚未开测，请等待管理员开始'];
        }
        // 本地测试：已开测或已结束都可继续反复测
        if (self::allowRetake()) {
            return [true, 'ok'];
        }
        if ((int)$batch['status'] === 2) {
            return [false, '测评已结束'];
        }
        if ((int)$batch['status'] !== 1) {
            return [false, '当前批次不可作答'];
        }
        if (!empty($batch['end_time']) && $batch['end_time'] < tmNow()) {
            // 到期自动结束并算分（与 PC「结束并自动算分」一致）
            BatchService::finishIfExpired($batchId);
            return [false, '测评已超过截止时间'];
        }
        return [true, 'ok'];
    }

    /**
     * 新登记进场校验；测试账号可在批次结束后仍登记作答。
     *
     * @param int $batchId
     * @param int $teamId
     * @return array [bool ok, string msg, array|null batch]
     */
    private static function assertBatchTeamForExam($batchId, $teamId)
    {
        list($ok, $msg, $batch) = MemberSelfRegisterService::assertBatchTeam($batchId, $teamId);
        if ($ok || !self::allowRetake() || !$batch) {
            return [$ok, $msg, $batch];
        }
        // 测试环境：批次已结束时仍允许进入（须班组绑定有效）
        if ((int)$batch['status'] === 0) {
            return [false, $msg, $batch];
        }
        $bind = Db::name('tm_batch_team')
            ->where('batch_id', (int)$batchId)
            ->where('team_id', (int)$teamId)
            ->find();
        if (!$bind) {
            return [false, '所选班组不在本批次参评范围', $batch];
        }
        $team = Db::name('tm_team')
            ->where('id', (int)$teamId)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->find();
        if (!$team) {
            return [false, '班组不存在或已停用', $batch];
        }
        return [true, 'ok', $batch];
    }

    /**
     * @param array $paper
     * @return array
     */
    private static function resultPayload(array $paper)
    {
        $batch = Db::name('tm_batch')->where('id', (int)$paper['batch_id'])->field('id,name')->find();
        $team = Db::name('tm_team')->where('id', (int)$paper['team_id'])->field('id,name')->find();
        $member = Db::name('tm_member')->where('id', (int)$paper['member_id'])->field('id,name')->find();
        $levelNames = Db::name('tm_level_threshold')->column('level_name', 'level_code');

        $personalLevel = null;
        if ($paper['personal_score'] !== null && $paper['personal_score'] !== '') {
            $personalLevel = LevelJudgeService::judge($paper['personal_score']);
        }
        $personalCode = $personalLevel ? (string)$personalLevel['level_code'] : '';

        list($answers, $personalWeak) = self::buildPersonalAnswers($paper);

        // 个人结果页：措施与薄弱项均按个人，不下发班组成绩
        $measures = [];
        if ($personalCode !== '') {
            $measureRows = Db::name('tm_empower_measure')
                ->where('level_code', $personalCode)
                ->where('status', 1)
                ->where(function ($q) use ($personalWeak) {
                    $q->whereNull('indicator_id');
                    $ids = [];
                    foreach ($personalWeak as $w) {
                        if (!empty($w['indicator_id'])) {
                            $ids[] = (int)$w['indicator_id'];
                        }
                    }
                    if ($ids) {
                        $q->whereOr('indicator_id', 'in', $ids);
                    }
                })
                ->order('sort_no asc,id asc')
                ->limit(20)
                ->select();
            foreach ($measureRows as $m) {
                if ($m['content'] === '暂无') {
                    continue;
                }
                $measures[] = [
                    'title'   => $m['title'],
                    'content' => $m['content'],
                ];
            }
        }

        return [
            'paper_id'       => (int)$paper['id'],
            'batch_id'       => (int)$paper['batch_id'],
            'batch_name'     => $batch ? $batch['name'] : '',
            'member_name'    => $member ? $member['name'] : '',
            'personal_score' => $paper['personal_score'],
            'submitted_at'   => $paper['submitted_at'],
            'personal'       => [
                'score'        => $paper['personal_score'],
                'level_code'   => $personalCode !== '' ? $personalCode : null,
                'level_name'   => ($personalCode && isset($levelNames[$personalCode])) ? $levelNames[$personalCode] : null,
                'feature_desc' => $personalLevel ? (string)$personalLevel['feature_desc'] : '',
                'question_cnt' => count($answers),
                'full_cnt'     => count(array_filter($answers, function ($a) {
                    return !empty($a['is_full']);
                })),
            ],
            'team'           => [
                'team_id'   => (int)$paper['team_id'],
                'team_name' => $team ? $team['name'] : '',
            ],
            'measures'        => $measures,
            'personal_weak'   => $personalWeak,
            'answers'         => $answers,
            'can_retake'      => self::allowRetake(),
        ];
    }

    /**
     * 组装个人答题明细与薄弱指标（不含选项得分比例，仅展示结果）。
     *
     * @param array $paper
     * @return array [answers, personalWeak]
     */
    private static function buildPersonalAnswers(array $paper)
    {
        list($ok, , $items) = self::loadSnapItems((int)$paper['batch_id']);
        if (!$ok) {
            $items = [];
        }
        $answerRows = Db::name('tm_paper_answer')
            ->where('paper_id', (int)$paper['id'])
            ->select();
        $answerMap = [];
        foreach ($answerRows as $row) {
            $answerMap[(int)$row['question_id']] = $row;
        }

        $answers = [];
        $weakMap = [];
        foreach ($items as $idx => $item) {
            $qid = (int)$item['question']['id'];
            $indId = (int)$item['indicator']['id'];
            $indName = $item['indicator']['name'];
            $weight = (float)$item['indicator']['weight'];
            $a = isset($answerMap[$qid]) ? $answerMap[$qid] : null;
            $optContent = '未作答';
            $itemScore = null;
            $isFull = 0;
            if ($a) {
                $optId = (int)$a['option_id'];
                foreach ($item['options'] as $opt) {
                    if ((int)$opt['id'] === $optId) {
                        $optContent = $opt['content'];
                        break;
                    }
                }
                $itemScore = round((float)$a['item_score'], 2);
                $w = (float)$a['weight'];
                if ($w <= 0) {
                    $w = $weight;
                }
                $isFull = ($itemScore + 0.0001 >= $w) ? 1 : 0;
                if (!$isFull) {
                    if (!isset($weakMap[$indId])) {
                        $weakMap[$indId] = [
                            'indicator_id' => $indId,
                            'name'         => $indName,
                            'avg_score'    => $itemScore,
                            'full_score'   => round($w, 2),
                        ];
                    } else {
                        // 同指标多题时取平均（通常一指标一题）
                        $weakMap[$indId]['avg_score'] = round(
                            ((float)$weakMap[$indId]['avg_score'] + $itemScore) / 2,
                            2
                        );
                    }
                }
            }
            $answers[] = [
                'sort_no'         => $idx + 1,
                'question_id'     => $qid,
                'indicator_id'    => $indId,
                'indicator_name'  => $indName,
                'title'           => $item['question']['title'],
                'option_content'  => $optContent,
                'item_score'      => $itemScore,
                'full_score'      => round($weight, 2),
                'is_full'         => $isFull,
                'is_answered'     => $a ? 1 : 0,
            ];
        }

        $personalWeak = array_values($weakMap);
        usort($personalWeak, function ($a, $b) {
            $da = $a['full_score'] - $a['avg_score'];
            $db = $b['full_score'] - $b['avg_score'];
            if ($da == $db) {
                return 0;
            }
            return ($da > $db) ? -1 : 1;
        });

        return [$answers, $personalWeak];
    }

    /**
     * @param array $items
     * @param int   $questionId
     * @param int   $optionId
     * @return array|null {indicator, question, option}
     */
    private static function findSnapOption(array $items, $questionId, $optionId, $batchId = 0)
    {
        $questionId = (int)$questionId;
        $optionId = (int)$optionId;
        $batchId = (int)$batchId;
        if ($batchId > 0 && isset(self::$optionIndex[$batchId][$questionId][$optionId])) {
            return self::$optionIndex[$batchId][$questionId][$optionId];
        }
        foreach ($items as $item) {
            if ((int)$item['question']['id'] !== $questionId) {
                continue;
            }
            foreach ($item['options'] as $opt) {
                if ((int)$opt['id'] === $optionId) {
                    return [
                        'indicator' => $item['indicator'],
                        'question'  => $item['question'],
                        'option'    => $opt,
                    ];
                }
            }
            return null;
        }
        return null;
    }

    /**
     * @param int $paperId
     * @param int $wxUserId
     * @return array|null
     */
    private static function ownedPaper($paperId, $wxUserId)
    {
        $paper = Db::name('tm_paper')->where('id', (int)$paperId)->find();
        if (!$paper) {
            return null;
        }
        if ((int)$paper['wx_user_id'] === (int)$wxUserId) {
            return $paper;
        }
        $memberId = (int)Db::name('tm_wx_user')->where('id', (int)$wxUserId)->value('member_id');
        if ($memberId > 0 && (int)$paper['member_id'] === $memberId) {
            return $paper;
        }
        return null;
    }

    /**
     * @param int $wxUserId
     * @param int $batchId
     * @return array|null
     */
    private static function findUserPaper($wxUserId, $batchId)
    {
        $wxUserId = (int)$wxUserId;
        $batchId = (int)$batchId;
        $paper = Db::name('tm_paper')
            ->where('batch_id', $batchId)
            ->where('wx_user_id', $wxUserId)
            ->find();
        if ($paper) {
            return $paper;
        }
        $memberId = (int)Db::name('tm_wx_user')->where('id', $wxUserId)->value('member_id');
        if ($memberId <= 0) {
            return null;
        }
        return Db::name('tm_paper')
            ->where('batch_id', $batchId)
            ->where('member_id', $memberId)
            ->find();
    }

    /**
     * @param string $msg
     * @return int
     */
    private static function denyCode($msg)
    {
        foreach (['不在本批次', '不可作答', '已结束', '尚未开测', '尚未开始', '截止'] as $k) {
            if (strpos($msg, $k) !== false) {
                return 403;
            }
        }
        return 201;
    }
}

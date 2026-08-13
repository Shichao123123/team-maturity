<?php
namespace app\index\service;

use think\Db;

/**
 * 测评批次：创建/绑班组/开测快照/结束/班组算分。
 */
class BatchService
{
    /**
     * 将批次状态码转为中文文案。
     *
     * @param int|string $status 状态码：0 未开始，1 进行中，2 已结束
     * @return string 对应中文，未知状态返回空字符串
     */
    public static function statusText($status)
    {
        $map = [0 => '未开始', 1 => '进行中', 2 => '已结束'];
        return isset($map[(int)$status]) ? $map[(int)$status] : '';
    }

    /**
     * 将算分状态码转为中文文案。
     *
     * @param int|string $status 状态码：0 未算分，1 算分中，2 已算分，3 失败
     * @return string 对应中文，未知状态返回空字符串
     */
    public static function scoreStatusText($status)
    {
        $map = [0 => '未算分', 1 => '算分中', 2 => '已算分', 3 => '失败'];
        return isset($map[(int)$status]) ? $map[(int)$status] : '';
    }

    /**
     * 按主键查询未软删的批次记录。
     *
     * @param int|string $id 批次 ID
     * @return array|null 批次行数据，不存在或已删除时返回 null
     */
    public static function find($id)
    {
        return Db::name('tm_batch')->where('id', (int)$id)->whereNull('deleted_at')->find();
    }

    /**
     * 分页查询批次列表，并附带版本名、班组数、已提交答卷数等统计。
     *
     * @param int|string|null $status  按状态筛选，null 或空表示不限
     * @param string          $keyword 批次名称模糊搜索
     * @param int             $page    页码
     * @param int             $limit   每页条数
     * @return array [count, list] count 为总条数，list 为当前页行数组（含 status_text、team_cnt 等）
     */
    public static function getPage($status = null, $keyword = '', $page = 1, $limit = 15)
    {
        $apply = function ($q) use ($status, $keyword) {
            $q->whereNull('b.deleted_at');
            if ($status !== null && $status !== '') {
                $q->where('b.status', (int)$status);
            }
            if ($keyword !== '') {
                $q->where('b.name', 'like', '%' . $keyword . '%');
            }
            return $q;
        };

        $count = $apply(Db::name('tm_batch')->alias('b'))->count();
        $list = $apply(
            Db::name('tm_batch')->alias('b')
                ->join('tm_indicator_version v', 'v.id = b.version_id', 'LEFT')
                ->field('b.*, v.name as version_name, v.version_no')
        )->order('b.id desc')->page($page, $limit)->select();

        if (empty($list)) {
            return [0, []];
        }

        // 批量统计各批次的绑定班组数与已提交答卷数，避免 N+1 查询
        $ids = [];
        foreach ($list as $row) {
            $ids[] = (int)$row['id'];
        }
        $teamCnt = Db::name('tm_batch_team')
            ->where('batch_id', 'in', $ids)
            ->group('batch_id')
            ->column('COUNT(*)', 'batch_id');
        $submitCnt = Db::name('tm_paper')
            ->where('batch_id', 'in', $ids)
            ->where('status', 1)
            ->group('batch_id')
            ->column('COUNT(*)', 'batch_id');

        foreach ($list as &$row) {
            $bid = (int)$row['id'];
            $row['status_text'] = self::statusText($row['status']);
            $row['score_status_text'] = self::scoreStatusText($row['score_status']);
            $row['team_cnt'] = isset($teamCnt[$bid]) ? (int)$teamCnt[$bid] : 0;
            $row['submitted_cnt'] = isset($submitCnt[$bid]) ? (int)$submitCnt[$bid] : 0;
        }
        unset($row);

        return [$count, $list];
    }

    /**
     * 创建新批次（初始状态为未开始、未算分）。
     *
     * @param array    $data    表单字段：name、version_id、start_time、end_time、question_pick_mode 等
     * @param int|null $adminId 创建人管理员 ID，可为 null
     * @return array [ok, msg, id] 成功时 id 为新批次主键，失败时 id 为 0
     */
    public static function create(array $data, $adminId = null)
    {
        list($ok, $msg, $clean) = self::validatePayload($data, true);
        if (!$ok) {
            return [false, $msg, 0];
        }
        $now = tmNow();
        $id = (int)Db::name('tm_batch')->insertGetId([
            'name'               => $clean['name'],
            'version_id'         => $clean['version_id'],
            'question_pick_mode' => $clean['question_pick_mode'],
            'start_time'         => $clean['start_time'],
            'end_time'           => $clean['end_time'],
            'status'             => 0,
            'score_status'       => 0,
            'created_by'         => $adminId ? (int)$adminId : null,
            'created_at'         => $now,
        ]);
        return [true, '创建成功', $id];
    }

    /**
     * 更新批次基本信息；已结束不可编辑，进行中仅允许改名称与结束时间。
     *
     * @param int|string $id   批次 ID
     * @param array      $data 待更新字段
     * @return array [ok, msg] 成功或失败及提示信息
     */
    public static function update($id, array $data)
    {
        $batch = self::find($id);
        if (!$batch) {
            return [false, '批次不存在'];
        }
        $status = (int)$batch['status'];
        if ($status === 2) {
            return [false, '已结束批次不可编辑'];
        }

        list($ok, $msg, $clean) = self::validatePayload($data, false, $batch);
        if (!$ok) {
            return [false, $msg];
        }

        $upd = [
            'name'       => $clean['name'],
            'end_time'   => $clean['end_time'],
            'updated_at' => tmNow(),
        ];
        // 未开测：允许改版本、开始时间与抽题模式；进行中：仅 end_time 等有限字段
        if ($status === 0) {
            $upd['version_id'] = $clean['version_id'];
            $upd['start_time'] = $clean['start_time'];
            $upd['question_pick_mode'] = $clean['question_pick_mode'];
        }

        // 若已绑定班组，校验调整后时间窗是否与其它批次冲突
        $newStart = isset($upd['start_time']) ? $upd['start_time'] : $batch['start_time'];
        $newEnd = $upd['end_time'];
        $boundIds = Db::name('tm_batch_team')->where('batch_id', (int)$id)->column('team_id');
        if (!empty($boundIds)) {
            $busy = self::findBusyTeams($id, $newStart, $newEnd);
            foreach ($boundIds as $tid) {
                if (isset($busy[(int)$tid])) {
                    $c = $busy[(int)$tid];
                    return [false, '调整后的时间与批次「' . $c['batch_name'] . '」重叠，请先解绑冲突班组或改时间'];
                }
            }
        }

        Db::name('tm_batch')->where('id', (int)$id)->update($upd);
        return [true, '保存成功'];
    }

    /**
     * 查找与本批次时间窗重叠、且已绑定某班组的其它批次（同一班组同一时间段仅允许一次测评）。
     *
     * @param int|string      $batchId   当前批次 ID
     * @param string|null     $startTime 可选覆盖开始时间，null 则用批次原值
     * @param string|null     $endTime   可选覆盖结束时间，null 则用批次原值
     * @return array [team_id => ['batch_id', 'batch_name', 'start_time', 'end_time', 'status', 'status_text']]
     */
    public static function findBusyTeams($batchId, $startTime = null, $endTime = null)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return [];
        }
        $start = $startTime !== null ? $startTime : $batch['start_time'];
        $end = $endTime !== null ? $endTime : $batch['end_time'];
        if ($start === '' || $end === '') {
            return [];
        }

        // 时间重叠：other.start < this.end AND other.end > this.start
        // 仅未开始/进行中占用班组；已结束批次不再阻挡新批次绑定
        $others = Db::name('tm_batch')
            ->whereNull('deleted_at')
            ->where('id', '<>', (int)$batchId)
            ->where('status', 'in', [0, 1])
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->field('id, name, start_time, end_time, status')
            ->select();
        if (empty($others)) {
            return [];
        }

        $otherIds = [];
        $otherMap = [];
        foreach ($others as $o) {
            $oid = (int)$o['id'];
            $otherIds[] = $oid;
            $otherMap[$oid] = $o;
        }

        $binds = Db::name('tm_batch_team')
            ->where('batch_id', 'in', $otherIds)
            ->field('batch_id, team_id')
            ->select();

        // 同一班组若被多个重叠批次占用，只保留第一个冲突批次信息
        $busy = [];
        foreach ($binds as $b) {
            $tid = (int)$b['team_id'];
            if (isset($busy[$tid])) {
                continue;
            }
            $ob = $otherMap[(int)$b['batch_id']];
            $busy[$tid] = [
                'batch_id'    => (int)$ob['id'],
                'batch_name'  => $ob['name'],
                'start_time'  => $ob['start_time'],
                'end_time'    => $ob['end_time'],
                'status'      => (int)$ob['status'],
                'status_text' => self::statusText($ob['status']),
            ];
        }
        return $busy;
    }

    /**
     * 开测前全量替换绑定参评班组，并写入各班组 member_total；开测后禁止修改。
     *
     * @param int|string $batchId 批次 ID
     * @param array      $teamIds 班组 ID 列表
     * @return array [ok, msg] 成功或失败及提示信息
     */
    public static function bindTeams($batchId, array $teamIds)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return [false, '批次不存在'];
        }
        if ((int)$batch['status'] !== 0) {
            return [false, '开测后不可修改参评班组'];
        }

        $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
        if (empty($teamIds)) {
            return [false, '请至少选择一个班组'];
        }

        $valid = Db::name('tm_team')
            ->where('id', 'in', $teamIds)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->column('id');
        if (count($valid) !== count($teamIds)) {
            return [false, '存在停用或不存在的班组，请重新选择'];
        }

        $busy = self::findBusyTeams($batchId);
        $conflicts = [];
        $nameMap = Db::name('tm_team')->where('id', 'in', $teamIds)->column('name', 'id');
        foreach ($teamIds as $tid) {
            if (isset($busy[$tid])) {
                $c = $busy[$tid];
                $tname = isset($nameMap[$tid]) ? $nameMap[$tid] : ('#' . $tid);
                $conflicts[] = "「{$tname}」已在重叠时段批次「{$c['batch_name']}」中";
            }
        }
        if (!empty($conflicts)) {
            return [false, '同一班组同一时间段只能参加一次测评：' . implode('；', $conflicts)];
        }

        // 事务内先删后插，保证绑定关系与 member_total 快照一致
        $now = tmNow();
        $countMap = Db::name('tm_member')
            ->where('team_id', 'in', $teamIds)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->group('team_id')
            ->column('COUNT(*)', 'team_id');
        $rows = [];
        foreach ($teamIds as $tid) {
            $tid = (int)$tid;
            $rows[] = [
                'batch_id'      => (int)$batchId,
                'team_id'       => $tid,
                'member_total'  => isset($countMap[$tid]) ? (int)$countMap[$tid] : 0,
                'submitted_cnt' => 0,
                'created_at'    => $now,
            ];
        }
        Db::startTrans();
        try {
            Db::name('tm_batch_team')->where('batch_id', (int)$batchId)->delete();
            if (!empty($rows)) {
                Db::name('tm_batch_team')->insertAll($rows);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return [false, '绑定失败：' . $e->getMessage()];
        }
        return [true, '绑定成功'];
    }

    /**
     * 开测：校验版本与抽题结果，写入指标快照并将批次 status 置为进行中。
     *
     * @param int|string $batchId 批次 ID
     * @return array [ok, msg, data] 成功时 data 含 batch_id、question_cnt、pick_mode；失败时 data 为 null
     */
    public static function start($batchId)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null];
        }
        if ((int)$batch['status'] !== 0) {
            return [false, '仅未开始的批次可以开测', null];
        }
        $bindCnt = Db::name('tm_batch_team')->where('batch_id', (int)$batchId)->count();
        if ($bindCnt < 1) {
            return [false, '请先绑定参评班组', null];
        }

        // 开测前再校验时间重叠（防止改时间后仍带着冲突班组开测）
        $boundIds = Db::name('tm_batch_team')->where('batch_id', (int)$batchId)->column('team_id');
        $busy = self::findBusyTeams($batchId);
        $conflicts = [];
        $nameMap = empty($boundIds) ? [] : Db::name('tm_team')->where('id', 'in', $boundIds)->column('name', 'id');
        foreach ($boundIds as $tid) {
            $tid = (int)$tid;
            if (isset($busy[$tid])) {
                $c = $busy[$tid];
                $tname = isset($nameMap[$tid]) ? $nameMap[$tid] : ('#' . $tid);
                $conflicts[] = "「{$tname}」与批次「{$c['batch_name']}」时间重叠";
            }
        }
        if (!empty($conflicts)) {
            return [false, '存在时段冲突班组，请调整时间或班组：' . implode('；', $conflicts), null];
        }

        $versionId = (int)$batch['version_id'];
        $ver = Db::name('tm_indicator_version')->where('id', $versionId)->find();
        if (!$ver || (int)$ver['status'] !== 1) {
            return [false, '指标版本未发布或不可用', null];
        }

        $errors = QuestionPickService::validateVersionQuestions($versionId);
        if (!empty($errors)) {
            return [false, implode('；', $errors), null];
        }

        $override = !empty($batch['question_pick_mode']) ? $batch['question_pick_mode'] : null;
        $examSet = QuestionPickService::buildExamSet($versionId, $override);
        if (empty($examSet['items'])) {
            return [false, '抽题结果为空，请检查题库', null];
        }

        $now = tmNow();
        Db::startTrans();
        try {
            // 快照已存在则更新（支持重复开测前修正），否则新建
            $exist = Db::name('tm_batch_indicator_snap')->where('batch_id', (int)$batchId)->find();
            $snapData = [
                'source_version_id' => $versionId,
                'snap_json'         => json_encode($examSet, JSON_UNESCAPED_UNICODE),
            ];
            if ($exist) {
                Db::name('tm_batch_indicator_snap')->where('id', $exist['id'])->update($snapData);
                $snapId = (int)$exist['id'];
            } else {
                $snapData['batch_id'] = (int)$batchId;
                $snapData['created_at'] = $now;
                $snapId = (int)Db::name('tm_batch_indicator_snap')->insertGetId($snapData);
            }
            Db::name('tm_batch')->where('id', (int)$batchId)->update([
                'status'     => 1,
                'updated_at' => $now,
            ]);
            Db::commit();
            // 清理小程序答题用的快照文件缓存，避免重复开测后仍用旧题
            $cacheFile = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR
                . 'runtime' . DIRECTORY_SEPARATOR . 'snap-cache' . DIRECTORY_SEPARATOR
                . 'b' . (int)$batchId . '_' . $snapId . '.json';
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }
        } catch (\Exception $e) {
            Db::rollback();
            return [false, '开测失败：' . $e->getMessage(), null];
        }

        return [true, '开测成功', [
            'batch_id'    => (int)$batchId,
            'question_cnt'=> count($examSet['items']),
            'pick_mode'   => $examSet['pick_mode'],
        ]];
    }

    /**
     * 结束批次（status=2）；结束后自动触发正式班组算分并落库。
     *
     * @param int|string $batchId   批次 ID
     * @param bool       $autoScore 是否在结束后立即算分（默认 true）
     * @return array [ok, msg, data] data 为算分结果摘要，未算分时可为 null
     */
    public static function finish($batchId, $autoScore = true)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null];
        }
        if ((int)$batch['status'] !== 1) {
            return [false, '仅进行中的批次可以结束', null];
        }
        Db::name('tm_batch')->where('id', (int)$batchId)->update([
            'status'     => 2,
            'updated_at' => tmNow(),
        ]);
        if (!$autoScore) {
            return [true, '批次已结束', null];
        }
        list($ok, $scoreMsg, $scoreData) = self::score($batchId);
        if ($ok) {
            return [true, '批次已结束并完成算分', $scoreData];
        }
        return [true, '批次已结束，但算分失败：' . $scoreMsg, $scoreData];
    }

    /**
     * 进行中批次：按当前已提交答卷实时估算班组分与等级，不落库、不改 score_status。
     * 用于查看「当前答题大致情况」。
     *
     * @param int|string $batchId 批次 ID
     * @return array [ok, msg, data]
     */
    public static function scorePreview($batchId)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null];
        }
        $status = (int)$batch['status'];
        if ($status === 0) {
            return [false, '未开测批次暂无答题概况', null];
        }
        if ($status === 2) {
            return [false, '批次已结束，请使用正式算分/重新算分', null];
        }

        $batchId = (int)$batchId;
        $computed = self::computeLiveTeamScores($batchId);
        $levelNames = LevelJudgeService::nameMap();

        $teamIds = Db::name('tm_batch_team')->where('batch_id', $batchId)->column('team_id');
        $teamNameMap = [];
        if (!empty($teamIds)) {
            $teamNameMap = Db::name('tm_team')->where('id', 'in', $teamIds)->column('name', 'id');
        }
        $answeringMap = [];
        if (!empty($teamIds)) {
            $answeringMap = Db::name('tm_paper')
                ->where('batch_id', $batchId)
                ->where('team_id', 'in', $teamIds)
                ->where('status', 0)
                ->group('team_id')
                ->column('COUNT(*)', 'team_id');
        }

        $byTeam = [];
        foreach ($computed['teams'] as $row) {
            $byTeam[(int)$row['team_id']] = $row;
        }

        $list = [];
        $submittedTotal = 0;
        $answeringTotal = 0;
        $scoredTeamCnt = 0;
        foreach ($teamIds as $tid) {
            $tid = (int)$tid;
            $row = isset($byTeam[$tid]) ? $byTeam[$tid] : null;
            $submitted = $row ? (int)$row['submit_cnt'] : 0;
            $answering = isset($answeringMap[$tid]) ? (int)$answeringMap[$tid] : 0;
            $submittedTotal += $submitted;
            $answeringTotal += $answering;
            $code = $row ? $row['level_code'] : '';
            if ($submitted > 0) {
                $scoredTeamCnt++;
            }
            $list[] = [
                'team_id'            => $tid,
                'team_name'          => isset($teamNameMap[$tid]) ? $teamNameMap[$tid] : ('班组#' . $tid),
                'submitted_cnt'      => $submitted,
                'answering_cnt'      => $answering,
                'provisional_score'  => $row ? $row['team_score'] : null,
                'provisional_level'  => ($code && isset($levelNames[$code])) ? $levelNames[$code] : '',
                'provisional_code'   => $code,
            ];
        }

        return [true, '当前答题概况（预估，未落库）', [
            'preview'         => 1,
            'batch_id'        => $batchId,
            'batch_name'      => $batch['name'],
            'team_total'      => count($teamIds),
            'scored_team_cnt' => $scoredTeamCnt,
            'submitted_total' => $submittedTotal,
            'answering_total' => $answeringTotal,
            'teams'           => $list,
            'note'            => '以下分数按「当前已提交答卷均分」估算，批次结束后才会正式算分落库。',
        ]];
    }

    /**
     * 对已结束批次下各绑定班组聚合已提交答卷，计算团队均分、等级与薄弱指标并落库。
     * 进行中批次请使用 scorePreview，不在此写入正式结果。
     *
     * @param int|string $batchId 批次 ID
     * @return array [ok, msg, data] 成功时 data['teams'] 为各班组算分摘要；失败时 data 为 null
     */
    public static function score($batchId)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null];
        }
        if ((int)$batch['status'] === 0) {
            return [false, '未开测批次不能算分', null];
        }
        if ((int)$batch['status'] !== 2) {
            return [false, '仅已结束批次可正式算分；进行中请查看「当前答题概况」', null];
        }

        $batchId = (int)$batchId;
        Db::startTrans();
        try {
            Db::name('tm_batch')->where('id', $batchId)->update([
                'score_status' => 1,
                'updated_at'   => tmNow(),
            ]);

            $teams = Db::name('tm_batch_team')->where('batch_id', $batchId)->column('team_id');
            $now = tmNow();
            $results = [];

            $live = self::computeLiveTeamScores($batchId);
            $papersByTeam = $live['papers_by_team'];
            $pending = $live['teams'];

            $existMap = [];
            $existRows = Db::name('tm_team_result')->where('batch_id', $batchId)->select();
            foreach ($existRows as $er) {
                $existMap[(int)$er['team_id']] = $er;
            }

            // 无提交班组：清结果、submitted_cnt 置 0
            $emptyTeamIds = [];
            foreach ($teams as $teamId) {
                $teamId = (int)$teamId;
                if (empty($papersByTeam[$teamId])) {
                    $emptyTeamIds[] = $teamId;
                }
            }
            if (!empty($emptyTeamIds)) {
                Db::name('tm_team_result')
                    ->where('batch_id', $batchId)
                    ->where('team_id', 'in', $emptyTeamIds)
                    ->delete();
                Db::name('tm_batch_team')
                    ->where('batch_id', $batchId)
                    ->where('team_id', 'in', $emptyTeamIds)
                    ->update(['submitted_cnt' => 0]);
            }

            $weakByTeam = self::buildTeamWeakIndicatorsBatch($pending);

            foreach ($pending as $item) {
                $teamId = $item['team_id'];
                $weak = isset($weakByTeam[$teamId]) ? $weakByTeam[$teamId] : [];
                $row = [
                    'team_score'          => $item['team_score'],
                    'level_code'          => $item['level_code'],
                    'submit_cnt'          => $item['submit_cnt'],
                    'weak_indicator_json' => json_encode($weak, JSON_UNESCAPED_UNICODE),
                    'scored_at'           => $now,
                ];
                if (isset($existMap[$teamId])) {
                    Db::name('tm_team_result')->where('id', $existMap[$teamId]['id'])->update($row);
                } else {
                    $row['batch_id'] = $batchId;
                    $row['team_id'] = $teamId;
                    Db::name('tm_team_result')->insert($row);
                }
                Db::name('tm_batch_team')->where('batch_id', $batchId)->where('team_id', $teamId)->update([
                    'submitted_cnt' => $item['submit_cnt'],
                ]);
                $results[] = [
                    'team_id'    => $teamId,
                    'team_score' => $item['team_score'],
                    'level_code' => $item['level_code'],
                    'submit_cnt' => $item['submit_cnt'],
                ];
            }

            Db::name('tm_batch')->where('id', $batchId)->update([
                'score_status' => 2,
                'updated_at'   => tmNow(),
            ]);
            Db::commit();
            return [true, '算分完成', ['teams' => $results]];
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('tm_batch')->where('id', $batchId)->update([
                'score_status' => 3,
                'updated_at'   => tmNow(),
            ]);
            return [false, '算分失败：' . $e->getMessage(), null];
        }
    }

    /**
     * 按当前已提交答卷实时计算各班组均分与等级（不写库）。
     *
     * @param int $batchId 批次 ID
     * @return array{teams:array,papers_by_team:array}
     */
    private static function computeLiveTeamScores($batchId)
    {
        $batchId = (int)$batchId;
        $teams = Db::name('tm_batch_team')->where('batch_id', $batchId)->column('team_id');
        $allPapers = [];
        if (!empty($teams)) {
            $allPapers = Db::name('tm_paper')
                ->where('batch_id', $batchId)
                ->where('team_id', 'in', $teams)
                ->where('status', 1)
                ->field('id, team_id, personal_score')
                ->select();
        }
        $papersByTeam = [];
        foreach ($allPapers as $p) {
            $tid = (int)$p['team_id'];
            if (!isset($papersByTeam[$tid])) {
                $papersByTeam[$tid] = [];
            }
            $papersByTeam[$tid][] = $p;
        }

        $pending = [];
        foreach ($teams as $teamId) {
            $teamId = (int)$teamId;
            if (empty($papersByTeam[$teamId])) {
                continue;
            }
            $papers = $papersByTeam[$teamId];
            $submitCnt = count($papers);
            $sum = 0.0;
            $paperIds = [];
            foreach ($papers as $p) {
                $sum += (float)$p['personal_score'];
                $paperIds[] = (int)$p['id'];
            }
            $teamScore = round($sum / $submitCnt, 2);
            $level = LevelJudgeService::judge($teamScore);
            $levelCode = $level ? $level['level_code'] : 'passive';
            $pending[] = [
                'team_id'    => $teamId,
                'team_score' => $teamScore,
                'level_code' => $levelCode,
                'submit_cnt' => $submitCnt,
                'paper_ids'  => $paperIds,
            ];
        }

        return [
            'teams'          => $pending,
            'papers_by_team' => $papersByTeam,
        ];
    }

    /**
     * 获取批次详情：版本信息、抽题快照、小程序二维码、绑定班组、进度与冲突班组等。
     *
     * @param int|string $batchId 批次 ID
     * @return array|null  enriched 批次数组，不存在时返回 null
     */
    public static function detail($batchId)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return null;
        }
        $ver = Db::name('tm_indicator_version')->where('id', $batch['version_id'])->find();
        $snap = Db::name('tm_batch_indicator_snap')->where('batch_id', (int)$batchId)->find();
        $questionCnt = 0;
        if ($snap && !empty($snap['snap_json'])) {
            $json = json_decode($snap['snap_json'], true);
            if (is_array($json) && isset($json['items'])) {
                $questionCnt = count($json['items']);
            }
        }

        $batch['status_text'] = self::statusText($batch['status']);
        $batch['score_status_text'] = self::scoreStatusText($batch['score_status']);
        $batch['version_name'] = $ver ? $ver['name'] : '';
        $batch['version_no'] = $ver ? $ver['version_no'] : '';
        $batch['has_snap'] = $snap ? 1 : 0;
        $batch['question_cnt'] = $questionCnt;
        $qrPath = \think\Config::get('mini.qr_path') ?: 'pages/exam/index';
        $batch['qr_path'] = $qrPath;
        $batch['qr_scene'] = 'batch_id=' . (int)$batchId;
        $batch['qr_content'] = $qrPath . '?' . $batch['qr_scene'];
        // 正式小程序码（getwxacodeunlimit）：scene=b={id}，由 /TmBatch/wxacode 出图
        $batch['wxacode_configured'] = \app\index\service\WxMiniService::isConfigured();
        $batch['wxacode_env'] = \app\index\service\WxMiniService::envVersion();
        $batch['wxacode_scene'] = 'b=' . (int)$batchId;
        $batch['wxacode_url'] = $batch['wxacode_configured']
            ? ('/TmBatch/wxacode?id=' . (int)$batchId)
            : '';
        $batch['team_ids'] = Db::name('tm_batch_team')->where('batch_id', (int)$batchId)->column('team_id');
        $batch['progress'] = self::progress($batchId);
        $busy = self::findBusyTeams($batchId);
        $batch['busy_teams'] = $busy;
        // 便于前端遍历
        $busyList = [];
        foreach ($busy as $tid => $info) {
            $info['team_id'] = (int)$tid;
            $busyList[] = $info;
        }
        $batch['busy_team_list'] = $busyList;

        return $batch;
    }

    /**
     * 汇总批次下各参评班组的答题与算分进度。
     *
     * @param int|string $batchId 批次 ID
     * @return array 班组进度列表，每项含 team_id、member_total、submitted_cnt、answering_cnt、team_score、level_name 等
     */
    public static function progress($batchId)
    {
        $batchId = (int)$batchId;
        $rows = Db::name('tm_batch_team')->alias('bt')
            ->join('tm_team t', 't.id = bt.team_id', 'LEFT')
            ->join('tm_team_result tr', 'tr.batch_id = bt.batch_id AND tr.team_id = bt.team_id', 'LEFT')
            ->where('bt.batch_id', $batchId)
            ->field('bt.team_id, bt.member_total, bt.submitted_cnt as bind_submitted_cnt,
                t.name as team_name,
                tr.team_score, tr.level_code, tr.submit_cnt as result_submit_cnt, tr.scored_at')
            ->order('bt.team_id asc')
            ->select();
        if (empty($rows)) {
            return [];
        }

        $teamIds = [];
        foreach ($rows as $row) {
            $teamIds[] = (int)$row['team_id'];
        }

        // 按班组聚合：已提交数、答题中数、最近提交时间（与 tm_team_result 算分结果互补）
        $submittedMap = Db::name('tm_paper')
            ->where('batch_id', $batchId)
            ->where('team_id', 'in', $teamIds)
            ->where('status', 1)
            ->group('team_id')
            ->column('COUNT(*)', 'team_id');
        $answeringMap = Db::name('tm_paper')
            ->where('batch_id', $batchId)
            ->where('team_id', 'in', $teamIds)
            ->where('status', 0)
            ->group('team_id')
            ->column('COUNT(*)', 'team_id');
        $lastSubmitMap = Db::name('tm_paper')
            ->where('batch_id', $batchId)
            ->where('team_id', 'in', $teamIds)
            ->where('status', 1)
            ->group('team_id')
            ->column('MAX(submitted_at)', 'team_id');

        $levelNames = LevelJudgeService::nameMap();
        $result = [];
        foreach ($rows as $row) {
            $tid = (int)$row['team_id'];
            $code = $row['level_code'] ?: '';
            $result[] = [
                'team_id'           => $tid,
                'team_name'         => $row['team_name'],
                'member_total'      => (int)$row['member_total'],
                'submitted_cnt'     => isset($submittedMap[$tid]) ? (int)$submittedMap[$tid] : 0,
                'answering_cnt'     => isset($answeringMap[$tid]) ? (int)$answeringMap[$tid] : 0,
                'last_submitted_at' => isset($lastSubmitMap[$tid]) && $lastSubmitMap[$tid] ? $lastSubmitMap[$tid] : '',
                'team_score'        => $row['team_score'],
                'level_code'        => $code,
                'level_name'        => ($code && isset($levelNames[$code])) ? $levelNames[$code] : '',
                'scored_at'         => $row['scored_at'] ?: '',
            ];
        }
        return $result;
    }

    /**
     * 软删除批次（写入 deleted_at）；进行中的批次需先结束。
     *
     * @param int|string $batchId 批次 ID
     * @return array [ok, msg] 成功或失败及提示信息
     */
    public static function softDelete($batchId)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return [false, '批次不存在'];
        }
        if ((int)$batch['status'] === 1) {
            return [false, '进行中的批次不可删除，请先结束'];
        }
        $paperCnt = Db::name('tm_paper')->where('batch_id', (int)$batchId)->count();
        if ($paperCnt > 0 && (int)$batch['status'] === 2) {
            // 已结束且有答卷：允许软删保留数据，或拒绝？计划未写——允许软删
        }
        Db::name('tm_batch')->where('id', (int)$batchId)->update([
            'deleted_at' => tmNow(),
            'updated_at' => tmNow(),
        ]);
        return [true, '已删除'];
    }

    /**
     * 校验创建/更新批次的表单载荷，并按状态决定可改字段范围。
     *
     * @param array      $data     原始表单数据
     * @param bool       $isCreate 是否为新建（true 校验全量字段，false 按 exist.status 分支）
     * @param array|null $exist    更新时的现有批次行，新建时传 null
     * @return array [ok, msg, clean] 成功时 clean 为规范化后的可写字段数组
     */
    private static function validatePayload(array $data, $isCreate, $exist = null)
    {
        $name = isset($data['name']) ? trim($data['name']) : '';
        $versionId = isset($data['version_id']) ? (int)$data['version_id'] : 0;
        $start = isset($data['start_time']) ? trim($data['start_time']) : '';
        $end = isset($data['end_time']) ? trim($data['end_time']) : '';
        $pickMode = isset($data['question_pick_mode']) ? trim($data['question_pick_mode']) : '';
        if ($pickMode === '') {
            $pickMode = null;
        }

        if ($name === '') {
            return [false, '请填写批次名称', null];
        }
        if ($isCreate || ($exist && (int)$exist['status'] === 0)) {
            if ($versionId <= 0) {
                return [false, '请选择已发布的指标版本', null];
            }
            $ver = Db::name('tm_indicator_version')->where('id', $versionId)->find();
            if (!$ver || (int)$ver['status'] !== 1) {
                return [false, '只能绑定已发布的指标版本', null];
            }
            if ($start === '' || $end === '') {
                return [false, '请填写开始与结束时间', null];
            }
            if (strtotime($start) === false || strtotime($end) === false) {
                return [false, '时间格式不正确', null];
            }
            if (strtotime($end) <= strtotime($start)) {
                return [false, '结束时间必须晚于开始时间', null];
            }
        } else {
            // 开测后：沿用原版本与开始时间，只校验 end
            $versionId = (int)$exist['version_id'];
            $start = $exist['start_time'];
            $pickMode = $exist['question_pick_mode'];
            if ($end === '') {
                return [false, '请填写结束时间', null];
            }
            if (strtotime($end) === false) {
                return [false, '结束时间格式不正确', null];
            }
            if (strtotime($end) <= strtotime($start)) {
                return [false, '结束时间必须晚于开始时间', null];
            }
        }

        return [true, 'ok', [
            'name'               => $name,
            'version_id'         => $versionId,
            'start_time'         => $start,
            'end_time'           => $end,
            'question_pick_mode' => $pickMode,
        ]];
    }

    /**
     * 按答卷明细聚合班组薄弱三级指标（指标均分低于满分），并匹配赋能措施文案。
     *
     * @param array  $paperIds  已提交答卷 ID 列表
     * @param string $levelCode 班组成熟度等级码，用于查找对应赋能措施
     * @return array 最多 10 条薄弱指标，按与满分的差距降序
     */
    private static function buildTeamWeakIndicators(array $paperIds, $levelCode)
    {
        if (empty($paperIds)) {
            return [];
        }
        $teamId = (int)Db::name('tm_paper')->where('id', (int)$paperIds[0])->value('team_id');
        $batch = self::buildTeamWeakIndicatorsBatch([[
            'team_id'    => $teamId,
            'level_code' => $levelCode,
            'paper_ids'  => $paperIds,
        ]]);
        return isset($batch[$teamId]) ? $batch[$teamId] : [];
    }

    /**
     * 多班组薄弱指标一次聚合：答卷 AVG 按 team+indicator 分组，措施批量预取。
     *
     * @param array $pending 每项含 team_id、level_code、paper_ids
     * @return array [team_id => weakList]
     */
    private static function buildTeamWeakIndicatorsBatch(array $pending)
    {
        $out = [];
        $allPaperIds = [];
        $teamLevel = [];
        foreach ($pending as $item) {
            $tid = (int)$item['team_id'];
            $out[$tid] = [];
            $teamLevel[$tid] = isset($item['level_code']) ? (string)$item['level_code'] : 'passive';
            if (empty($item['paper_ids'])) {
                continue;
            }
            foreach ($item['paper_ids'] as $pid) {
                $allPaperIds[(int)$pid] = true;
            }
        }
        $allPaperIds = array_keys($allPaperIds);
        if (empty($allPaperIds)) {
            return $out;
        }

        $rows = Db::name('tm_paper_answer')->alias('a')
            ->join('tm_paper p', 'p.id = a.paper_id', 'INNER')
            ->join('tm_indicator i', 'i.id = a.indicator_id', 'LEFT')
            ->where('a.paper_id', 'in', $allPaperIds)
            ->field('p.team_id, a.indicator_id, i.name as indicator_name, AVG(a.item_score) as avg_score, AVG(a.weight) as avg_weight')
            ->group('p.team_id, a.indicator_id')
            ->select();

        $indicatorIds = [];
        $byTeam = [];
        foreach ($rows as $r) {
            $tid = (int)$r['team_id'];
            $avg = round((float)$r['avg_score'], 2);
            $full = round((float)$r['avg_weight'], 2);
            if ($full <= 0) {
                continue;
            }
            if ($avg >= $full - 0.0001) {
                continue;
            }
            $iid = (int)$r['indicator_id'];
            $indicatorIds[$iid] = true;
            if (!isset($byTeam[$tid])) {
                $byTeam[$tid] = [];
            }
            $byTeam[$tid][] = [
                'indicator_id'   => $iid,
                'indicator_name' => $r['indicator_name'] ?: '',
                'avg_score'      => $avg,
                'full_score'     => $full,
            ];
        }

        $measureMap = MeasureLookup::loadByIndicatorIds(array_keys($indicatorIds));

        foreach ($byTeam as $tid => $list) {
            $levelCode = isset($teamLevel[$tid]) ? $teamLevel[$tid] : 'passive';
            $weak = [];
            foreach ($list as $row) {
                $measure = MeasureLookup::pickForTeam($measureMap, $row['indicator_id'], $levelCode);
                $weak[] = [
                    'indicator_id'   => $row['indicator_id'],
                    'indicator_name' => $row['indicator_name'],
                    'avg_score'      => $row['avg_score'],
                    'full_score'     => $row['full_score'],
                    'measure'        => $measure ? $measure['content'] : '暂无',
                ];
            }
            usort($weak, function ($a, $b) {
                $da = $a['full_score'] - $a['avg_score'];
                $db = $b['full_score'] - $b['avg_score'];
                if ($da == $db) {
                    return 0;
                }
                return ($da > $db) ? -1 : 1;
            });
            $out[$tid] = array_slice($weak, 0, 10);
        }

        return $out;
    }
}
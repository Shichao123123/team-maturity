<?php
namespace app\index\service;

use think\Db;

/**
 * 测评批次：创建/绑班组/开测快照/结束/班组算分。
 */
class BatchService
{
    public static function statusText($status)
    {
        $map = [0 => '未开始', 1 => '进行中', 2 => '已结束'];
        return isset($map[(int)$status]) ? $map[(int)$status] : '';
    }

    public static function scoreStatusText($status)
    {
        $map = [0 => '未算分', 1 => '算分中', 2 => '已算分', 3 => '失败'];
        return isset($map[(int)$status]) ? $map[(int)$status] : '';
    }

    public static function find($id)
    {
        return Db::name('tm_batch')->where('id', (int)$id)->whereNull('deleted_at')->find();
    }

    /**
     * @return array [count, list]
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
     * @return array [ok, msg, id]
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
     * @return array [ok, msg]
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
        if ($status === 0) {
            $upd['version_id'] = $clean['version_id'];
            $upd['start_time'] = $clean['start_time'];
            $upd['question_pick_mode'] = $clean['question_pick_mode'];
        }

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
     * 查找与本批次时间窗重叠、且已绑定某班组的其它批次（一班组同一时间段仅一次测评）。
     * @return array [team_id => ['batch_id'=>, 'batch_name'=>, 'start_time'=>, 'end_time'=>, 'status'=>, 'status_text'=>]]
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
        $others = Db::name('tm_batch')
            ->whereNull('deleted_at')
            ->where('id', '<>', (int)$batchId)
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
     * 开测前全量替换绑定班组；开测后禁止。
     * @return array [ok, msg]
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

        $now = tmNow();
        Db::startTrans();
        try {
            Db::name('tm_batch_team')->where('batch_id', (int)$batchId)->delete();
            foreach ($teamIds as $tid) {
                $memberTotal = (int)Db::name('tm_member')
                    ->where('team_id', $tid)
                    ->whereNull('deleted_at')
                    ->where('status', 1)
                    ->count();
                Db::name('tm_batch_team')->insert([
                    'batch_id'      => (int)$batchId,
                    'team_id'       => $tid,
                    'member_total'  => $memberTotal,
                    'submitted_cnt' => 0,
                    'created_at'    => $now,
                ]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return [false, '绑定失败：' . $e->getMessage()];
        }
        return [true, '绑定成功'];
    }

    /**
     * 开测：抽题写快照，status=1
     * @return array [ok, msg, data]
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
            $exist = Db::name('tm_batch_indicator_snap')->where('batch_id', (int)$batchId)->find();
            $snapData = [
                'source_version_id' => $versionId,
                'snap_json'         => json_encode($examSet, JSON_UNESCAPED_UNICODE),
            ];
            if ($exist) {
                Db::name('tm_batch_indicator_snap')->where('id', $exist['id'])->update($snapData);
            } else {
                $snapData['batch_id'] = (int)$batchId;
                $snapData['created_at'] = $now;
                Db::name('tm_batch_indicator_snap')->insert($snapData);
            }
            Db::name('tm_batch')->where('id', (int)$batchId)->update([
                'status'     => 1,
                'updated_at' => $now,
            ]);
            Db::commit();
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
     * @return array [ok, msg]
     */
    public static function finish($batchId)
    {
        $batch = self::find($batchId);
        if (!$batch) {
            return [false, '批次不存在'];
        }
        if ((int)$batch['status'] !== 1) {
            return [false, '仅进行中的批次可以结束'];
        }
        Db::name('tm_batch')->where('id', (int)$batchId)->update([
            'status'     => 2,
            'updated_at' => tmNow(),
        ]);
        return [true, '批次已结束'];
    }

    /**
     * 班组算分落库。
     * @return array [ok, msg, data]
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

        $batchId = (int)$batchId;
        Db::name('tm_batch')->where('id', $batchId)->update([
            'score_status' => 1,
            'updated_at'   => tmNow(),
        ]);

        try {
            $teams = Db::name('tm_batch_team')->where('batch_id', $batchId)->column('team_id');
            $now = tmNow();
            $results = [];
            foreach ($teams as $teamId) {
                $teamId = (int)$teamId;
                $papers = Db::name('tm_paper')
                    ->where('batch_id', $batchId)
                    ->where('team_id', $teamId)
                    ->where('status', 1)
                    ->field('id, personal_score')
                    ->select();
                $submitCnt = count($papers);
                if ($submitCnt < 1) {
                    // 无提交则清除旧结果
                    Db::name('tm_team_result')->where('batch_id', $batchId)->where('team_id', $teamId)->delete();
                    Db::name('tm_batch_team')->where('batch_id', $batchId)->where('team_id', $teamId)->update([
                        'submitted_cnt' => 0,
                    ]);
                    continue;
                }

                $sum = 0.0;
                $paperIds = [];
                foreach ($papers as $p) {
                    $sum += (float)$p['personal_score'];
                    $paperIds[] = (int)$p['id'];
                }
                $teamScore = round($sum / $submitCnt, 2);
                $level = LevelJudgeService::judge($teamScore);
                $levelCode = $level ? $level['level_code'] : 'passive';
                $weak = self::buildTeamWeakIndicators($paperIds, $levelCode);

                $exist = Db::name('tm_team_result')
                    ->where('batch_id', $batchId)
                    ->where('team_id', $teamId)
                    ->find();
                $row = [
                    'team_score'          => $teamScore,
                    'level_code'          => $levelCode,
                    'submit_cnt'          => $submitCnt,
                    'weak_indicator_json' => json_encode($weak, JSON_UNESCAPED_UNICODE),
                    'scored_at'           => $now,
                ];
                if ($exist) {
                    Db::name('tm_team_result')->where('id', $exist['id'])->update($row);
                } else {
                    $row['batch_id'] = $batchId;
                    $row['team_id'] = $teamId;
                    Db::name('tm_team_result')->insert($row);
                }
                Db::name('tm_batch_team')->where('batch_id', $batchId)->where('team_id', $teamId)->update([
                    'submitted_cnt' => $submitCnt,
                ]);
                $results[] = [
                    'team_id'    => $teamId,
                    'team_score' => $teamScore,
                    'level_code' => $levelCode,
                    'submit_cnt' => $submitCnt,
                ];
            }

            Db::name('tm_batch')->where('id', $batchId)->update([
                'score_status' => 2,
                'updated_at'   => tmNow(),
            ]);
            return [true, '算分完成', ['teams' => $results]];
        } catch (\Exception $e) {
            Db::name('tm_batch')->where('id', $batchId)->update([
                'score_status' => 3,
                'updated_at'   => tmNow(),
            ]);
            return [false, '算分失败：' . $e->getMessage(), null];
        }
    }

    /**
     * 详情：批次 + 快照标记 + 进度。
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

        $levelNames = Db::name('tm_level_threshold')->column('level_name', 'level_code');
        $result = [];
        foreach ($rows as $row) {
            $tid = (int)$row['team_id'];
            $submitted = (int)Db::name('tm_paper')
                ->where('batch_id', $batchId)
                ->where('team_id', $tid)
                ->where('status', 1)
                ->count();
            $answering = (int)Db::name('tm_paper')
                ->where('batch_id', $batchId)
                ->where('team_id', $tid)
                ->where('status', 0)
                ->count();
            $lastSubmit = Db::name('tm_paper')
                ->where('batch_id', $batchId)
                ->where('team_id', $tid)
                ->where('status', 1)
                ->order('submitted_at desc')
                ->value('submitted_at');
            $code = $row['level_code'] ?: '';
            $result[] = [
                'team_id'        => $tid,
                'team_name'      => $row['team_name'],
                'member_total'   => (int)$row['member_total'],
                'submitted_cnt'  => $submitted,
                'answering_cnt'  => $answering,
                'last_submitted_at' => $lastSubmit ?: '',
                'team_score'     => $row['team_score'],
                'level_code'     => $code,
                'level_name'     => ($code && isset($levelNames[$code])) ? $levelNames[$code] : '',
                'scored_at'      => $row['scored_at'] ?: '',
            ];
        }
        return $result;
    }

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
     * @return array [ok, msg, clean]
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
     * 按答卷明细聚合班组薄弱三级指标（均分低于满分）。
     */
    private static function buildTeamWeakIndicators(array $paperIds, $levelCode)
    {
        if (empty($paperIds)) {
            return [];
        }
        $rows = Db::name('tm_paper_answer')->alias('a')
            ->join('tm_indicator i', 'i.id = a.indicator_id', 'LEFT')
            ->where('a.paper_id', 'in', $paperIds)
            ->field('a.indicator_id, i.name as indicator_name, AVG(a.item_score) as avg_score, AVG(a.weight) as avg_weight')
            ->group('a.indicator_id')
            ->select();

        $weak = [];
        foreach ($rows as $r) {
            $avg = round((float)$r['avg_score'], 2);
            $full = round((float)$r['avg_weight'], 2);
            if ($full <= 0) {
                continue;
            }
            if ($avg >= $full - 0.0001) {
                continue;
            }
            $measure = Db::name('tm_empower_measure')
                ->where('indicator_id', (int)$r['indicator_id'])
                ->where('level_code', $levelCode ?: 'essential')
                ->find();
            if (!$measure) {
                $measure = Db::name('tm_empower_measure')
                    ->where('indicator_id', (int)$r['indicator_id'])
                    ->order('id asc')
                    ->find();
            }
            $weak[] = [
                'indicator_id'   => (int)$r['indicator_id'],
                'indicator_name' => $r['indicator_name'] ?: '',
                'avg_score'      => $avg,
                'full_score'     => $full,
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
        return array_slice($weak, 0, 10);
    }
}

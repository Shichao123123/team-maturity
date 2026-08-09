<?php
namespace app\index\model;

use think\Db;
use think\Model;

class MemberModel extends Model
{
    protected $table = 'tm_member';

    public function getPage($teamId = null, $keyword = '', $roleCode = '', $status = null, $page = 1, $limit = 15)
    {
        $apply = function ($q) use ($teamId, $keyword, $roleCode, $status) {
            $q->whereNull('m.deleted_at');
            if ($teamId !== null && $teamId !== '') {
                $q->where('m.team_id', (int)$teamId);
            }
            if ($keyword !== '') {
                $q->where('m.name|m.mobile', 'like', '%' . $keyword . '%');
            }
            if ($roleCode !== '') {
                $q->where('m.role_code', $roleCode);
            }
            if ($status !== null && $status !== '') {
                $q->where('m.status', (int)$status);
            }
            return $q;
        };

        $count = $apply(Db::name('tm_member')->alias('m'))->count();
        $list = $apply(
            Db::name('tm_member')->alias('m')
                ->join('tm_team t', 't.id = m.team_id', 'LEFT')
                ->field('m.*, t.name as team_name')
        )->order('m.id desc')->page($page, $limit)->select();

        return [$count, $list];
    }

    public function find($id)
    {
        return Db::name('tm_member')->where('id', $id)->whereNull('deleted_at')->find();
    }

    public function existsMobile($mobile, $excludeId = 0)
    {
        if ($mobile === null || $mobile === '') {
            return false;
        }
        $q = Db::name('tm_member')->where('mobile', $mobile)->whereNull('deleted_at');
        if ($excludeId) {
            $q->where('id', '<>', $excludeId);
        }
        return $q->count() > 0;
    }

    public function addRow($data)
    {
        $data['created_at'] = tmNow();
        return Db::name('tm_member')->insertGetId($data);
    }

    public function editRow($id, $data)
    {
        $data['updated_at'] = tmNow();
        return Db::name('tm_member')->where('id', $id)->update($data);
    }

    public function softDelete($id)
    {
        Db::name('tm_member')->where('id', $id)->update(['deleted_at' => tmNow()]);
        return true;
    }

    /**
     * 为成员列表附加答题摘要（答卷数、已提交数、最近得分/批次/等级）。
     * @param array $list
     * @return array
     */
    public function attachPaperSummary($list)
    {
        if (empty($list)) {
            return $list;
        }
        $ids = [];
        foreach ($list as $row) {
            $ids[] = (int)$row['id'];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return $list;
        }

        $stats = Db::name('tm_paper')
            ->where('member_id', 'in', $ids)
            ->field('member_id, COUNT(*) as paper_cnt, SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) as submitted_cnt, MAX(id) as latest_paper_id')
            ->group('member_id')
            ->select();
        $statMap = [];
        $latestIds = [];
        foreach ($stats as $s) {
            $mid = (int)$s['member_id'];
            $statMap[$mid] = $s;
            if (!empty($s['latest_paper_id'])) {
                $latestIds[] = (int)$s['latest_paper_id'];
            }
        }

        $latestMap = [];
        if (!empty($latestIds)) {
            $papers = Db::name('tm_paper')->alias('p')
                ->join('tm_batch b', 'b.id = p.batch_id', 'LEFT')
                ->join('tm_team_result tr', 'tr.batch_id = p.batch_id AND tr.team_id = p.team_id', 'LEFT')
                ->where('p.id', 'in', $latestIds)
                ->field('p.id, p.member_id, p.status, p.personal_score, p.submitted_at, b.name as batch_name, tr.team_score, tr.level_code as team_level_code')
                ->select();
            foreach ($papers as $p) {
                $latestMap[(int)$p['member_id']] = $p;
            }
        }

        $levelNames = Db::name('tm_level_threshold')->column('level_name', 'level_code');

        foreach ($list as &$row) {
            $mid = (int)$row['id'];
            $st = isset($statMap[$mid]) ? $statMap[$mid] : null;
            $row['paper_cnt'] = $st ? (int)$st['paper_cnt'] : 0;
            $row['submitted_cnt'] = $st ? (int)$st['submitted_cnt'] : 0;
            $latest = isset($latestMap[$mid]) ? $latestMap[$mid] : null;
            if (!$latest) {
                $row['latest_batch_name'] = '';
                $row['latest_personal_score'] = null;
                $row['latest_paper_status'] = null;
                $row['latest_paper_status_text'] = '暂无';
                $row['latest_team_score'] = null;
                $row['latest_level_code'] = '';
                $row['latest_level_name'] = '';
                $row['latest_team_level_code'] = '';
                $row['latest_team_level_name'] = '';
                continue;
            }
            $row['latest_batch_name'] = $latest['batch_name'] ?: '';
            $row['latest_personal_score'] = $latest['personal_score'];
            $row['latest_paper_status'] = (int)$latest['status'];
            $row['latest_paper_status_text'] = ((int)$latest['status'] === 1) ? '已提交' : '答题中';
            $row['latest_team_score'] = $latest['team_score'];
            $levelCode = '';
            $levelName = '';
            if ((int)$latest['status'] === 1 && $latest['personal_score'] !== null && $latest['personal_score'] !== '') {
                $lv = \app\index\service\LevelJudgeService::judge($latest['personal_score']);
                if ($lv) {
                    $levelCode = $lv['level_code'];
                    $levelName = $lv['level_name'];
                }
            }
            $row['latest_level_code'] = $levelCode;
            $row['latest_level_name'] = $levelName !== ''
                ? $levelName
                : (($levelCode && isset($levelNames[$levelCode])) ? $levelNames[$levelCode] : '');
            $teamCode = !empty($latest['team_level_code']) ? $latest['team_level_code'] : '';
            $row['latest_team_level_code'] = $teamCode;
            $row['latest_team_level_name'] = ($teamCode && isset($levelNames[$teamCode])) ? $levelNames[$teamCode] : '';
        }
        unset($row);
        return $list;
    }

    /**
     * 成员答卷列表（含批次与班组结果摘要）。
     */
    public function listPapers($memberId)
    {
        $memberId = (int)$memberId;
        $rows = Db::name('tm_paper')->alias('p')
            ->join('tm_batch b', 'b.id = p.batch_id', 'LEFT')
            ->join('tm_team t', 't.id = p.team_id', 'LEFT')
            ->join('tm_team_result tr', 'tr.batch_id = p.batch_id AND tr.team_id = p.team_id', 'LEFT')
            ->where('p.member_id', $memberId)
            ->field('p.id as paper_id, p.batch_id, p.team_id, p.status, p.personal_score, p.submitted_at, p.created_at,
                b.name as batch_name, b.start_time, b.end_time,
                t.name as team_name,
                tr.team_score, tr.level_code as team_level_code, tr.submit_cnt as team_submit_cnt, tr.scored_at')
            ->order('p.id desc')
            ->select();

        $levelNames = Db::name('tm_level_threshold')->column('level_name', 'level_code');
        foreach ($rows as &$row) {
            $row['status'] = (int)$row['status'];
            $row['status_text'] = $row['status'] === 1 ? '已提交' : '答题中';
            $personalLevel = null;
            if ($row['status'] === 1 && $row['personal_score'] !== null && $row['personal_score'] !== '') {
                $personalLevel = \app\index\service\LevelJudgeService::judge($row['personal_score']);
            }
            $row['personal_level_code'] = $personalLevel ? $personalLevel['level_code'] : '';
            $row['personal_level_name'] = $personalLevel ? $personalLevel['level_name'] : '';
            $teamCode = $row['team_level_code'] ?: '';
            $row['team_level_name'] = ($teamCode && isset($levelNames[$teamCode])) ? $levelNames[$teamCode] : '';
            $answered = Db::name('tm_paper_answer')->where('paper_id', (int)$row['paper_id'])->count();
            $row['answered_cnt'] = (int)$answered;
        }
        unset($row);
        return $rows;
    }

    /**
     * 答卷明细：结果摘要 + 逐题作答。
     * @return array|null
     */
    public function paperDetail($paperId, $memberId = 0)
    {
        $q = Db::name('tm_paper')->alias('p')
            ->join('tm_batch b', 'b.id = p.batch_id', 'LEFT')
            ->join('tm_team t', 't.id = p.team_id', 'LEFT')
            ->join('tm_member m', 'm.id = p.member_id', 'LEFT')
            ->join('tm_team_result tr', 'tr.batch_id = p.batch_id AND tr.team_id = p.team_id', 'LEFT')
            ->where('p.id', (int)$paperId)
            ->field('p.*, b.name as batch_name, t.name as team_name, m.name as member_name,
                tr.team_score, tr.level_code as team_level_code, tr.submit_cnt as team_submit_cnt,
                tr.weak_indicator_json, tr.scored_at');
        if ($memberId > 0) {
            $q->where('p.member_id', (int)$memberId);
        }
        $paper = $q->find();
        if (!$paper) {
            return null;
        }

        $personalLevel = null;
        if ((int)$paper['status'] === 1 && $paper['personal_score'] !== null && $paper['personal_score'] !== '') {
            $personalLevel = \app\index\service\LevelJudgeService::judge($paper['personal_score']);
        }
        $levelNames = Db::name('tm_level_threshold')->column('level_name', 'level_code');
        $teamCode = $paper['team_level_code'] ?: '';

        // 先取答卷已作答明细，再按批次快照补齐「每一道题」
        $answeredRows = Db::name('tm_paper_answer')->alias('a')
            ->join('tm_question q', 'q.id = a.question_id', 'LEFT')
            ->join('tm_option o', 'o.id = a.option_id', 'LEFT')
            ->join('tm_indicator i', 'i.id = a.indicator_id', 'LEFT')
            ->where('a.paper_id', (int)$paperId)
            ->field('a.id, a.question_id, a.option_id, a.indicator_id, a.weight, a.score_ratio, a.item_score, a.answered_at,
                q.title as question_title, o.content as option_content, i.name as indicator_name')
            ->order('a.id asc')
            ->select();
        $answerMap = [];
        foreach ($answeredRows as $ar) {
            $answerMap[(int)$ar['question_id']] = $ar;
        }

        $answers = [];
        $snap = Db::name('tm_batch_indicator_snap')
            ->where('batch_id', (int)$paper['batch_id'])
            ->find();
        $snapItems = [];
        if ($snap && !empty($snap['snap_json'])) {
            $snapJson = json_decode($snap['snap_json'], true);
            if (is_array($snapJson) && !empty($snapJson['items']) && is_array($snapJson['items'])) {
                $snapItems = $snapJson['items'];
            }
        }

        if (!empty($snapItems)) {
            $sortNo = 0;
            foreach ($snapItems as $item) {
                $sortNo++;
                $qid = isset($item['question']['id']) ? (int)$item['question']['id'] : 0;
                $ind = isset($item['indicator']) ? $item['indicator'] : [];
                $qinfo = isset($item['question']) ? $item['question'] : [];
                $weight = isset($ind['weight']) ? (float)$ind['weight'] : 0;
                $row = [
                    'sort_no'         => $sortNo,
                    'question_id'     => $qid,
                    'indicator_id'    => isset($ind['id']) ? (int)$ind['id'] : 0,
                    'indicator_name'  => isset($ind['name']) ? $ind['name'] : '',
                    'question_title'  => isset($qinfo['title']) ? $qinfo['title'] : '',
                    'weight'          => $weight,
                    'option_id'       => null,
                    'option_content'  => '未作答',
                    'score_ratio'     => null,
                    'item_score'      => null,
                    'answered_at'     => null,
                    'is_answered'     => 0,
                ];
                if ($qid > 0 && isset($answerMap[$qid])) {
                    $a = $answerMap[$qid];
                    $row['option_id'] = (int)$a['option_id'];
                    $row['option_content'] = $a['option_content'] !== '' && $a['option_content'] !== null
                        ? $a['option_content'] : ('选项#' . $a['option_id']);
                    $row['score_ratio'] = $a['score_ratio'];
                    $row['item_score'] = $a['item_score'];
                    $row['weight'] = $a['weight'] !== null && $a['weight'] !== '' ? $a['weight'] : $weight;
                    $row['answered_at'] = $a['answered_at'];
                    $row['is_answered'] = 1;
                    if (!empty($a['indicator_name'])) {
                        $row['indicator_name'] = $a['indicator_name'];
                    }
                    if (!empty($a['question_title'])) {
                        $row['question_title'] = $a['question_title'];
                    }
                }
                $answers[] = $row;
            }
        } else {
            // 无快照时退回仅展示已答明细
            $sortNo = 0;
            foreach ($answeredRows as $a) {
                $sortNo++;
                $answers[] = [
                    'sort_no'        => $sortNo,
                    'question_id'    => (int)$a['question_id'],
                    'indicator_id'   => (int)$a['indicator_id'],
                    'indicator_name' => $a['indicator_name'],
                    'question_title' => $a['question_title'],
                    'weight'         => $a['weight'],
                    'option_id'      => (int)$a['option_id'],
                    'option_content' => $a['option_content'],
                    'score_ratio'    => $a['score_ratio'],
                    'item_score'     => $a['item_score'],
                    'answered_at'    => $a['answered_at'],
                    'is_answered'    => 1,
                ];
            }
        }

        $answeredCnt = 0;
        foreach ($answers as $a) {
            if (!empty($a['is_answered'])) {
                $answeredCnt++;
            }
        }

        return [
            'paper' => [
                'paper_id'       => (int)$paper['id'],
                'batch_id'       => (int)$paper['batch_id'],
                'batch_name'     => $paper['batch_name'],
                'team_id'        => (int)$paper['team_id'],
                'team_name'      => $paper['team_name'],
                'member_id'      => (int)$paper['member_id'],
                'member_name'    => $paper['member_name'],
                'status'         => (int)$paper['status'],
                'status_text'    => ((int)$paper['status'] === 1) ? '已提交' : '答题中',
                'personal_score' => $paper['personal_score'],
                'personal_level_code' => $personalLevel ? $personalLevel['level_code'] : '',
                'personal_level_name' => $personalLevel ? $personalLevel['level_name'] : '',
                'personal_feature'    => $personalLevel ? $personalLevel['feature_desc'] : '',
                'submitted_at'   => $paper['submitted_at'],
                'created_at'     => $paper['created_at'],
                'team_score'     => $paper['team_score'],
                'team_level_code'=> $teamCode,
                'team_level_name'=> ($teamCode && isset($levelNames[$teamCode])) ? $levelNames[$teamCode] : '',
                'team_submit_cnt'=> $paper['team_submit_cnt'],
                'scored_at'      => $paper['scored_at'],
                'weak_indicators'=> $paper['weak_indicator_json'] ? json_decode($paper['weak_indicator_json'], true) : [],
                'question_cnt'   => count($answers),
                'answered_cnt'   => $answeredCnt,
            ],
            'answers' => $answers,
            'answered_cnt' => $answeredCnt,
            'question_cnt' => count($answers),
        ];
    }
}

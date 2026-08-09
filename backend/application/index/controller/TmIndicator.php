<?php
namespace app\index\controller;

use app\index\service\IndicatorService;
use app\index\service\OpLogService;
use think\Db;
use think\Request;

class TmIndicator extends Base
{
    public function index()
    {
        $versions = Db::name('tm_indicator_version')->order('id desc')->select();
        $this->assign('versions', $versions);

        $reqId = (int)Request::instance()->param('versionId', 0);
        $defaultId = 0;
        if ($reqId > 0) {
            foreach ($versions as $v) {
                if ((int)$v['id'] === $reqId) {
                    $defaultId = $reqId;
                    break;
                }
            }
        }
        if (!$defaultId) {
            foreach ($versions as $v) {
                if ((int)$v['status'] === 0) {
                    $defaultId = $v['id'];
                    break;
                }
            }
        }
        if (!$defaultId) {
            foreach ($versions as $v) {
                if ((int)$v['status'] === 1) {
                    $defaultId = $v['id'];
                    break;
                }
            }
        }
        if (!$defaultId && $versions) {
            $defaultId = $versions[0]['id'];
        }
        $this->assign('defaultVersionId', $defaultId);
        return $this->fetch('tm_indicator/index');
    }

    public function versionMeta()
    {
        $versionId = (int)Request::instance()->param('versionId', 0);
        $ver = IndicatorService::findVersion($versionId);
        if (!$ver) {
            return json(DefaultResponse(404, false, '版本不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', [
            'id'                 => (int)$ver['id'],
            'name'               => $ver['name'],
            'version_no'         => $ver['version_no'],
            'status'             => (int)$ver['status'],
            'status_text'        => [0 => '草稿', 1 => '已发布', 2 => '停用'][(int)$ver['status']] ?? '',
            'can_edit'           => (int)$ver['status'] === 0 ? 1 : 0,
            'question_pick_mode' => $ver['question_pick_mode'],
        ]));
    }

    public function tree()
    {
        $versionId = (int)Request::instance()->param('versionId', 0);
        if ($versionId <= 0) {
            return json(DefaultResponse(400, false, '请选择版本'));
        }
        $rows = Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('status', 1)
            ->order('sort_no asc,id asc')
            ->select();

        $cntMap = Db::name('tm_question')
            ->where('version_id', $versionId)
            ->where('status', 1)
            ->group('indicator_id')
            ->column('count(*)', 'indicator_id');
        foreach ($rows as &$row) {
            $row['question_cnt'] = isset($cntMap[$row['id']]) ? (int)$cntMap[$row['id']] : 0;
        }
        unset($row);
        $tree = $this->buildTree($rows, 0);
        $this->sumQuestionCnt($tree);
        return json(DefaultResponse(0, true, 'ok', $tree));
    }

    public function questions()
    {
        $req = Request::instance();
        $versionId = (int)$req->param('versionId', 0);
        $indicatorId = (int)$req->param('indicatorId', 0);
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 20);

        $apply = function ($query) use ($versionId, $indicatorId) {
            if ($versionId > 0) {
                $query->where('q.version_id', $versionId);
            }
            if ($indicatorId > 0) {
                $ids = $this->collectIndicatorIds($indicatorId);
                $query->where('q.indicator_id', 'in', $ids);
            }
            return $query;
        };

        $count = $apply(Db::name('tm_question')->alias('q'))->count();
        $list = $apply(
            Db::name('tm_question')->alias('q')
                ->join('tm_indicator i', 'i.id = q.indicator_id', 'LEFT')
                ->field('q.*, i.name as indicator_name, i.weight, i.level_no')
        )->order('q.sort_no asc,q.id asc')->page($page, $limit)->select();

        foreach ($list as &$row) {
            $row['options'] = Db::name('tm_option')->where('question_id', $row['id'])->order('sort_no asc')->select();
            $measure = Db::name('tm_empower_measure')->where('indicator_id', $row['indicator_id'])->find();
            $row['measure'] = $measure ? $measure['content'] : '';
        }
        unset($row);
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    public function questionDetail()
    {
        $id = (int)Request::instance()->param('id', 0);
        $q = IndicatorService::questionDetail($id);
        if (!$q) {
            return json(DefaultResponse(404, false, '题目不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', $q));
    }

    public function saveIndicator()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $versionId = (int)$req->param('version_id', 0);
        list($ok, $msg, $id) = IndicatorService::saveIndicator($versionId, [
            'id'        => (int)$req->param('id', 0),
            'parent_id' => (int)$req->param('parent_id', 0),
            'name'      => $req->param('name', '', 'trim'),
            'code'      => $req->param('code', '', 'trim'),
            'weight'    => $req->param('weight', 0),
            'sort_no'   => (int)$req->param('sort_no', 0),
            'status'    => (int)$req->param('status', 1),
        ]);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator', 'save', $id, ['version_id' => $versionId]);
        return json(DefaultResponse(0, true, $msg, ['id' => $id]));
    }

    public function deleteIndicator()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $versionId = (int)$req->param('version_id', 0);
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = IndicatorService::deleteIndicator($versionId, $id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator', 'delete', $id, ['version_id' => $versionId]);
        return json(DefaultResponse(0, true, $msg));
    }

    public function saveQuestion()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $versionId = (int)$req->param('version_id', 0);
        $options = $req->param('options');
        list($ok, $msg, $id) = IndicatorService::saveQuestion($versionId, [
            'id'           => (int)$req->param('id', 0),
            'indicator_id' => (int)$req->param('indicator_id', 0),
            'title'        => $req->param('title', '', 'trim'),
            'remark'       => $req->param('remark', '', 'trim'),
            'sort_no'      => (int)$req->param('sort_no', 0),
            'status'       => (int)$req->param('status', 1),
            'options'      => $options,
        ]);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('question', 'save', $id, ['version_id' => $versionId]);
        return json(DefaultResponse(0, true, $msg, ['id' => $id]));
    }

    public function deleteQuestion()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $versionId = (int)$req->param('version_id', 0);
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = IndicatorService::deleteQuestion($versionId, $id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('question', 'delete', $id, ['version_id' => $versionId]);
        return json(DefaultResponse(0, true, $msg));
    }

    private function collectIndicatorIds($id)
    {
        $ids = [$id];
        $children = Db::name('tm_indicator')->where('parent_id', $id)->column('id');
        foreach ($children as $cid) {
            $ids = array_merge($ids, $this->collectIndicatorIds($cid));
        }
        return $ids;
    }

    private function buildTree($rows, $parentId)
    {
        $tree = [];
        foreach ($rows as $row) {
            if ((int)$row['parent_id'] !== (int)$parentId) {
                continue;
            }
            $node = $row;
            $node['title'] = $row['name'] . (isset($row['question_cnt']) && (int)$row['level_no'] === 3
                ? ('（' . (int)$row['question_cnt'] . '题）') : '');
            if ((int)$row['level_no'] < 3 && isset($row['weight'])) {
                $node['title'] = $row['name'] . ' [' . rtrim(rtrim(number_format((float)$row['weight'], 2, '.', ''), '0'), '.') . ']';
            }
            $node['children'] = $this->buildTree($rows, $row['id']);
            $tree[] = $node;
        }
        return $tree;
    }

    private function sumQuestionCnt(&$nodes)
    {
        $sum = 0;
        foreach ($nodes as &$n) {
            $childSum = 0;
            if (!empty($n['children'])) {
                $childSum = $this->sumQuestionCnt($n['children']);
            }
            $self = isset($n['question_cnt']) ? (int)$n['question_cnt'] : 0;
            $n['question_cnt'] = $self + $childSum;
            $sum += $n['question_cnt'];
        }
        unset($n);
        return $sum;
    }
}

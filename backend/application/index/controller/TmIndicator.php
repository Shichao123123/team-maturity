<?php
namespace app\index\controller;

use think\Db;
use think\Request;

class TmIndicator extends Base
{
    public function index()
    {
        $versions = Db::name('tm_indicator_version')->order('id desc')->select();
        $this->assign('versions', $versions);

        // 支持从「指标版本」带 versionId 跳转查看题库
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

        // 预留多题：挂上每个三级（及汇总到上级）的题池数量
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
        return json(DefaultResponseList(0, $count, 'ok', $list));
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
            $node['title'] = $row['name'];
            $node['children'] = $this->buildTree($rows, $row['id']);
            $tree[] = $node;
        }
        return $tree;
    }

    /** 上级节点汇总下级题池数量（便于后期看「一指标多题」） */
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

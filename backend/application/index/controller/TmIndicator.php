<?php
namespace app\index\controller;

use app\index\service\IndicatorService;
use app\index\service\OpLogService;
use think\Db;
use think\Request;

/**
 * 指标与题库：指标树浏览、草稿维护、题目/选项增删改。
 */
class TmIndicator extends Base
{
    /**
     * 指标与题库页面；优先选中 URL 指定版本，否则草稿，再否则已发布版本。
     *
     * @return mixed
     */
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

    /**
     * 版本元信息：状态与是否可编辑（仅草稿可改）。
     *
     * @return \think\response\Json
     */
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

    /**
     * 指标树 JSON（含各级题目数量汇总）。
     *
     * @return \think\response\Json
     */
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

        // 三级指标题池数量，再由 sumQuestionCnt 向上汇总
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
        $this->sumScoreTotal($tree);
        $this->decorateTreeTitles($tree);
        return json(DefaultResponse(0, true, 'ok', $tree));
    }

    /**
     * 题目分页列表（含选项与赋能措施摘要）。
     *
     * @return \think\response\Json Layui 分页格式
     */
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
                // 选中非叶子时，包含其全部下级指标下的题目
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

    /**
     * 单题详情（含全部选项，供编辑回填）。
     *
     * @return \think\response\Json
     */
    public function questionDetail()
    {
        $id = (int)Request::instance()->param('id', 0);
        $q = IndicatorService::questionDetail($id);
        if (!$q) {
            return json(DefaultResponse(404, false, '题目不存在'));
        }
        return json(DefaultResponse(0, true, 'ok', $q));
    }

    /**
     * 新增或更新指标节点（仅草稿版本）。
     *
     * @return \think\response\Json
     */
    public function saveIndicator()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $versionId = (int)$req->param('version_id', 0);
        list($ok, $msg, $id) = IndicatorService::saveIndicator($versionId, [
            'id'          => (int)$req->param('id', 0),
            'name'        => $req->param('name', '', 'trim'),
            'description' => $req->param('description', '', 'trim'),
            'code'        => $req->param('code', '', 'trim'),
            'weight'      => $req->param('weight', 0),
            'sort_no'     => (int)$req->param('sort_no', 0),
            'status'      => (int)$req->param('status', 1),
        ]);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator', 'save', $id, ['version_id' => $versionId]);
        return json(DefaultResponse(0, true, $msg, ['id' => $id]));
    }

    /**
     * 删除指标（须无下级、无题目）。
     *
     * @return \think\response\Json
     */
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

    /**
     * 保存题目及选项（options 可为 JSON 字符串）。
     *
     * @return \think\response\Json
     */
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

    /**
     * 单独更新选项权重 API（POST）。
     * 参数：version_id、option_id、score_ratio（0～1，前端可称「权重」）。
     *
     * @return \think\response\Json
     */
    public function saveOptionWeight()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $versionId = (int)$req->param('version_id', 0);
        $optionId = (int)$req->param('option_id', 0);
        $ratio = $req->param('score_ratio', $req->param('weight', 0));
        list($ok, $msg) = IndicatorService::updateOptionWeight($versionId, $optionId, $ratio);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('option', 'weight', $optionId, [
            'version_id'  => $versionId,
            'score_ratio' => (float)$ratio,
        ]);
        return json(DefaultResponse(0, true, $msg, [
            'option_id'   => $optionId,
            'score_ratio' => (float)$ratio,
        ]));
    }

    /**
     * 删除题目及其选项。
     *
     * @return \think\response\Json
     */
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

    /**
     * 收集某指标及其全部子孙指标 ID。
     *
     * @param int $id 指标 ID
     * @return int[]
     */
    private function collectIndicatorIds($id)
    {
        $ids = [$id];
        $children = Db::name('tm_indicator')->where('parent_id', $id)->column('id');
        foreach ($children as $cid) {
            $ids = array_merge($ids, $this->collectIndicatorIds($cid));
        }
        return $ids;
    }

    /**
     * 将扁平指标行组装为树（标题在汇总后 decorateTreeTitles 生成）。
     *
     * @param array $rows     指标行
     * @param int   $parentId 父节点 ID
     * @return array
     */
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

    /**
     * 递归汇总下级题数到当前节点 question_cnt。
     *
     * @param array $nodes 树节点（引用）
     * @return int 本层合计题数
     */
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

    /**
     * 递归汇总满分：三级=自身权重（题目满分），一/二级=下级合计。
     *
     * @param array $nodes 树节点（引用）
     * @return float 本层合计满分
     */
    private function sumScoreTotal(&$nodes)
    {
        $sum = 0.0;
        foreach ($nodes as &$n) {
            $levelNo = isset($n['level_no']) ? (int)$n['level_no'] : 0;
            if ($levelNo === 3) {
                $n['score_total'] = isset($n['weight']) ? (float)$n['weight'] : 0.0;
            } else {
                $childSum = 0.0;
                if (!empty($n['children'])) {
                    $childSum = $this->sumScoreTotal($n['children']);
                }
                $n['score_total'] = $childSum;
            }
            $sum += (float)$n['score_total'];
        }
        unset($n);
        return $sum;
    }

    /**
     * 格式化树节点标题：各级展示 [满分]，三级额外展示题数。
     *
     * @param array $nodes 树节点（引用）
     * @return void
     */
    private function decorateTreeTitles(&$nodes)
    {
        foreach ($nodes as &$n) {
            $name = isset($n['name']) ? $n['name'] : '';
            $score = isset($n['score_total']) ? (float)$n['score_total'] : 0.0;
            $w = rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');
            if ($w === '') {
                $w = '0';
            }
            $levelNo = isset($n['level_no']) ? (int)$n['level_no'] : 0;
            if ($levelNo === 3) {
                $qCnt = isset($n['question_cnt']) ? (int)$n['question_cnt'] : 0;
                $n['title'] = $name . ' [' . $w . ']（' . $qCnt . '题）';
            } else {
                $n['title'] = $name . ' [' . $w . ']';
            }
            if (!empty($n['children'])) {
                $this->decorateTreeTitles($n['children']);
            }
        }
        unset($n);
    }
}

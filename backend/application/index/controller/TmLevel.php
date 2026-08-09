<?php
namespace app\index\controller;

use think\Db;
use think\Request;

class TmLevel extends Base
{
    private $levelNames = [
        'passive'     => '被动型',
        'dependent'   => '依赖型',
        'independent' => '自立型',
        'essential'   => '本质安全型',
    ];

    /**
     * 成熟度等级配置页，加载等级名称与三级指标列表。
     *
     * @return mixed 渲染 tm_level/index 视图
     */
    public function index()
    {
        $this->assign('levelNames', $this->levelNames);
        $versionId = (int)Db::name('tm_indicator_version')
            ->where('status', 1)
            ->order('id desc')
            ->value('id');
        $indicators = [];
        if ($versionId) {
            $indicators = Db::name('tm_indicator')
                ->where('version_id', $versionId)
                ->where('level_no', 3)
                ->where('status', 1)
                ->order('sort_no asc,id asc')
                ->field('id,name')
                ->select();
        }
        $this->assign('indicators', $indicators);
        return $this->fetch('tm_level/index');
    }

    /**
     * 等级分值阈值列表 API（含区间展示文本）。
     *
     * @return \think\response\Json 返回全部等级阈值配置
     */
    public function thresholds()
    {
        $list = Db::name('tm_level_threshold')->order('sort_no asc,id asc')->select();
        foreach ($list as &$row) {
            $row['range_text'] = $this->formatRange($row['min_score'], $row['max_score']);
        }
        unset($row);
        return json(DefaultResponse(0, true, 'ok', $list));
    }

    /**
     * 批量保存等级分值阈值 API（含区间重叠校验）。
     *
     * @return \think\response\Json 保存结果
     */
    public function saveThresholds()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }

        $raw = $req->param('items');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || empty($raw)) {
            $body = json_decode($req->getContent(), true);
            if (isset($body['items']) && is_array($body['items'])) {
                $raw = $body['items'];
            }
        }
        if (!is_array($raw) || empty($raw)) {
            return json(DefaultResponse(400, false, '无阈值数据'));
        }

        $rows = [];
        foreach ($raw as $item) {
            $id = isset($item['id']) ? (int)$item['id'] : 0;
            $code = isset($item['level_code']) ? trim($item['level_code']) : '';
            $name = isset($item['level_name']) ? trim($item['level_name']) : '';
            $min = isset($item['min_score']) ? $item['min_score'] : null;
            $max = array_key_exists('max_score', $item) ? $item['max_score'] : null;
            if ($max === '' || $max === 'null') {
                $max = null;
            }
            $desc = isset($item['feature_desc']) ? trim($item['feature_desc']) : '';
            $sort = isset($item['sort_no']) ? (int)$item['sort_no'] : 0;
            $status = isset($item['status']) ? (int)$item['status'] : 1;

            if ($id <= 0 || $code === '' || $name === '' || $min === null || $min === '') {
                return json(DefaultResponse(400, false, '等级名称与最低分不能为空'));
            }
            $min = round((float)$min, 2);
            $max = $max === null ? null : round((float)$max, 2);
            if ($max !== null && $max < $min) {
                return json(DefaultResponse(400, false, $name . '：最高分不能小于最低分'));
            }
            $rows[] = [
                'id'           => $id,
                'level_code'   => $code,
                'level_name'   => $name,
                'min_score'    => $min,
                'max_score'    => $max,
                'feature_desc' => $desc,
                'sort_no'      => $sort,
                'status'       => $status,
            ];
        }

        $err = $this->validateRanges($rows);
        if ($err) {
            return json(DefaultResponse(400, false, $err));
        }

        Db::startTrans();
        try {
            foreach ($rows as $r) {
                Db::name('tm_level_threshold')->where('id', $r['id'])->update([
                    'level_name'   => $r['level_name'],
                    'min_score'    => $r['min_score'],
                    'max_score'    => $r['max_score'],
                    'feature_desc' => $r['feature_desc'],
                    'sort_no'      => $r['sort_no'],
                    'status'       => $r['status'],
                ]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(DefaultResponse(500, false, $e->getMessage()));
        }

        return json(DefaultResponse(0, true, '等级阈值已保存'));
    }

    /**
     * 赋能措施分页列表 API（按三级指标聚合各等级措施内容）。
     *
     * @return \think\response\Json Layui 分页格式的措施矩阵列表
     */
    public function measures()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 10);
        $keyword = $req->param('keyword', '', 'trim');

        $versionId = (int)Db::name('tm_indicator_version')
            ->where('status', 1)
            ->order('id desc')
            ->value('id');
        if ($versionId <= 0) {
            return json(DefaultResponseList(0, 0, 'ok', []));
        }

        $filterIds = null;
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $idsByName = Db::name('tm_indicator')
                ->where('version_id', $versionId)
                ->where('level_no', 3)
                ->where('name', 'like', $like)
                ->column('id');
            $idsByMeasure = Db::name('tm_empower_measure')->alias('m')
                ->join('tm_indicator i', 'i.id = m.indicator_id')
                ->where('i.version_id', $versionId)
                ->where('i.level_no', 3)
                ->where('m.title|m.content', 'like', $like)
                ->column('m.indicator_id');
            $filterIds = array_values(array_unique(array_merge($idsByName ?: [], $idsByMeasure ?: [])));
            if (!$filterIds) {
                return json(DefaultResponseList(0, 0, 'ok', []));
            }
        }

        $countQ = Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 3)
            ->where('status', 1);
        if ($filterIds !== null) {
            $countQ->where('id', 'in', $filterIds);
        }
        $count = $countQ->count();

        $listQ = Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 3)
            ->where('status', 1);
        if ($filterIds !== null) {
            $listQ->where('id', 'in', $filterIds);
        }
        $indicators = $listQ->order('sort_no asc,id asc')->page($page, $limit)->select();
        if (!$indicators) {
            return json(DefaultResponseList(0, $count, 'ok', []));
        }

        $indIds = [];
        foreach ($indicators as $ind) {
            $indIds[] = (int)$ind['id'];
        }
        $measureRows = Db::name('tm_empower_measure')
            ->where('indicator_id', 'in', $indIds)
            ->select();
        $byInd = [];
        foreach ($measureRows as $m) {
            $byInd[(int)$m['indicator_id']][$m['level_code']] = $m;
        }

        $levelCodes = array_keys($this->levelNames);
        $list = [];
        foreach ($indicators as $ind) {
            $iid = (int)$ind['id'];
            $row = [
                'indicator_id'   => $iid,
                'indicator_name' => $ind['name'],
            ];
            foreach ($levelCodes as $code) {
                $m = isset($byInd[$iid][$code]) ? $byInd[$iid][$code] : null;
                $row[$code . '_id'] = $m ? (int)$m['id'] : 0;
                $row[$code . '_content'] = $m ? $m['content'] : '暂无';
                $row[$code . '_title'] = $m ? $m['title'] : $ind['name'];
            }
            $list[] = $row;
        }

        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    /**
     * 新增或编辑单条赋能措施 API。
     *
     * @return \think\response\Json 保存成功时返回 id（新增）
     */
    public function saveMeasure()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }

        $id = (int)$req->param('id', 0);
        $levelCode = $req->param('level_code', '', 'trim');
        $title = $req->param('title', '', 'trim');
        $content = $req->param('content', '', 'trim');
        $indicatorId = $req->param('indicator_id', '');

        if ($levelCode === '' || !isset($this->levelNames[$levelCode])) {
            return json(DefaultResponse(400, false, '请选择有效等级'));
        }
        if ($title === '' || $content === '') {
            return json(DefaultResponse(400, false, '标题与内容不能为空'));
        }

        $indId = null;
        if ($indicatorId !== '' && $indicatorId !== null && (int)$indicatorId > 0) {
            $indId = (int)$indicatorId;
            $exists = Db::name('tm_indicator')->where('id', $indId)->find();
            if (!$exists) {
                return json(DefaultResponse(400, false, '关联指标不存在'));
            }
        }

        $data = [
            'level_code'   => $levelCode,
            'indicator_id' => $indId,
            'title'        => $title,
            'content'      => $content,
        ];

        if ($id > 0) {
            $data['updated_at'] = tmNow();
            Db::name('tm_empower_measure')->where('id', $id)->update($data);
            return json(DefaultResponse(0, true, '措施已更新'));
        }

        $data['sort_no'] = 0;
        $data['status'] = 1;
        $data['created_at'] = tmNow();
        $newId = Db::name('tm_empower_measure')->insertGetId($data);
        return json(DefaultResponse(0, true, '措施已新增', ['id' => $newId]));
    }

    /**
     * 删除单条赋能措施 API。
     *
     * @return \think\response\Json 删除结果
     */
    public function deleteMeasure()
    {
        $id = (int)Request::instance()->param('id', 0);
        if ($id <= 0) {
            return json(DefaultResponse(400, false, '参数错误'));
        }
        Db::name('tm_empower_measure')->where('id', $id)->delete();
        return json(DefaultResponse(0, true, '已删除'));
    }

    /**
     * 格式化等级分值区间为可读文本。
     *
     * @param mixed $min 最低分
     * @param mixed $max 最高分，null 表示无上限
     * @return string 如「70 ~ 89.99 分」或「90 ~ 100 分」
     */
    private function formatRange($min, $max)
    {
        $min = rtrim(rtrim(number_format((float)$min, 2, '.', ''), '0'), '.');
        if ($max === null || $max === '') {
            return $min . ' ~ 100 分';
        }
        $max = rtrim(rtrim(number_format((float)$max, 2, '.', ''), '0'), '.');
        return $min . ' ~ ' . $max . ' 分';
    }

    /**
     * 校验启用等级分值区间不重叠（按分数轴，允许相邻首尾相接）。
     *
     * @param array $rows 待校验的等级阈值行
     * @return string 校验通过返回空字符串，失败返回错误提示
     */
    private function validateRanges(array $rows)
    {
        $enabled = array_values(array_filter($rows, function ($r) {
            return (int)$r['status'] === 1;
        }));
        usort($enabled, function ($a, $b) {
            if ($a['min_score'] == $b['min_score']) {
                return $a['sort_no'] - $b['sort_no'];
            }
            return $a['min_score'] < $b['min_score'] ? -1 : 1;
        });

        for ($i = 0; $i < count($enabled); $i++) {
            $a = $enabled[$i];
            $aMax = $a['max_score'];
            for ($j = $i + 1; $j < count($enabled); $j++) {
                $b = $enabled[$j];
                $bMax = $b['max_score'];
                // 区间按闭区间检测重叠；允许 a.max+0.01 == b.min 这种相邻
                $aHi = $aMax === null ? PHP_FLOAT_MAX : (float)$aMax;
                $bHi = $bMax === null ? PHP_FLOAT_MAX : (float)$bMax;
                $aLo = (float)$a['min_score'];
                $bLo = (float)$b['min_score'];
                if ($aLo <= $bHi && $bLo <= $aHi) {
                    // 若刚好首尾相接（如 69.99 与 70.00）视为允许
                    if ($aMax !== null && abs((float)$aMax + 0.01 - $bLo) < 0.001) {
                        continue;
                    }
                    if ($bMax !== null && abs((float)$bMax + 0.01 - $aLo) < 0.001) {
                        continue;
                    }
                    // 自立型 90 与本质 90.01 相邻
                    if ($aMax !== null && abs((float)$aMax - $bLo) < 0.011) {
                        continue;
                    }
                    if ($bMax !== null && abs((float)$bMax - $aLo) < 0.011) {
                        continue;
                    }
                    return '等级「' . $a['level_name'] . '」与「' . $b['level_name'] . '」分值区间重叠，请调整';
                }
            }
        }
        return '';
    }
}

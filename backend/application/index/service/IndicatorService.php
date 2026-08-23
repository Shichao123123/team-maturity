<?php
namespace app\index\service;

use think\Db;

/**
 * 指标版本 / 指标树 / 题库维护。
 * 约定：仅草稿(status=0)可改指标与题目；已发布只读，需 copy 后再改。
 */
class IndicatorService
{
    /**
     * 根据 ID 查询指标版本记录。
     *
     * @param int $id 版本 ID
     * @return array|null 版本行数据；不存在时返回 null
     */
    public static function findVersion($id)
    {
        return Db::name('tm_indicator_version')->where('id', (int)$id)->find();
    }

    /**
     * 判断指定版本是否为草稿（status=0）。
     *
     * @param int $versionId 版本 ID
     * @return bool 存在且为草稿时 true
     */
    public static function isDraft($versionId)
    {
        $v = self::findVersion($versionId);
        return $v && (int)$v['status'] === 0;
    }

    /**
     * 断言版本存在且为草稿，供写操作前置校验。
     *
     * @param int $versionId 版本 ID
     * @return array [bool ok, string msg, array|null version] 成功时第三项为版本记录
     */
    public static function assertDraft($versionId)
    {
        $v = self::findVersion($versionId);
        if (!$v) {
            return [false, '版本不存在'];
        }
        if ((int)$v['status'] !== 0) {
            return [false, '仅草稿版本可编辑；请先复制为新草稿'];
        }
        return [true, 'ok', $v];
    }

    /**
     * 创建新的草稿指标版本。
     *
     * @param array $data 版本字段：name、version_no 必填；remark、question_pick_mode 可选
     * @return array [bool ok, string msg, int id] 成功时 id 为新版本 ID，失败时为 0
     */
    public static function createVersion(array $data)
    {
        $name = isset($data['name']) ? trim($data['name']) : '';
        $versionNo = isset($data['version_no']) ? trim($data['version_no']) : '';
        $remark = isset($data['remark']) ? trim($data['remark']) : '';
        $mode = isset($data['question_pick_mode']) ? trim($data['question_pick_mode']) : 'random';
        if ($name === '') {
            return [false, '请填写版本名称', 0];
        }
        if ($versionNo === '') {
            return [false, '请填写版本号', 0];
        }
        if (!in_array($mode, ['first', 'random', 'sequential'], true)) {
            $mode = 'random';
        }
        if (Db::name('tm_indicator_version')->where('version_no', $versionNo)->find()) {
            return [false, '版本号已存在', 0];
        }
        $now = tmNow();
        $id = (int)Db::name('tm_indicator_version')->insertGetId([
            'name'               => $name,
            'version_no'         => $versionNo,
            'status'             => 0,
            'remark'             => $remark !== '' ? $remark : null,
            'question_pick_mode' => $mode,
            'created_at'         => $now,
        ]);
        return [true, '创建成功', $id];
    }

    /**
     * 更新草稿版本的基本信息（名称、版本号、备注、抽题模式）。
     *
     * @param int   $id   版本 ID
     * @param array $data 待更新字段：name、version_no、remark、question_pick_mode
     * @return array [bool ok, string msg]
     */
    public static function updateVersion($id, array $data)
    {
        list($ok, $msg, $ver) = self::assertDraft($id);
        if (!$ok) {
            return [$ok, $msg];
        }
        $name = isset($data['name']) ? trim($data['name']) : '';
        $versionNo = isset($data['version_no']) ? trim($data['version_no']) : '';
        $remark = isset($data['remark']) ? trim($data['remark']) : '';
        $mode = isset($data['question_pick_mode']) ? trim($data['question_pick_mode']) : $ver['question_pick_mode'];
        if ($name === '' || $versionNo === '') {
            return [false, '版本名称与版本号不能为空'];
        }
        if (!in_array($mode, ['first', 'random', 'sequential'], true)) {
            $mode = 'random';
        }
        $dup = Db::name('tm_indicator_version')
            ->where('version_no', $versionNo)
            ->where('id', '<>', (int)$id)
            ->find();
        if ($dup) {
            return [false, '版本号已存在'];
        }
        Db::name('tm_indicator_version')->where('id', (int)$id)->update([
            'name'               => $name,
            'version_no'         => $versionNo,
            'remark'             => $remark !== '' ? $remark : null,
            'question_pick_mode' => $mode,
            'updated_at'         => tmNow(),
        ]);
        return [true, '保存成功'];
    }

    /**
     * 发布前完整校验：权重口径（与算分一致）、三级指标、抽题规则、题目选项数量。
     *
     * 算分使用三级指标 weight 直接相乘累加，故必须以三级权重合计 100 为准；
     * 同时校验一级合计 100，以及各一级下挂三级权重合计等于该一级权重。
     * （二级权重在现有题库中可为 0，不作父子等权强制。）
     *
     * @param int $versionId 版本 ID
     * @return array string[] 错误信息列表，空数组表示通过
     */
    public static function validateForPublish($versionId)
    {
        $errors = [];
        $versionId = (int)$versionId;

        // 三级指标权重之和须为 100（与 ScoreService 百分制口径一致）
        $l3Sum = (float)Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 3)
            ->where('status', 1)
            ->sum('weight');
        if (abs($l3Sum - 100) > 0.05) {
            $errors[] = '三级指标权重之和应为 100（当前 ' . round($l3Sum, 2) . '，算分按三级权重累加）';
        }

        // 一级指标启用项权重之和须为 100（展示/结构口径）
        $l1Sum = (float)Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 1)
            ->where('status', 1)
            ->sum('weight');
        if (abs($l1Sum - 100) > 0.05) {
            $errors[] = '一级指标权重之和应为 100（当前 ' . round($l1Sum, 2) . '）';
        }

        // 各一级下：子孙三级权重合计应等于该一级权重
        $l1List = Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 1)
            ->where('status', 1)
            ->field('id, name, weight')
            ->select();
        foreach ($l1List as $l1) {
            $l2Ids = Db::name('tm_indicator')
                ->where('version_id', $versionId)
                ->where('parent_id', (int)$l1['id'])
                ->where('status', 1)
                ->column('id');
            $childL3Sum = 0.0;
            if (!empty($l2Ids)) {
                $childL3Sum = (float)Db::name('tm_indicator')
                    ->where('version_id', $versionId)
                    ->where('parent_id', 'in', $l2Ids)
                    ->where('level_no', 3)
                    ->where('status', 1)
                    ->sum('weight');
            }
            if (abs($childL3Sum - (float)$l1['weight']) > 0.05) {
                $errors[] = '一级「' . $l1['name'] . '」权重为 ' . round((float)$l1['weight'], 2)
                    . '，其下三级权重合计为 ' . round($childL3Sum, 2);
            }
        }

        // 至少存在一个启用的三级指标
        $l3Cnt = (int)Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 3)
            ->where('status', 1)
            ->count();
        if ($l3Cnt < 1) {
            $errors[] = '至少需要一个启用的三级指标';
        }

        // 委托 QuestionPickService 校验各三级指标下的题目配置
        $errors = array_merge($errors, QuestionPickService::validateVersionQuestions($versionId));

        // 每道启用题目至少 2 个选项
        $questions = Db::name('tm_question')
            ->where('version_id', $versionId)
            ->where('status', 1)
            ->field('id, title')
            ->select();
        foreach ($questions as $q) {
            $optCnt = (int)Db::name('tm_option')->where('question_id', (int)$q['id'])->count();
            if ($optCnt < 2) {
                $title = mb_substr($q['title'], 0, 20);
                $errors[] = '题目「' . $title . '」至少需要 2 个选项';
            }
        }
        return $errors;
    }

    /**
     * 校验通过后发布版本（status 置为 1）。
     *
     * @param int $versionId 版本 ID
     * @return array [bool ok, string msg]
     */
    public static function publish($versionId)
    {
        $ver = self::findVersion($versionId);
        if (!$ver) {
            return [false, '版本不存在'];
        }
        if ((int)$ver['status'] === 1) {
            return [false, '版本已发布'];
        }
        if ((int)$ver['status'] === 2) {
            return [false, '已停用版本不可直接发布，请先复制为草稿'];
        }
        $errors = self::validateForPublish($versionId);
        if (!empty($errors)) {
            return [false, implode('；', $errors)];
        }
        Db::name('tm_indicator_version')->where('id', (int)$versionId)->update([
            'status'       => 1,
            'published_at' => tmNow(),
            'updated_at'   => tmNow(),
        ]);
        return [true, '发布成功'];
    }

    /**
     * 停用已发布版本（status 置为 2）；草稿不可停用。
     *
     * @param int $versionId 版本 ID
     * @return array [bool ok, string msg]
     */
    public static function disable($versionId)
    {
        $ver = self::findVersion($versionId);
        if (!$ver) {
            return [false, '版本不存在'];
        }
        if ((int)$ver['status'] === 0) {
            return [false, '草稿请直接编辑或删除，无需停用'];
        }
        Db::name('tm_indicator_version')->where('id', (int)$versionId)->update([
            'status'     => 2,
            'updated_at' => tmNow(),
        ]);
        return [true, '已停用'];
    }

    /**
     * 删除指标版本（草稿或未被批次引用的停用版）；级联清理指标/题目/选项/措施。
     * 已发布版本不可删（请先停用）；有批次引用时不可删。
     *
     * @param int $versionId 版本 ID
     * @return array [bool ok, string msg]
     */
    public static function deleteVersion($versionId)
    {
        $versionId = (int)$versionId;
        $ver = self::findVersion($versionId);
        if (!$ver) {
            return [false, '版本不存在'];
        }
        if ((int)$ver['status'] === 1) {
            return [false, '已发布版本不可删除，请先停用'];
        }
        $batchCnt = (int)Db::name('tm_batch')
            ->where('version_id', $versionId)
            ->whereNull('deleted_at')
            ->count();
        if ($batchCnt > 0) {
            return [false, '该版本已被 ' . $batchCnt . ' 个批次引用，无法删除'];
        }

        Db::startTrans();
        try {
            $qIds = Db::name('tm_question')->where('version_id', $versionId)->column('id');
            if (!empty($qIds)) {
                Db::name('tm_option')->where('question_id', 'in', $qIds)->delete();
            }
            Db::name('tm_question')->where('version_id', $versionId)->delete();

            $indIds = Db::name('tm_indicator')->where('version_id', $versionId)->column('id');
            if (!empty($indIds)) {
                Db::name('tm_empower_measure')->where('indicator_id', 'in', $indIds)->delete();
            }
            Db::name('tm_indicator')->where('version_id', $versionId)->delete();
            Db::name('tm_indicator_version')->where('id', $versionId)->delete();

            Db::commit();
            return [true, '已删除'];
        } catch (\Exception $e) {
            Db::rollback();
            return [false, '删除失败：' . $e->getMessage()];
        }
    }

    /**
     * 深拷贝版本（指标树、题目、选项、赋能措施），生成新草稿。
     *
     * @param int    $sourceId      源版本 ID
     * @param string $newName       新版本名称，空则自动加「（副本）」
     * @param string $newVersionNo  新版本号，空则自动生成带时间戳的后缀
     * @return array [bool ok, string msg, int newId] 成功时 newId 为新版本 ID，失败时为 0
     */
    public static function copyVersion($sourceId, $newName = '', $newVersionNo = '')
    {
        $src = self::findVersion($sourceId);
        if (!$src) {
            return [false, '源版本不存在', 0];
        }
        $newName = trim((string)$newName);
        $newVersionNo = trim((string)$newVersionNo);
        if ($newName === '') {
            $newName = $src['name'] . '（副本）';
        }
        if ($newVersionNo === '') {
            $newVersionNo = $src['version_no'] . '-copy-' . date('YmdHis');
        }
        if (Db::name('tm_indicator_version')->where('version_no', $newVersionNo)->find()) {
            return [false, '版本号已存在', 0];
        }

        $now = tmNow();
        Db::startTrans();
        try {
            $newId = (int)Db::name('tm_indicator_version')->insertGetId([
                'name'               => $newName,
                'version_no'         => $newVersionNo,
                'status'             => 0,
                'remark'             => $src['remark'],
                'question_pick_mode' => $src['question_pick_mode'] ?: 'random',
                'created_at'         => $now,
            ]);

            // 按层级顺序复制指标，维护 oldId => newId 映射以重挂 parent_id
            $inds = Db::name('tm_indicator')
                ->where('version_id', (int)$sourceId)
                ->order('level_no asc, sort_no asc, id asc')
                ->select();
            $idMap = [0 => 0];
            foreach ($inds as $ind) {
                $oldId = (int)$ind['id'];
                $parentOld = (int)$ind['parent_id'];
                $newParent = isset($idMap[$parentOld]) ? $idMap[$parentOld] : 0;
                $nid = (int)Db::name('tm_indicator')->insertGetId([
                    'version_id'  => $newId,
                    'parent_id'   => $newParent,
                    'level_no'    => (int)$ind['level_no'],
                    'code'        => $ind['code'],
                    'name'        => $ind['name'],
                    'description' => isset($ind['description']) ? $ind['description'] : null,
                    'weight'      => $ind['weight'],
                    'sort_no'     => (int)$ind['sort_no'],
                    'status'      => (int)$ind['status'],
                    'created_at'  => $now,
                ]);
                $idMap[$oldId] = $nid;
            }

            // 复制题目与选项，indicator_id 与 question_id 均走映射表
            $questions = Db::name('tm_question')->where('version_id', (int)$sourceId)->select();
            $qMap = [];
            foreach ($questions as $q) {
                $oldQid = (int)$q['id'];
                $oldInd = (int)$q['indicator_id'];
                if (!isset($idMap[$oldInd])) {
                    continue;
                }
                $nqid = (int)Db::name('tm_question')->insertGetId([
                    'version_id'   => $newId,
                    'indicator_id' => $idMap[$oldInd],
                    'title'        => $q['title'],
                    'remark'       => $q['remark'],
                    'sort_no'      => (int)$q['sort_no'],
                    'status'       => (int)$q['status'],
                    'created_at'   => $now,
                ]);
                $qMap[$oldQid] = $nqid;
                $opts = Db::name('tm_option')->where('question_id', $oldQid)->order('sort_no asc,id asc')->select();
                foreach ($opts as $opt) {
                    Db::name('tm_option')->insert([
                        'question_id' => $nqid,
                        'content'     => $opt['content'],
                        'score_ratio' => $opt['score_ratio'],
                        'sort_no'     => (int)$opt['sort_no'],
                    ]);
                }
            }

            // 复制挂在三级指标上的赋能措施，indicator_id 使用 idMap 新 ID
            foreach ($idMap as $oldInd => $newInd) {
                if ($oldInd <= 0 || $newInd <= 0) {
                    continue;
                }
                $measures = Db::name('tm_empower_measure')->where('indicator_id', $oldInd)->select();
                foreach ($measures as $m) {
                    Db::name('tm_empower_measure')->insert([
                        'level_code'   => $m['level_code'],
                        'indicator_id' => $newInd,
                        'title'        => $m['title'],
                        'content'      => $m['content'],
                        'sort_no'      => (int)$m['sort_no'],
                        'status'       => (int)$m['status'],
                        'created_at'   => $now,
                    ]);
                }
            }

            Db::commit();
            return [true, '复制成功', $newId];
        } catch (\Exception $e) {
            Db::rollback();
            return [false, '复制失败：' . $e->getMessage(), 0];
        }
    }

    /**
     * 更新已有指标节点信息（仅草稿；不可新增节点、不可改 parent_id / level_no）。
     *
     * @param int   $versionId 版本 ID
     * @param array $data 指标字段：id（必填）、name、description?、code?、weight、sort_no、status?
     * @return array [bool ok, string msg, int id]
     */
    public static function saveIndicator($versionId, array $data)
    {
        list($ok, $msg) = self::assertDraft($versionId);
        if (!$ok) {
            return [$ok, $msg, 0];
        }

        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            return [false, '不可新增指标节点；请选择已有一/二/三级指标进行编辑', 0];
        }

        $row = Db::name('tm_indicator')
            ->where('id', $id)
            ->where('version_id', (int)$versionId)
            ->find();
        if (!$row) {
            return [false, '指标不存在或不属于当前版本', 0];
        }

        $name = isset($data['name']) ? trim((string)$data['name']) : '';
        if ($name === '') {
            return [false, '请填写指标名称', 0];
        }
        if (mb_strlen($name) > 128) {
            return [false, '指标名称过长', 0];
        }

        $description = isset($data['description']) ? trim((string)$data['description']) : '';
        if (mb_strlen($description) > 1024) {
            return [false, '指标描述过长', 0];
        }

        $code = isset($data['code']) ? trim((string)$data['code']) : '';
        if ($code !== '' && mb_strlen($code) > 32) {
            return [false, '指标编码过长', 0];
        }

        $weight = isset($data['weight']) ? (float)$data['weight'] : (float)$row['weight'];
        if ($weight < 0 || $weight > 100) {
            return [false, '权重须在 0～100 之间', 0];
        }

        $sortNo = isset($data['sort_no']) ? (int)$data['sort_no'] : (int)$row['sort_no'];
        $status = isset($data['status']) ? (int)$data['status'] : (int)$row['status'];
        if ($status !== 0 && $status !== 1) {
            $status = 1;
        }

        Db::name('tm_indicator')->where('id', $id)->where('version_id', (int)$versionId)->update([
            'name'        => $name,
            'description' => $description === '' ? null : $description,
            'code'        => $code === '' ? null : $code,
            'weight'      => $weight,
            'sort_no'     => $sortNo,
            'status'      => $status,
            'updated_at'  => tmNow(),
        ]);

        return [true, '指标已保存', $id];
    }

    /**
     * 删除指标节点（结构固定，接口保留但拒绝）。
     *
     * @param int $versionId   版本 ID
     * @param int $indicatorId 指标 ID
     * @return array [bool ok, string msg]
     */
    public static function deleteIndicator($versionId, $indicatorId)
    {
        return [false, '指标树结构固定，不可删除指标；请编辑指标信息或在三级指标下维护题目'];
    }

    /**
     * 保存题目及其选项（仅草稿版本可写）。
     * options 元素形如：{id?, content, score_ratio, sort_no?}
     *
     * @param int   $versionId 版本 ID
     * @param array $data 题目字段：id?、indicator_id、title、remark?、sort_no?、status?、options?
     * @return array [bool ok, string msg, int id] 成功时 id 为题目 ID，失败时为 0
     */
    public static function saveQuestion($versionId, array $data)
    {
        list($ok, $msg) = self::assertDraft($versionId);
        if (!$ok) {
            return [$ok, $msg, 0];
        }
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $indicatorId = isset($data['indicator_id']) ? (int)$data['indicator_id'] : 0;
        $title = isset($data['title']) ? trim($data['title']) : '';
        $remark = isset($data['remark']) ? trim($data['remark']) : '';
        $sortNo = isset($data['sort_no']) ? (int)$data['sort_no'] : 0;
        $status = isset($data['status']) ? (int)$data['status'] : 1;
        $options = isset($data['options']) ? $data['options'] : [];
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }
        if ($title === '') {
            return [false, '请填写题干', 0];
        }
        $ind = Db::name('tm_indicator')
            ->where('id', $indicatorId)
            ->where('version_id', (int)$versionId)
            ->find();
        if (!$ind || (int)$ind['level_no'] !== 3) {
            return [false, '题目必须挂在三级指标下', 0];
        }

        // 过滤空选项并校验得分比例
        $cleanOpts = [];
        foreach ($options as $i => $opt) {
            $content = isset($opt['content']) ? trim($opt['content']) : '';
            if ($content === '') {
                continue;
            }
            $ratio = isset($opt['score_ratio']) ? (float)$opt['score_ratio'] : 0;
            if ($ratio < 0 || $ratio > 1) {
                return [false, '选项得分比例须在 0～1 之间', 0];
            }
            $cleanOpts[] = [
                'id'          => isset($opt['id']) ? (int)$opt['id'] : 0,
                'content'     => $content,
                'score_ratio' => $ratio,
                'sort_no'     => isset($opt['sort_no']) ? (int)$opt['sort_no'] : ($i + 1),
            ];
        }
        if (count($cleanOpts) < 2) {
            return [false, '至少需要 2 个选项', 0];
        }

        $now = tmNow();
        Db::startTrans();
        try {
            if ($id > 0) {
                $exist = Db::name('tm_question')
                    ->where('id', $id)
                    ->where('version_id', (int)$versionId)
                    ->find();
                if (!$exist) {
                    throw new \RuntimeException('题目不存在');
                }
                Db::name('tm_question')->where('id', $id)->update([
                    'indicator_id' => $indicatorId,
                    'title'        => $title,
                    'remark'       => $remark !== '' ? $remark : null,
                    'sort_no'      => $sortNo,
                    'status'       => $status ? 1 : 0,
                    'updated_at'   => $now,
                ]);
                $qid = $id;

                // 选项增量同步：有 id 则更新，无 id 则新增，最后删除未保留的旧选项
                $keepIds = [];
                foreach ($cleanOpts as $opt) {
                    if ($opt['id'] > 0) {
                        Db::name('tm_option')->where('id', $opt['id'])->where('question_id', $qid)->update([
                            'content'     => $opt['content'],
                            'score_ratio' => $opt['score_ratio'],
                            'sort_no'     => $opt['sort_no'],
                        ]);
                        $keepIds[] = $opt['id'];
                    } else {
                        $oid = (int)Db::name('tm_option')->insertGetId([
                            'question_id' => $qid,
                            'content'     => $opt['content'],
                            'score_ratio' => $opt['score_ratio'],
                            'sort_no'     => $opt['sort_no'],
                        ]);
                        $keepIds[] = $oid;
                    }
                }
                if (!empty($keepIds)) {
                    Db::name('tm_option')->where('question_id', $qid)->where('id', 'not in', $keepIds)->delete();
                }
            } else {
                $qid = (int)Db::name('tm_question')->insertGetId([
                    'version_id'   => (int)$versionId,
                    'indicator_id' => $indicatorId,
                    'title'        => $title,
                    'remark'       => $remark !== '' ? $remark : null,
                    'sort_no'      => $sortNo,
                    'status'       => $status ? 1 : 0,
                    'created_at'   => $now,
                ]);
                foreach ($cleanOpts as $opt) {
                    Db::name('tm_option')->insert([
                        'question_id' => $qid,
                        'content'     => $opt['content'],
                        'score_ratio' => $opt['score_ratio'],
                        'sort_no'     => $opt['sort_no'],
                    ]);
                }
            }
            Db::commit();
            return [true, '保存成功', $qid];
        } catch (\Exception $e) {
            Db::rollback();
            return [false, $e->getMessage(), 0];
        }
    }

    /**
     * 单独更新选项权重（score_ratio，仅草稿版本可写）。
     *
     * @param int   $versionId  版本 ID
     * @param int   $optionId   选项 ID
     * @param float $scoreRatio 权重，范围 0～1
     * @return array [bool ok, string msg]
     */
    public static function updateOptionWeight($versionId, $optionId, $scoreRatio)
    {
        list($ok, $msg) = self::assertDraft($versionId);
        if (!$ok) {
            return [$ok, $msg];
        }
        $optionId = (int)$optionId;
        $ratio = (float)$scoreRatio;
        if ($optionId <= 0) {
            return [false, '选项无效'];
        }
        if ($ratio < 0 || $ratio > 1) {
            return [false, '选项权重须在 0～1 之间'];
        }
        $opt = Db::name('tm_option')->alias('o')
            ->join('tm_question q', 'q.id = o.question_id')
            ->where('o.id', $optionId)
            ->where('q.version_id', (int)$versionId)
            ->field('o.id')
            ->find();
        if (!$opt) {
            return [false, '选项不存在或不属于该版本'];
        }
        Db::name('tm_option')->where('id', $optionId)->update([
            'score_ratio' => $ratio,
        ]);
        return [true, '权重已更新'];
    }

    /**
     * 删除题目及其全部选项（仅草稿版本可写）。
     *
     * @param int $versionId  版本 ID
     * @param int $questionId 题目 ID
     * @return array [bool ok, string msg]
     */
    public static function deleteQuestion($versionId, $questionId)
    {
        list($ok, $msg) = self::assertDraft($versionId);
        if (!$ok) {
            return [$ok, $msg];
        }
        $q = Db::name('tm_question')
            ->where('id', (int)$questionId)
            ->where('version_id', (int)$versionId)
            ->find();
        if (!$q) {
            return [false, '题目不存在'];
        }
        Db::startTrans();
        try {
            Db::name('tm_option')->where('question_id', (int)$questionId)->delete();
            Db::name('tm_question')->where('id', (int)$questionId)->delete();
            Db::commit();
            return [true, '已删除'];
        } catch (\Exception $e) {
            Db::rollback();
            return [false, '删除失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取题目详情，附带按 sort_no 排序的选项列表。
     *
     * @param int $questionId 题目 ID
     * @return array|null 题目行 + options 数组；不存在时返回 null
     */
    public static function questionDetail($questionId)
    {
        $q = Db::name('tm_question')->where('id', (int)$questionId)->find();
        if (!$q) {
            return null;
        }
        $q['options'] = Db::name('tm_option')
            ->where('question_id', (int)$questionId)
            ->order('sort_no asc,id asc')
            ->select();
        return $q;
    }
}

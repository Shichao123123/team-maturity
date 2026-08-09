<?php
namespace app\index\service;

use think\Db;

/**
 * 指标版本 / 指标树 / 题库维护。
 * 约定：仅草稿(status=0)可改指标与题目；已发布只读，需 copy 后再改。
 */
class IndicatorService
{
    public static function findVersion($id)
    {
        return Db::name('tm_indicator_version')->where('id', (int)$id)->find();
    }

    public static function isDraft($versionId)
    {
        $v = self::findVersion($versionId);
        return $v && (int)$v['status'] === 0;
    }

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
     * @return array [ok, msg, id]
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
     * @return array [ok, msg]
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
     * 发布前完整校验。
     * @return array 错误列表，空=通过
     */
    public static function validateForPublish($versionId)
    {
        $errors = [];
        $versionId = (int)$versionId;
        $l1Sum = (float)Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 1)
            ->where('status', 1)
            ->sum('weight');
        if (abs($l1Sum - 100) > 0.05) {
            $errors[] = '一级指标权重之和应为 100（当前 ' . round($l1Sum, 2) . '）';
        }
        $l3Cnt = (int)Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 3)
            ->where('status', 1)
            ->count();
        if ($l3Cnt < 1) {
            $errors[] = '至少需要一个启用的三级指标';
        }
        $errors = array_merge($errors, QuestionPickService::validateVersionQuestions($versionId));

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
     * @return array [ok, msg]
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
     * @return array [ok, msg]
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
     * 深拷贝版本（指标/题目/选项），赋能措施按新三级指标复制。
     * @return array [ok, msg, newId]
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
                    'version_id' => $newId,
                    'parent_id'  => $newParent,
                    'level_no'   => (int)$ind['level_no'],
                    'code'       => $ind['code'],
                    'name'       => $ind['name'],
                    'weight'     => $ind['weight'],
                    'sort_no'    => (int)$ind['sort_no'],
                    'status'     => (int)$ind['status'],
                    'created_at' => $now,
                ]);
                $idMap[$oldId] = $nid;
            }

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

            // 复制挂在三级指标上的赋能措施
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
     * @return array [ok, msg, id]
     */
    public static function saveIndicator($versionId, array $data)
    {
        list($ok, $msg) = self::assertDraft($versionId);
        if (!$ok) {
            return [$ok, $msg, 0];
        }
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $parentId = isset($data['parent_id']) ? (int)$data['parent_id'] : 0;
        $name = isset($data['name']) ? trim($data['name']) : '';
        $code = isset($data['code']) ? trim($data['code']) : '';
        $weight = isset($data['weight']) ? (float)$data['weight'] : 0;
        $sortNo = isset($data['sort_no']) ? (int)$data['sort_no'] : 0;
        $status = isset($data['status']) ? (int)$data['status'] : 1;
        if ($name === '') {
            return [false, '请填写指标名称', 0];
        }

        $levelNo = 1;
        if ($parentId > 0) {
            $parent = Db::name('tm_indicator')
                ->where('id', $parentId)
                ->where('version_id', (int)$versionId)
                ->find();
            if (!$parent) {
                return [false, '上级指标不存在', 0];
            }
            $levelNo = (int)$parent['level_no'] + 1;
            if ($levelNo > 3) {
                return [false, '最多三级指标', 0];
            }
        }

        $now = tmNow();
        $row = [
            'parent_id'  => $parentId,
            'level_no'   => $levelNo,
            'code'       => $code !== '' ? $code : null,
            'name'       => $name,
            'weight'     => $weight,
            'sort_no'    => $sortNo,
            'status'     => $status ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($id > 0) {
            $exist = Db::name('tm_indicator')
                ->where('id', $id)
                ->where('version_id', (int)$versionId)
                ->find();
            if (!$exist) {
                return [false, '指标不存在', 0];
            }
            // 不允许改层级结构到非法；保持 level_no 按 parent 重算
            Db::name('tm_indicator')->where('id', $id)->update($row);
            return [true, '保存成功', $id];
        }
        $row['version_id'] = (int)$versionId;
        $row['created_at'] = $now;
        unset($row['updated_at']);
        $newId = (int)Db::name('tm_indicator')->insertGetId($row);
        return [true, '添加成功', $newId];
    }

    /**
     * @return array [ok, msg]
     */
    public static function deleteIndicator($versionId, $indicatorId)
    {
        list($ok, $msg) = self::assertDraft($versionId);
        if (!$ok) {
            return [$ok, $msg];
        }
        $ind = Db::name('tm_indicator')
            ->where('id', (int)$indicatorId)
            ->where('version_id', (int)$versionId)
            ->find();
        if (!$ind) {
            return [false, '指标不存在'];
        }
        $childCnt = (int)Db::name('tm_indicator')->where('parent_id', (int)$indicatorId)->count();
        if ($childCnt > 0) {
            return [false, '请先删除下级指标'];
        }
        $qCnt = (int)Db::name('tm_question')->where('indicator_id', (int)$indicatorId)->count();
        if ($qCnt > 0) {
            return [false, '请先删除该指标下的题目'];
        }
        Db::name('tm_empower_measure')->where('indicator_id', (int)$indicatorId)->delete();
        Db::name('tm_indicator')->where('id', (int)$indicatorId)->delete();
        return [true, '已删除'];
    }

    /**
     * 保存题目及选项。options: [{id?, content, score_ratio, sort_no}]
     * @return array [ok, msg, id]
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
     * @return array [ok, msg]
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

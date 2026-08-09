<?php
namespace app\index\service;

use think\Db;

/**
 * 三级指标 → 题目抽取（预留「一指标多题、开测抽一」）
 *
 * 约定：
 * - 题库侧：同一 indicator_id（三级）可挂多道 tm_question
 * - 开测侧：每个三级指标只落入快照 1 道题，答题与算分只认快照
 * - 当前题库多为 1:1；多题时按 pick_mode 抽取
 */
class QuestionPickService
{
    /** 仅取排序第一题（兼容当前 1:1） */
    const MODE_FIRST = 'first';
    /** 随机抽一题（后期扩题默认推荐） */
    const MODE_RANDOM = 'random';
    /** 按 sort_no 轮询（需外部传入游标，预留） */
    const MODE_SEQUENTIAL = 'sequential';

    /**
     * 读取版本上的抽题策略；无配置时：仅 1 题用 first，多题用 random
     */
    public static function resolveMode($versionId, $override = null)
    {
        if ($override) {
            return $override;
        }
        $ver = Db::name('tm_indicator_version')->where('id', $versionId)->find();
        if ($ver && !empty($ver['question_pick_mode'])) {
            return $ver['question_pick_mode'];
        }
        return self::MODE_RANDOM;
    }

    /**
     * 列出某三级指标下可用题目（启用）
     */
    public static function listCandidates($indicatorId)
    {
        return Db::name('tm_question')
            ->where('indicator_id', $indicatorId)
            ->where('status', 1)
            ->order('sort_no asc,id asc')
            ->select();
    }

    /**
     * 从候选中抽 1 道题
     * @param array $candidates
     * @param string $mode
     * @param int|null $seqCursor sequential 模式游标
     * @return array|null 题目行
     */
    public static function pickOne(array $candidates, $mode = self::MODE_RANDOM, $seqCursor = null)
    {
        $n = count($candidates);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return $candidates[0];
        }

        switch ($mode) {
            case self::MODE_FIRST:
                return $candidates[0];
            case self::MODE_SEQUENTIAL:
                $idx = $seqCursor === null ? 0 : ((int)$seqCursor % $n);
                return $candidates[$idx];
            case self::MODE_RANDOM:
            default:
                return $candidates[mt_rand(0, $n - 1)];
        }
    }

    /**
     * 为整个版本生成「每个三级指标 → 1 道题」的开测题集（含选项）
     * 供批次开始写快照时调用。
     *
     * @return array [
     *   'pick_mode' => string,
     *   'items' => [
     *      [
     *        'indicator' => [...],
     *        'question'  => [...],
     *        'options'   => [...],
     *        'candidate_cnt' => int,  // 题池大小，便于审计
     *      ],
     *   ]
     * ]
     */
    public static function buildExamSet($versionId, $mode = null)
    {
        $mode = self::resolveMode($versionId, $mode);

        $l3List = Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 3)
            ->where('status', 1)
            ->order('sort_no asc,id asc')
            ->select();

        $items = [];
        foreach ($l3List as $ind) {
            $candidates = self::listCandidates($ind['id']);
            $picked = self::pickOne($candidates, $mode);
            if (!$picked) {
                // 无题的三级指标跳过，发布校验应已拦住；此处不中断
                continue;
            }
            $options = Db::name('tm_option')
                ->where('question_id', $picked['id'])
                ->order('sort_no asc,id asc')
                ->select();

            $items[] = [
                'indicator'     => [
                    'id'     => (int)$ind['id'],
                    'name'   => $ind['name'],
                    'weight' => (float)$ind['weight'],
                    'parent_id' => (int)$ind['parent_id'],
                ],
                'question'      => [
                    'id'    => (int)$picked['id'],
                    'title' => $picked['title'],
                    'sort_no' => (int)$picked['sort_no'],
                ],
                'options'       => $options,
                'candidate_cnt' => count($candidates),
            ];
        }

        return [
            'version_id' => (int)$versionId,
            'pick_mode'  => $mode,
            'picked_at'  => date('Y-m-d H:i:s'),
            'items'      => $items,
        ];
    }

    /**
     * 发布前校验：每个三级至少 1 题（允许多题）
     * @return array 错误消息列表，空=通过
     */
    public static function validateVersionQuestions($versionId)
    {
        $errors = [];
        $l3List = Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 3)
            ->where('status', 1)
            ->select();
        foreach ($l3List as $ind) {
            $cnt = Db::name('tm_question')
                ->where('indicator_id', $ind['id'])
                ->where('status', 1)
                ->count();
            if ($cnt < 1) {
                $errors[] = '三级指标「' . $ind['name'] . '」下至少需要 1 道启用题目';
            }
        }
        return $errors;
    }
}

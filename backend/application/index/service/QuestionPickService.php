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
     * 读取指标版本上的抽题策略；无配置时默认 random。
     *
     * @param int         $versionId 指标版本 ID
     * @param string|null $override  强制指定模式（first/random/sequential），非空时优先
     * @return string 抽题模式常量值
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
     * 列出某三级指标下所有启用状态的候选题目。
     *
     * @param int $indicatorId 三级指标 ID
     * @return array tm_question 行列表，按 sort_no、id 升序
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
     * 从候选题目中按策略抽取一道题。
     *
     * @param array    $candidates 候选题目行列表
     * @param string   $mode       抽题模式（MODE_FIRST / MODE_RANDOM / MODE_SEQUENTIAL）
     * @param int|null $seqCursor  sequential 模式的轮询游标
     * @return array|null 选中的题目行；无候选时返回 null
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
     * 为整个指标版本生成开测题集：每个三级指标对应 1 道题及选项。
     * 供批次开始写快照时调用。
     *
     * @param int         $versionId 指标版本 ID
     * @param string|null $mode      抽题模式；null 时由 resolveMode 决定
     * @return array 含 version_id、pick_mode、picked_at、items（indicator/question/options/candidate_cnt）
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
     * 发布前校验：每个启用的三级指标下至少有一道启用题目。
     *
     * @param int $versionId 指标版本 ID
     * @return array 错误消息字符串列表；空数组表示校验通过
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

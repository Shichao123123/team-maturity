<?php
namespace app\index\service;

use think\Db;

/**
 * 按 tm_level_threshold 判级（供批次算分复用）
 */
class LevelJudgeService
{
    /**
     * 根据 tm_level_threshold 配置，按分数区间判定成熟度等级。
     *
     * @param float|int|string $score 待判定的得分
     * @return array|null 匹配到的等级行（含 level_code、level_name、min_score、max_score、feature_desc 等）；无匹配返回 null
     */
    public static function judge($score)
    {
        $score = round((float)$score, 2);
        $list = Db::name('tm_level_threshold')
            ->where('status', 1)
            ->order('sort_no asc,id asc')
            ->select();

        foreach ($list as $row) {
            $min = (float)$row['min_score'];
            $max = $row['max_score'];
            if ($score < $min) {
                continue;
            }
            if ($max === null || $max === '') {
                return $row;
            }
            if ($score <= (float)$max) {
                return $row;
            }
        }
        return null;
    }
}

<?php
namespace app\index\service;

use think\Db;

/**
 * 按 tm_level_threshold 判级（供批次算分复用）
 */
class LevelJudgeService
{
    /** @var array|null 本请求内阈值行缓存 */
    private static $thresholdRows = null;

    /** @var array|null level_code => level_name */
    private static $nameMap = null;

    /**
     * 清空请求内缓存（阈值配置变更后如需立即生效可调用）。
     */
    public static function clearCache()
    {
        self::$thresholdRows = null;
        self::$nameMap = null;
    }

    /**
     * 启用中的阈值列表（按 sort_no、id 升序），同请求只查一次库。
     *
     * @return array
     */
    public static function thresholds()
    {
        if (self::$thresholdRows === null) {
            self::$thresholdRows = Db::name('tm_level_threshold')
                ->where('status', 1)
                ->order('sort_no asc,id asc')
                ->select();
            if (!is_array(self::$thresholdRows)) {
                self::$thresholdRows = [];
            }
        }
        return self::$thresholdRows;
    }

    /**
     * 等级码 → 名称映射，同请求只查一次。
     *
     * @return array
     */
    public static function nameMap()
    {
        if (self::$nameMap === null) {
            self::$nameMap = Db::name('tm_level_threshold')->column('level_name', 'level_code');
            if (!is_array(self::$nameMap)) {
                self::$nameMap = [];
            }
        }
        return self::$nameMap;
    }

    /**
     * 根据 tm_level_threshold 配置，按分数区间判定成熟度等级。
     *
     * @param float|int|string $score 待判定的得分
     * @return array|null 匹配到的等级行（含 level_code、level_name、min_score、max_score、feature_desc 等）；无匹配返回 null
     */
    public static function judge($score)
    {
        $score = round((float)$score, 2);
        $list = self::thresholds();

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

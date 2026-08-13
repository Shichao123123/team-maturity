<?php
namespace app\index\service;

use think\Db;

/**
 * 赋能措施批量加载与解析（避免薄弱项循环内 N+1 查询）。
 * 解析规则与原先 ScoreService / BatchService 单条查询保持一致。
 */
class MeasureLookup
{
    /**
     * 按指标 ID 列表一次取出措施，按 indicator_id 分组（组内 id 升序）。
     *
     * @param array $indicatorIds
     * @return array [indicator_id => measureRow[]]
     */
    public static function loadByIndicatorIds(array $indicatorIds)
    {
        $ids = [];
        foreach ($indicatorIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if (empty($ids)) {
            return [];
        }

        $rows = Db::name('tm_empower_measure')
            ->where('indicator_id', 'in', $ids)
            ->order('id asc')
            ->select();

        $map = [];
        foreach ($rows as $row) {
            $iid = (int)$row['indicator_id'];
            if (!isset($map[$iid])) {
                $map[$iid] = [];
            }
            $map[$iid][] = $row;
        }
        return $map;
    }

    /**
     * 班组薄弱项：优先匹配等级码（空则 essential），否则取该指标 id 最小的一条。
     *
     * @param array  $map         loadByIndicatorIds 结果
     * @param int    $indicatorId
     * @param string $levelCode
     * @return array|null
     */
    public static function pickForTeam(array $map, $indicatorId, $levelCode)
    {
        $levelCode = $levelCode !== '' && $levelCode !== null ? (string)$levelCode : 'essential';
        $list = isset($map[(int)$indicatorId]) ? $map[(int)$indicatorId] : [];
        foreach ($list as $m) {
            if ((string)$m['level_code'] === $levelCode) {
                return $m;
            }
        }
        return !empty($list) ? $list[0] : null;
    }

    /**
     * 个人薄弱项：先 essential（无则任意第一条），再按等级码覆盖（内容非「暂无」时）。
     * 会写入 measure / measure_level，语义同原 ScoreService 循环。
     *
     * @param array  $weakList  引用薄弱列表
     * @param string $levelCode 个人等级码
     * @return void
     */
    public static function enrichPersonalWeak(array &$weakList, $levelCode)
    {
        if (empty($weakList)) {
            return;
        }
        $ids = [];
        foreach ($weakList as $w) {
            $ids[] = (int)$w['indicator_id'];
        }
        $map = self::loadByIndicatorIds($ids);
        $levelCode = (string)$levelCode;

        foreach ($weakList as &$w) {
            $list = isset($map[(int)$w['indicator_id']]) ? $map[(int)$w['indicator_id']] : [];
            $essential = null;
            foreach ($list as $m) {
                if ((string)$m['level_code'] === 'essential') {
                    $essential = $m;
                    break;
                }
            }
            if (!$essential && !empty($list)) {
                $essential = $list[0];
            }
            $w['measure'] = $essential ? $essential['content'] : '暂无';

            if ($levelCode !== '' && $levelCode !== 'essential') {
                $byLevel = null;
                foreach ($list as $m) {
                    if ((string)$m['level_code'] === $levelCode) {
                        $byLevel = $m;
                        break;
                    }
                }
                if ($byLevel && $byLevel['content'] !== '暂无') {
                    $w['measure'] = $byLevel['content'];
                    $w['measure_level'] = $levelCode;
                } else {
                    $w['measure_level'] = $essential ? $essential['level_code'] : '';
                }
            } else {
                $w['measure_level'] = $essential ? $essential['level_code'] : '';
            }
        }
        unset($w);
    }
}

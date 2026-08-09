<?php
namespace app\index\service;

use think\Db;

/**
 * 个人得分：Σ(指标权重 × 选项得分比例)
 */
class ScoreService
{
    /**
     * 计算个人测评得分：Σ(指标权重 × 选项得分比例)，并判级、提取薄弱指标。
     *
     * @param array $examItems QuestionPickService::buildExamSet 返回的 items
     * @param array $answers   作答映射 [question_id => option_id]
     * @return array 含 total_score、full_score、answered_cnt、question_cnt、level、detail、weak_list
     */
    public static function scorePersonal(array $examItems, array $answers)
    {
        $total = 0.0;
        $detail = [];
        $answered = 0;

        foreach ($examItems as $item) {
            $qid = (int)$item['question']['id'];
            $weight = (float)$item['indicator']['weight'];
            $optId = isset($answers[$qid]) ? (int)$answers[$qid] : 0;
            $ratio = null;
            $optContent = '';
            $chosen = null;

            if ($optId > 0) {
                foreach ($item['options'] as $opt) {
                    if ((int)$opt['id'] === $optId) {
                        $chosen = $opt;
                        break;
                    }
                }
            }
            if ($chosen) {
                $ratio = (float)$chosen['score_ratio'];
                $optContent = $chosen['content'];
                $answered++;
            } else {
                $ratio = 0.0;
            }

            $itemScore = round($weight * $ratio, 4);
            $total += $itemScore;

            $detail[] = [
                'indicator_id'   => (int)$item['indicator']['id'],
                'indicator_name' => $item['indicator']['name'],
                'weight'         => $weight,
                'question_id'    => $qid,
                'question_title' => $item['question']['title'],
                'option_id'      => $optId ?: null,
                'option_content' => $optContent,
                'score_ratio'    => $ratio,
                'item_score'     => $itemScore,
                'full_score'     => $weight,
                'is_weak'        => $ratio < 1.0,
            ];
        }

        $total = round($total, 2);
        $level = LevelJudgeService::judge($total);

        // 薄弱指标：未拿满分，按失分从大到小
        $weak = array_values(array_filter($detail, function ($d) {
            return !empty($d['is_weak']);
        }));
        usort($weak, function ($a, $b) {
            $da = $a['full_score'] - $a['item_score'];
            $db = $b['full_score'] - $b['item_score'];
            if ($da == $db) {
                return 0;
            }
            return ($da > $db) ? -1 : 1;
        });

        foreach ($weak as &$w) {
            $measure = Db::name('tm_empower_measure')
                ->where('indicator_id', $w['indicator_id'])
                ->where('level_code', 'essential')
                ->order('id asc')
                ->find();
            if (!$measure) {
                $measure = Db::name('tm_empower_measure')
                    ->where('indicator_id', $w['indicator_id'])
                    ->order('id asc')
                    ->find();
            }
            $w['measure'] = $measure ? $measure['content'] : '暂无';
            $levelCode = $level ? $level['level_code'] : '';
            if ($levelCode && $levelCode !== 'essential') {
                $byLevel = Db::name('tm_empower_measure')
                    ->where('indicator_id', $w['indicator_id'])
                    ->where('level_code', $levelCode)
                    ->find();
                if ($byLevel && $byLevel['content'] !== '暂无') {
                    $w['measure'] = $byLevel['content'];
                    $w['measure_level'] = $levelCode;
                } else {
                    $w['measure_level'] = $measure ? $measure['level_code'] : '';
                }
            } else {
                $w['measure_level'] = $measure ? $measure['level_code'] : '';
            }
        }
        unset($w);

        return [
            'total_score'   => $total,
            'full_score'    => round(array_sum(array_column($detail, 'full_score')), 2),
            'answered_cnt'  => $answered,
            'question_cnt'  => count($detail),
            'level'         => $level ? [
                'level_code'   => $level['level_code'],
                'level_name'   => $level['level_name'],
                'feature_desc' => $level['feature_desc'],
                'min_score'    => $level['min_score'],
                'max_score'    => $level['max_score'],
            ] : null,
            'detail'        => $detail,
            'weak_list'     => $weak,
        ];
    }
}

<?php
namespace app\index\controller;

use app\index\model\PowerModel;
use app\index\service\OpLogService;
use think\Db;
use think\Session;

class Index extends Base
{
    /** 工作台等级建议（对齐设计稿） */
    private $levelAdvice = [
        'essential'   => '巩固提升',
        'independent' => '重点培育',
        'dependent'   => '加强帮扶',
        'passive'     => '专项整改',
    ];

    public function index()
    {
        return $this->fetch('index/index');
    }

    public function welcome()
    {
        $admin = $this->currentAdmin;
        $this->assign('admin', $admin);
        return $this->fetch('index/welcome');
    }

    /**
     * 工作台统计：顶部 KPI + 等级分布 + 明细清单
     * 有测评结果时按最近有结果的批次统计；尚无结果时班组总数仍取档案数，其余为空/0。
     */
    public function workbenchStats()
    {
        $teamTotal = (int)Db::name('tm_team')->whereNull('deleted_at')->where('status', 1)->count();

        $levelsMeta = Db::name('tm_level_threshold')
            ->where('status', 1)
            ->order('sort_no desc,id asc')
            ->select();
        if (!$levelsMeta) {
            $levelsMeta = [
                ['level_code' => 'essential', 'level_name' => '本质安全型', 'sort_no' => 4],
                ['level_code' => 'independent', 'level_name' => '自立型', 'sort_no' => 3],
                ['level_code' => 'dependent', 'level_name' => '依赖型', 'sort_no' => 2],
                ['level_code' => 'passive', 'level_name' => '被动型', 'sort_no' => 1],
            ];
        }

        $batchId = (int)Db::name('tm_team_result')->order('scored_at desc,id desc')->value('batch_id');
        $batchName = '';
        $scoredAt = '';
        $avgScore = null;
        $countMap = [];

        if ($batchId > 0) {
            $batch = Db::name('tm_batch')->where('id', $batchId)->find();
            $batchName = $batch ? $batch['name'] : ('批次#' . $batchId);
            $rows = Db::name('tm_team_result')->where('batch_id', $batchId)->select();
            $sum = 0;
            $n = 0;
            foreach ($rows as $row) {
                $code = $row['level_code'];
                if (!isset($countMap[$code])) {
                    $countMap[$code] = 0;
                }
                $countMap[$code]++;
                $sum += (float)$row['team_score'];
                $n++;
                if ($scoredAt === '' || $row['scored_at'] > $scoredAt) {
                    $scoredAt = $row['scored_at'];
                }
            }
            if ($n > 0) {
                $avgScore = round($sum / $n, 1);
            }
        }

        $resultTeamCnt = 0;
        foreach ($countMap as $c) {
            $resultTeamCnt += (int)$c;
        }

        $levels = [];
        foreach ($levelsMeta as $meta) {
            $code = $meta['level_code'];
            $cnt = isset($countMap[$code]) ? (int)$countMap[$code] : 0;
            $percent = $resultTeamCnt > 0 ? round($cnt * 100 / $resultTeamCnt, 1) : 0;
            $levels[] = [
                'level_code'   => $code,
                'level_name'   => $meta['level_name'],
                'count'        => $cnt,
                'percent'      => $percent,
                'percent_text' => number_format($percent, 1) . '%',
                'advice'       => isset($this->levelAdvice[$code]) ? $this->levelAdvice[$code] : '',
            ];
        }

        $essentialCnt = isset($countMap['essential']) ? (int)$countMap['essential'] : 0;
        $passiveCnt = isset($countMap['passive']) ? (int)$countMap['passive'] : 0;

        return json(DefaultResponse(0, true, 'ok', [
            'team_total'       => $teamTotal,
            'avg_score'        => $avgScore,
            'avg_score_text'   => $avgScore === null ? null : (string)$avgScore,
            'essential_cnt'    => $essentialCnt,
            'passive_cnt'      => $passiveCnt,
            'result_team_cnt'  => $resultTeamCnt,
            'batch_id'         => $batchId ?: null,
            'batch_name'       => $batchName,
            'scored_at'        => $scoredAt,
            'levels'           => $levels,
        ]));
    }

    /**
     * Pear 侧栏菜单（按角色权限）
     */
    public function menu()
    {
        $admin = $this->currentAdmin;
        $model = new PowerModel();
        $list = $model->getMenuByRoleId($admin['role_id']);
        return json($list);
    }

    public function backsys()
    {
        $user = Session::get('tm_user');
        if ($user && isset($user['id'])) {
            OpLogService::write('login', 'logout', $user['id'], [
                'username' => isset($user['username']) ? $user['username'] : '',
            ], $user['id']);
        }
        Session::clear();
        $this->success('您已退出', '/Login');
    }
}

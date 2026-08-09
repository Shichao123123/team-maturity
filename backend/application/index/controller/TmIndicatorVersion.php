<?php
namespace app\index\controller;

use think\Db;
use think\Request;

class TmIndicatorVersion extends Base
{
    public function index()
    {
        return $this->fetch('tm_indicator_version/index');
    }

    public function getList()
    {
        $page = (int)Request::instance()->param('page', 1);
        $limit = (int)Request::instance()->param('limit', 15);
        $q = Db::name('tm_indicator_version')->order('id desc');
        $count = $q->count();
        $list = Db::name('tm_indicator_version')->order('id desc')->page($page, $limit)->select();
        $modeMap = ['first' => '取首题', 'random' => '随机抽一', 'sequential' => '轮询抽一'];
        foreach ($list as &$row) {
            $row['question_cnt'] = Db::name('tm_question')->where('version_id', $row['id'])->count();
            $row['status_text'] = [0 => '草稿', 1 => '已发布', 2 => '停用'][(int)$row['status']] ?? '';
            $l1Sum = Db::name('tm_indicator')
                ->where('version_id', $row['id'])
                ->where('level_no', 1)
                ->sum('weight');
            $row['l1_weight_sum'] = round((float)$l1Sum, 2);
            $mode = isset($row['question_pick_mode']) ? $row['question_pick_mode'] : 'random';
            $row['pick_mode_text'] = isset($modeMap[$mode]) ? $modeMap[$mode] : $mode;
        }
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }
}

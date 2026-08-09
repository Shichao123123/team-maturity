<?php
namespace app\index\controller;

class TmDashboard extends Base
{
    public function index()
    {
        $this->assign('title', '成熟度看板（待实现）');
        return $this->fetch('index/placeholder');
    }

    public function trend()
    {
        $this->assign('title', '成长趋势（待实现）');
        return $this->fetch('index/placeholder');
    }

    public function rank()
    {
        $this->assign('title', '班组排行榜（待实现）');
        return $this->fetch('index/placeholder');
    }
}

<?php
namespace app\index\controller;

class TmResult extends Base
{
    public function index()
    {
        $this->assign('title', '结果查询（待实现）');
        return $this->fetch('index/placeholder');
    }
}

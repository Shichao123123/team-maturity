<?php
namespace app\index\controller;

class TmBatch extends Base
{
    public function index()
    {
        $this->assign('title', '测评批次（待实现）');
        return $this->fetch('index/placeholder');
    }
}

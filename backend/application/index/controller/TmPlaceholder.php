<?php
namespace app\index\controller;

/**
 * 未完成模块占位页（指标/批次/看板等后续迭代）
 */
class TmPlaceholder extends Base
{
    public function index()
    {
        $title = $this->request->param('title', '功能开发中');
        $this->assign('title', $title);
        return $this->fetch('index/placeholder');
    }
}

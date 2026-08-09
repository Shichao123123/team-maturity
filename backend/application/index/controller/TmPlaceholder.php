<?php
namespace app\index\controller;

/**
 * 未完成模块占位页（指标/批次/看板等后续迭代）
 */
class TmPlaceholder extends Base
{
    /**
     * 占位页展示（功能开发中提示）。
     *
     * @return mixed 渲染 index/placeholder 视图
     */
    public function index()
    {
        $title = $this->request->param('title', '功能开发中');
        $this->assign('title', $title);
        return $this->fetch('index/placeholder');
    }
}

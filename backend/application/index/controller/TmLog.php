<?php
namespace app\index\controller;

use app\index\service\OpLogService;
use think\Db;
use think\Request;

class TmLog extends Base
{
    /**
     * 操作日志管理页。
     *
     * @return mixed 渲染 tm_log/index 视图
     */
    public function index()
    {
        $this->assign('modules', OpLogService::moduleOptions());
        return $this->fetch('tm_log/index');
    }

    /**
     * 操作日志分页列表 API（支持模块、关键词、日期范围筛选）。
     *
     * @return \think\response\Json Layui 分页格式的操作日志列表
     */
    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $module = $req->param('module', '', 'trim');
        $keyword = $req->param('keyword', '', 'trim');
        $dateFrom = $req->param('dateFrom', '', 'trim');
        $dateTo = $req->param('dateTo', '', 'trim');

        $q = Db::name('tm_op_log')->alias('l')
            ->join('tm_admin a', 'a.id = l.admin_id', 'LEFT')
            ->field('l.*, a.name as admin_name, a.username as admin_username');

        if ($module !== '') {
            $q->where('l.module', $module);
        }
        if ($keyword !== '') {
            $q->where('l.action|l.biz_id|l.detail|l.ip|a.name|a.username', 'like', '%' . $keyword . '%');
        }
        if ($dateFrom !== '') {
            $q->where('l.created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $q->where('l.created_at', '<=', $dateTo . ' 23:59:59');
        }

        $countQ = Db::name('tm_op_log')->alias('l')
            ->join('tm_admin a', 'a.id = l.admin_id', 'LEFT');
        if ($module !== '') {
            $countQ->where('l.module', $module);
        }
        if ($keyword !== '') {
            $countQ->where('l.action|l.biz_id|l.detail|l.ip|a.name|a.username', 'like', '%' . $keyword . '%');
        }
        if ($dateFrom !== '') {
            $countQ->where('l.created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $countQ->where('l.created_at', '<=', $dateTo . ' 23:59:59');
        }
        $count = $countQ->count();
        $list = $q->order('l.id desc')->page($page, $limit)->select();

        foreach ($list as &$row) {
            if (empty($row['admin_name'])) {
                $row['admin_name'] = $row['admin_username'] ?: '系统';
            }
        }
        unset($row);
        $list = OpLogService::decorateList($list);

        return json(DefaultResponseList(0, $count, 'ok', $list));
    }
}

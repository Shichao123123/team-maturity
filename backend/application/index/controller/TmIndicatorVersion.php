<?php
namespace app\index\controller;

use app\index\service\IndicatorService;
use app\index\service\OpLogService;
use think\Db;
use think\Request;

class TmIndicatorVersion extends Base
{
    /**
     * 指标版本管理页。
     *
     * @return mixed 渲染 tm_indicator_version/index 视图
     */
    public function index()
    {
        return $this->fetch('tm_indicator_version/index');
    }

    /**
     * 指标版本分页列表 API（含题目数、指标数、权重汇总等）。
     *
     * @return \think\response\Json Layui 分页格式的版本列表
     */
    public function getList()
    {
        $page = (int)Request::instance()->param('page', 1);
        $limit = (int)Request::instance()->param('limit', 15);
        $count = Db::name('tm_indicator_version')->count();
        $list = Db::name('tm_indicator_version')->order('id desc')->page($page, $limit)->select();
        $modeMap = ['first' => '取首题', 'random' => '随机抽一', 'sequential' => '轮询抽一'];
        foreach ($list as &$row) {
            $vid = (int)$row['id'];
            $row['question_cnt'] = (int)Db::name('tm_question')->where('version_id', $vid)->count();
            $row['indicator_cnt'] = (int)Db::name('tm_indicator')->where('version_id', $vid)->where('status', 1)->count();
            $row['status_text'] = [0 => '草稿', 1 => '已发布', 2 => '停用'][(int)$row['status']] ?? '';
            $l1Sum = Db::name('tm_indicator')
                ->where('version_id', $vid)
                ->where('level_no', 1)
                ->where('status', 1)
                ->sum('weight');
            $row['l1_weight_sum'] = round((float)$l1Sum, 2);
            $mode = isset($row['question_pick_mode']) ? $row['question_pick_mode'] : 'random';
            $row['pick_mode_text'] = isset($modeMap[$mode]) ? $modeMap[$mode] : $mode;
            $row['can_edit'] = (int)$row['status'] === 0 ? 1 : 0;
        }
        unset($row);
        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    /**
     * 新建指标版本 API（POST，默认草稿状态）。
     *
     * @return \think\response\Json 成功返回新版本 id
     */
    public function add()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        list($ok, $msg, $id) = IndicatorService::createVersion([
            'name'               => $req->param('name', '', 'trim'),
            'version_no'         => $req->param('version_no', '', 'trim'),
            'remark'             => $req->param('remark', '', 'trim'),
            'question_pick_mode' => $req->param('question_pick_mode', 'random', 'trim'),
        ]);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'add', $id, ['name' => $req->param('name', '', 'trim')]);
        return json(DefaultResponse(0, true, $msg, ['id' => $id]));
    }

    /**
     * 编辑指标版本基本信息 API（POST，仅草稿可改）。
     *
     * @return \think\response\Json 保存结果
     */
    public function edit()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = IndicatorService::updateVersion($id, [
            'name'               => $req->param('name', '', 'trim'),
            'version_no'         => $req->param('version_no', '', 'trim'),
            'remark'             => $req->param('remark', '', 'trim'),
            'question_pick_mode' => $req->param('question_pick_mode', 'random', 'trim'),
        ]);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'edit', $id, null);
        return json(DefaultResponse(0, true, $msg));
    }

    /**
     * 发布指标版本 API（POST，发布后不可再编辑指标/题目）。
     *
     * @return \think\response\Json 发布结果
     */
    public function publish()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = IndicatorService::publish($id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'publish', $id, null);
        return json(DefaultResponse(0, true, $msg));
    }

    /**
     * 停用指标版本 API（POST）。
     *
     * @return \think\response\Json 停用结果
     */
    public function disable()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg) = IndicatorService::disable($id);
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'disable', $id, null);
        return json(DefaultResponse(0, true, $msg));
    }

    /**
     * 复制指标版本 API（POST，含指标树与题目）。
     *
     * @return \think\response\Json 成功返回新版本 id
     */
    public function copy()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        list($ok, $msg, $newId) = IndicatorService::copyVersion(
            $id,
            $req->param('name', '', 'trim'),
            $req->param('version_no', '', 'trim')
        );
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        OpLogService::write('indicator_version', 'copy', $newId, ['from' => $id]);
        return json(DefaultResponse(0, true, $msg, ['id' => $newId]));
    }

    /**
     * 发布前预检 API：校验权重、题目完整性等。
     *
     * @return \think\response\Json 返回 ok 标志与 errors 问题列表
     */
    public function validatePublish()
    {
        $id = (int)Request::instance()->param('id', 0);
        $errors = IndicatorService::validateForPublish($id);
        return json(DefaultResponse(0, true, empty($errors) ? '校验通过' : '存在问题', [
            'ok'     => empty($errors),
            'errors' => $errors,
        ]));
    }
}

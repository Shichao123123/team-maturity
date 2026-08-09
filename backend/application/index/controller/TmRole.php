<?php
namespace app\index\controller;

use app\index\service\OpLogService;
use think\Db;
use think\Request;

class TmRole extends Base
{
    public function index()
    {
        return $this->fetch('tm_role/index');
    }

    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $keyword = $req->param('keyword', '', 'trim');
        $status = $req->param('status', '');

        $q = Db::name('tm_role');
        if ($keyword !== '') {
            $q->where('name|remark', 'like', '%' . $keyword . '%');
        }
        if ($status !== '' && $status !== null) {
            $q->where('status', (int)$status);
        }
        $countQ = Db::name('tm_role');
        if ($keyword !== '') {
            $countQ->where('name|remark', 'like', '%' . $keyword . '%');
        }
        if ($status !== '' && $status !== null) {
            $countQ->where('status', (int)$status);
        }
        $count = $countQ->count();
        $list = $q->order('id asc')->page($page, $limit)->select();

        foreach ($list as &$row) {
            $row['admin_cnt'] = (int)Db::name('tm_admin')
                ->where('role_id', $row['id'])
                ->whereNull('deleted_at')
                ->count();
            $row['power_cnt'] = (int)Db::name('tm_role_power')->where('role_id', $row['id'])->count();
        }
        unset($row);

        return json(DefaultResponseList(0, $count, 'ok', $list));
    }

    public function save()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        $name = $req->param('name', '', 'trim');
        $remark = $req->param('remark', '', 'trim');
        $status = (int)$req->param('status', 1);

        if ($name === '') {
            return json(DefaultResponse(400, false, '请填写角色名称'));
        }
        $dup = Db::name('tm_role')->where('name', $name)->where('id', '<>', $id)->find();
        if ($dup) {
            return json(DefaultResponse(400, false, '角色名称已存在'));
        }

        $data = [
            'name'   => $name,
            'remark' => $remark,
            'status' => $status ? 1 : 0,
        ];

        if ($id > 0) {
            if ($id === 1 && !$data['status']) {
                return json(DefaultResponse(400, false, '超级管理员角色不能停用'));
            }
            $data['updated_at'] = tmNow();
            Db::name('tm_role')->where('id', $id)->update($data);
            OpLogService::write('role', 'edit', $id, ['name' => $name]);
            return json(DefaultResponse(0, true, '角色已更新'));
        }

        $data['created_at'] = tmNow();
        $newId = Db::name('tm_role')->insertGetId($data);
        OpLogService::write('role', 'add', $newId, ['name' => $name]);
        return json(DefaultResponse(0, true, '角色已新增', ['id' => $newId]));
    }

    public function delete()
    {
        $id = (int)Request::instance()->param('id', 0);
        if ($id <= 0) {
            return json(DefaultResponse(400, false, '参数错误'));
        }
        if ($id === 1) {
            return json(DefaultResponse(400, false, '超级管理员角色不能删除'));
        }
        $adminCnt = (int)Db::name('tm_admin')->where('role_id', $id)->whereNull('deleted_at')->count();
        if ($adminCnt > 0) {
            return json(DefaultResponse(400, false, '该角色下仍有管理员，无法删除'));
        }
        Db::name('tm_role_power')->where('role_id', $id)->delete();
        Db::name('tm_role')->where('id', $id)->delete();
        OpLogService::write('role', 'delete', $id);
        return json(DefaultResponse(0, true, '已删除'));
    }

    /**
     * 权限树（layui tree 格式）
     */
    public function powerTree()
    {
        $roleId = (int)Request::instance()->param('roleId', 0);
        $checked = [];
        if ($roleId > 0) {
            $checked = Db::name('tm_role_power')->where('role_id', $roleId)->column('power_id');
            $checked = array_map('intval', $checked ?: []);
        }
        $rows = Db::name('tm_power')->where('status', 1)->order('sort_no asc,id asc')->select();
        $tree = $this->buildPowerTree($rows, 0, $checked);
        return json(DefaultResponse(0, true, 'ok', $tree));
    }

    public function savePowers()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $roleId = (int)$req->param('role_id', 0);
        if ($roleId <= 0 || !Db::name('tm_role')->where('id', $roleId)->find()) {
            return json(DefaultResponse(400, false, '角色不存在'));
        }

        $powerIds = $req->param('power_ids/a', []);
        if (!is_array($powerIds)) {
            $raw = $req->param('power_ids', '');
            if (is_string($raw) && $raw !== '') {
                $powerIds = explode(',', $raw);
            } else {
                $powerIds = [];
            }
        }
        $powerIds = array_values(array_unique(array_filter(array_map('intval', $powerIds))));

        // 勾选子菜单时自动带上父级，保证侧栏可展示
        if ($powerIds) {
            $all = Db::name('tm_power')->column('parent_id', 'id');
            $withParent = $powerIds;
            foreach ($powerIds as $pid) {
                $cur = $pid;
                while (isset($all[$cur]) && (int)$all[$cur] > 0) {
                    $parent = (int)$all[$cur];
                    if (!in_array($parent, $withParent, true)) {
                        $withParent[] = $parent;
                    }
                    $cur = $parent;
                }
            }
            $powerIds = $withParent;
        }

        if ($roleId === 1 && !$powerIds) {
            return json(DefaultResponse(400, false, '超级管理员至少保留一个菜单权限'));
        }

        Db::startTrans();
        try {
            Db::name('tm_role_power')->where('role_id', $roleId)->delete();
            foreach ($powerIds as $pid) {
                if ($pid <= 0) {
                    continue;
                }
                Db::name('tm_role_power')->insert([
                    'role_id'  => $roleId,
                    'power_id' => $pid,
                ]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(DefaultResponse(500, false, $e->getMessage()));
        }

        OpLogService::write('role', 'save_powers', $roleId, ['power_cnt' => count($powerIds)]);
        return json(DefaultResponse(0, true, '权限已保存'));
    }

    private function buildPowerTree($rows, $parentId, $checked)
    {
        $tree = [];
        foreach ($rows as $row) {
            if ((int)$row['parent_id'] !== (int)$parentId) {
                continue;
            }
            $node = [
                'id'       => (int)$row['id'],
                'title'    => $row['name'],
                'spread'   => true,
                'checked'  => in_array((int)$row['id'], $checked, true),
                'children' => $this->buildPowerTree($rows, $row['id'], $checked),
            ];
            $tree[] = $node;
        }
        return $tree;
    }
}

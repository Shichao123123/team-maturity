<?php
namespace app\index\controller;

use app\index\service\OpLogService;
use think\Db;
use think\Request;

class TmAdmin extends Base
{
    public function index()
    {
        $roles = Db::name('tm_role')->where('status', 1)->order('id asc')->select();
        $this->assign('roles', $roles);
        return $this->fetch('tm_admin/index');
    }

    public function getList()
    {
        $req = Request::instance();
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 15);
        $keyword = $req->param('keyword', '', 'trim');
        $roleId = $req->param('roleId', '');
        $status = $req->param('status', '');

        $q = Db::name('tm_admin')->alias('a')
            ->join('tm_role r', 'r.id = a.role_id', 'LEFT')
            ->whereNull('a.deleted_at')
            ->field('a.id,a.name,a.username,a.mobile,a.role_id,a.status,a.last_login_at,a.login_count,a.created_at,r.name as role_name');

        if ($keyword !== '') {
            $q->where('a.name|a.username|a.mobile', 'like', '%' . $keyword . '%');
        }
        if ($roleId !== '' && $roleId !== null) {
            $q->where('a.role_id', (int)$roleId);
        }
        if ($status !== '' && $status !== null) {
            $q->where('a.status', (int)$status);
        }

        $countQ = Db::name('tm_admin')->alias('a')->whereNull('a.deleted_at');
        if ($keyword !== '') {
            $countQ->where('a.name|a.username|a.mobile', 'like', '%' . $keyword . '%');
        }
        if ($roleId !== '' && $roleId !== null) {
            $countQ->where('a.role_id', (int)$roleId);
        }
        if ($status !== '' && $status !== null) {
            $countQ->where('a.status', (int)$status);
        }
        $count = $countQ->count();
        $list = $q->order('a.id asc')->page($page, $limit)->select();

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
        $username = $req->param('username', '', 'trim');
        $mobile = $req->param('mobile', '', 'trim');
        $roleId = (int)$req->param('role_id', 0);
        $status = (int)$req->param('status', 1);
        $password = $req->param('password', '', 'trim');

        if ($name === '' || $username === '' || $roleId <= 0) {
            return json(DefaultResponse(400, false, '请填写姓名、登录账号和角色'));
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            return json(DefaultResponse(400, false, '登录账号需为 3~32 位字母数字或下划线'));
        }
        if ($mobile !== '' && !preg_match('/^1\d{10}$/', $mobile)) {
            return json(DefaultResponse(400, false, '手机号格式不正确'));
        }
        if (!Db::name('tm_role')->where('id', $roleId)->find()) {
            return json(DefaultResponse(400, false, '角色不存在'));
        }

        $dup = Db::name('tm_admin')
            ->where('username', $username)
            ->whereNull('deleted_at')
            ->where('id', '<>', $id)
            ->find();
        if ($dup) {
            return json(DefaultResponse(400, false, '登录账号已存在'));
        }

        $selfId = isset($this->currentAdmin['id']) ? (int)$this->currentAdmin['id'] : 0;
        if ($id > 0 && $id === $selfId && $status !== 1) {
            return json(DefaultResponse(400, false, '不能停用当前登录账号'));
        }

        $data = [
            'name'     => $name,
            'username' => $username,
            'mobile'   => $mobile !== '' ? $mobile : null,
            'role_id'  => $roleId,
            'status'   => $status ? 1 : 0,
        ];

        if ($id > 0) {
            if ($password !== '') {
                if (strlen($password) < 6) {
                    return json(DefaultResponse(400, false, '密码至少 6 位'));
                }
                $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $data['updated_at'] = tmNow();
            Db::name('tm_admin')->where('id', $id)->whereNull('deleted_at')->update($data);
            OpLogService::write('admin', 'edit', $id, ['username' => $username]);
            return json(DefaultResponse(0, true, '管理员已更新'));
        }

        if ($password === '' || strlen($password) < 6) {
            return json(DefaultResponse(400, false, '新增时密码至少 6 位'));
        }
        $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        $data['created_at'] = tmNow();
        $newId = Db::name('tm_admin')->insertGetId($data);
        OpLogService::write('admin', 'add', $newId, ['username' => $username]);
        return json(DefaultResponse(0, true, '管理员已新增', ['id' => $newId]));
    }

    public function delete()
    {
        $id = (int)Request::instance()->param('id', 0);
        if ($id <= 0) {
            return json(DefaultResponse(400, false, '参数错误'));
        }
        $selfId = isset($this->currentAdmin['id']) ? (int)$this->currentAdmin['id'] : 0;
        if ($id === $selfId) {
            return json(DefaultResponse(400, false, '不能删除当前登录账号'));
        }
        $row = Db::name('tm_admin')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$row) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        Db::name('tm_admin')->where('id', $id)->update([
            'deleted_at' => tmNow(),
            'updated_at' => tmNow(),
            'status'     => 0,
        ]);
        OpLogService::write('admin', 'delete', $id, ['username' => $row['username']]);
        return json(DefaultResponse(0, true, '已删除'));
    }

    public function resetPassword()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return json(DefaultResponse(405, false, 'method not allowed'));
        }
        $id = (int)$req->param('id', 0);
        $password = $req->param('password', '', 'trim');
        if ($id <= 0) {
            return json(DefaultResponse(400, false, '参数错误'));
        }
        if (strlen($password) < 6) {
            return json(DefaultResponse(400, false, '密码至少 6 位'));
        }
        $row = Db::name('tm_admin')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$row) {
            return json(DefaultResponse(404, false, '记录不存在'));
        }
        Db::name('tm_admin')->where('id', $id)->update([
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'updated_at'    => tmNow(),
        ]);
        OpLogService::write('admin', 'reset_password', $id, ['username' => $row['username']]);
        return json(DefaultResponse(0, true, '密码已重置'));
    }
}

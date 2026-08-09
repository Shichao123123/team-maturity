<?php
namespace app\index\model;

use think\Db;
use think\Model;

class AdminModel extends Model
{
    protected $table = 'tm_admin';

    /**
     * 按登录账号查询未删除的管理员。
     *
     * @param string $username 登录账号
     * @return array|null 管理员行（含 password_hash），不存在时 null
     */
    public function getByUsername($username)
    {
        return Db::name('tm_admin')
            ->where('username', $username)
            ->whereNull('deleted_at')
            ->find();
    }

    /**
     * 更新管理员最近登录时间与登录次数。
     *
     * @param int $id 管理员 id
     * @return int 受影响行数
     */
    public function touchLogin($id)
    {
        Db::name('tm_admin')->where('id', $id)->setInc('login_count');
        return Db::name('tm_admin')->where('id', $id)->update([
            'last_login_at' => tmNow(),
            'updated_at'    => tmNow(),
        ]);
    }
}

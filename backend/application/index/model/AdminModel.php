<?php
namespace app\index\model;

use think\Db;
use think\Model;

class AdminModel extends Model
{
    protected $table = 'tm_admin';

    public function getByUsername($username)
    {
        return Db::name('tm_admin')
            ->where('username', $username)
            ->whereNull('deleted_at')
            ->find();
    }

    public function touchLogin($id)
    {
        Db::name('tm_admin')->where('id', $id)->setInc('login_count');
        return Db::name('tm_admin')->where('id', $id)->update([
            'last_login_at' => tmNow(),
            'updated_at'    => tmNow(),
        ]);
    }
}

<?php
namespace app\index\model;

use think\Db;
use think\Model;

class MemberModel extends Model
{
    protected $table = 'tm_member';

    public function getPage($teamId = null, $keyword = '', $roleCode = '', $status = null, $page = 1, $limit = 15)
    {
        $apply = function ($q) use ($teamId, $keyword, $roleCode, $status) {
            $q->whereNull('m.deleted_at');
            if ($teamId !== null && $teamId !== '') {
                $q->where('m.team_id', (int)$teamId);
            }
            if ($keyword !== '') {
                $q->where('m.name|m.mobile', 'like', '%' . $keyword . '%');
            }
            if ($roleCode !== '') {
                $q->where('m.role_code', $roleCode);
            }
            if ($status !== null && $status !== '') {
                $q->where('m.status', (int)$status);
            }
            return $q;
        };

        $count = $apply(Db::name('tm_member')->alias('m'))->count();
        $list = $apply(
            Db::name('tm_member')->alias('m')
                ->join('tm_team t', 't.id = m.team_id', 'LEFT')
                ->field('m.*, t.name as team_name')
        )->order('m.id desc')->page($page, $limit)->select();

        return [$count, $list];
    }

    public function find($id)
    {
        return Db::name('tm_member')->where('id', $id)->whereNull('deleted_at')->find();
    }

    public function existsMobile($mobile, $excludeId = 0)
    {
        $q = Db::name('tm_member')->where('mobile', $mobile)->whereNull('deleted_at');
        if ($excludeId) {
            $q->where('id', '<>', $excludeId);
        }
        return $q->count() > 0;
    }

    public function addRow($data)
    {
        $data['created_at'] = tmNow();
        return Db::name('tm_member')->insertGetId($data);
    }

    public function editRow($id, $data)
    {
        $data['updated_at'] = tmNow();
        return Db::name('tm_member')->where('id', $id)->update($data);
    }

    public function softDelete($id)
    {
        Db::name('tm_member')->where('id', $id)->update(['deleted_at' => tmNow()]);
        return true;
    }
}

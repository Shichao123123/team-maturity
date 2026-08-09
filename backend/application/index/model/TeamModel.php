<?php
namespace app\index\model;

use think\Db;
use think\Model;

class TeamModel extends Model
{
    protected $table = 'tm_team';

    public function getPage($orgId = null, $keyword = '', $status = null, $page = 1, $limit = 15)
    {
        $apply = function ($q) use ($orgId, $keyword, $status) {
            $q->whereNull('t.deleted_at');
            if ($orgId !== null && $orgId !== '') {
                $q->where('t.org_id', (int)$orgId);
            }
            if ($keyword !== '') {
                $q->where('t.name', 'like', '%' . $keyword . '%');
            }
            if ($status !== null && $status !== '') {
                $q->where('t.status', (int)$status);
            }
            return $q;
        };

        $count = $apply(Db::name('tm_team')->alias('t'))->count();
        $list = $apply(
            Db::name('tm_team')->alias('t')
                ->join('tm_org o', 'o.id = t.org_id', 'LEFT')
                ->field('t.*, o.name as org_name')
        )->order('t.id desc')->page($page, $limit)->select();

        return [$count, $list];
    }

    public function find($id)
    {
        return Db::name('tm_team')->where('id', $id)->whereNull('deleted_at')->find();
    }

    public function addRow($data)
    {
        $data['created_at'] = tmNow();
        return Db::name('tm_team')->insertGetId($data);
    }

    public function editRow($id, $data)
    {
        $data['updated_at'] = tmNow();
        return Db::name('tm_team')->where('id', $id)->update($data);
    }

    public function softDelete($id)
    {
        $member = Db::name('tm_member')->where('team_id', $id)->whereNull('deleted_at')->count();
        if ($member > 0) {
            return '班组下仍有成员，无法删除';
        }
        Db::name('tm_team')->where('id', $id)->update(['deleted_at' => tmNow()]);
        return true;
    }
}

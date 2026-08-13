<?php
namespace app\index\model;

use think\Db;
use think\Model;

class TeamModel extends Model
{
    protected $table = 'tm_team';

    /**
     * 班组分页查询（支持组织、名称、状态筛选）。
     *
     * @param int|null    $orgId   所属组织 id，null 表示不限
     * @param string      $keyword 班组名称模糊搜索
     * @param int|null    $status  启用状态，null 表示不限
     * @param int         $page    页码
     * @param int         $limit   每页条数
     * @return array [总数, 列表行（含 org_name）]
     */
    public function getPage($orgId = null, $keyword = '', $status = null, $page = 1, $limit = 15, $orgIds = null)
    {
        $apply = function ($q) use ($orgId, $keyword, $status, $orgIds) {
            $q->whereNull('t.deleted_at');
            if (is_array($orgIds) && count($orgIds) > 0) {
                $q->whereIn('t.org_id', array_map('intval', $orgIds));
            } elseif ($orgId !== null && $orgId !== '') {
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

    /**
     * 按 id 查询未删除的班组。
     *
     * @param int $id 班组 id
     * @return array|null 班组行，不存在时 null
     */
    public function find($id)
    {
        return Db::name('tm_team')->where('id', $id)->whereNull('deleted_at')->find();
    }

    /**
     * 新增班组记录。
     *
     * @param array $data 班组字段
     * @return int 新记录 id
     */
    public function addRow($data)
    {
        $data['created_at'] = tmNow();
        return Db::name('tm_team')->insertGetId($data);
    }

    /**
     * 更新班组记录。
     *
     * @param int   $id   班组 id
     * @param array $data 待更新字段
     * @return int 受影响行数
     */
    public function editRow($id, $data)
    {
        $data['updated_at'] = tmNow();
        return Db::name('tm_team')->where('id', $id)->update($data);
    }

    /**
     * 软删除班组（存在成员时拒绝删除）。
     *
     * @param int $id 班组 id
     * @return bool|string 成功返回 true，失败返回错误提示字符串
     */
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

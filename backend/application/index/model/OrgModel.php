<?php
namespace app\index\model;

use think\Db;
use think\Model;

class OrgModel extends Model
{
    protected $table = 'tm_org';

    /**
     * 查询全部未删除的组织，按排序号与 id 升序。
     *
     * @return array 组织行列表
     */
    public function getAllActive()
    {
        return Db::name('tm_org')
            ->whereNull('deleted_at')
            ->order('sort_no asc,id asc')
            ->select();
    }

    /**
     * 组织分页查询（支持上级组织、名称关键词筛选）。
     *
     * @param int|null    $parentId 上级组织 id，null 表示不限
     * @param string      $keyword  组织名称模糊搜索
     * @param int         $page     页码
     * @param int         $limit    每页条数
     * @return array [总数, 列表行（含 parent_name）]
     */
    public function getPage($parentId = null, $keyword = '', $page = 1, $limit = 15)
    {
        $apply = function ($q) use ($parentId, $keyword) {
            $q->whereNull('o.deleted_at');
            if ($parentId !== null && $parentId !== '') {
                $q->where('o.parent_id', (int)$parentId);
            }
            if ($keyword !== '') {
                $q->where('o.name', 'like', '%' . $keyword . '%');
            }
            return $q;
        };

        $count = $apply(Db::name('tm_org')->alias('o'))->count();
        $list = $apply(Db::name('tm_org')->alias('o'))
            ->order('o.sort_no asc,o.id asc')
            ->page($page, $limit)
            ->select();

        $nameMap = Db::name('tm_org')->whereNull('deleted_at')->column('name', 'id');
        foreach ($list as &$row) {
            $pid = (int)$row['parent_id'];
            $row['parent_name'] = ($pid > 0 && isset($nameMap[$pid])) ? $nameMap[$pid] : '';
        }
        unset($row);

        return [$count, $list];
    }

    /**
     * 按 id 查询未删除的组织。
     *
     * @param int $id 组织 id
     * @return array|null 组织行，不存在时 null
     */
    public function find($id)
    {
        return Db::name('tm_org')->where('id', $id)->whereNull('deleted_at')->find();
    }

    /**
     * 新增组织记录。
     *
     * @param array $data 组织字段
     * @return int 新记录 id
     */
    public function addRow($data)
    {
        $data['created_at'] = tmNow();
        return Db::name('tm_org')->insertGetId($data);
    }

    /**
     * 更新组织记录。
     *
     * @param int   $id   组织 id
     * @param array $data 待更新字段
     * @return int 受影响行数
     */
    public function editRow($id, $data)
    {
        $data['updated_at'] = tmNow();
        return Db::name('tm_org')->where('id', $id)->update($data);
    }

    /**
     * 软删除组织（存在下级组织或班组时拒绝删除）。
     *
     * @param int $id 组织 id
     * @return bool|string 成功返回 true，失败返回错误提示字符串
     */
    public function softDelete($id)
    {
        $child = Db::name('tm_org')->where('parent_id', $id)->whereNull('deleted_at')->count();
        if ($child > 0) {
            return '存在下级组织，无法删除';
        }
        $team = Db::name('tm_team')->where('org_id', $id)->whereNull('deleted_at')->count();
        if ($team > 0) {
            return '组织下仍有班组，无法删除';
        }
        Db::name('tm_org')->where('id', $id)->update(['deleted_at' => tmNow()]);
        return true;
    }

    /**
     * 下拉选项：带路径，兼容 2 层 / 3 层 / 更深。
     *
     * @return array [['id'=>,'name'=>,'path'=>,'depth'=>], ...]
     */
    public function getSelectOptions()
    {
        $rows = $this->getAllActive();
        $byParent = [];
        foreach ($rows as $r) {
            $pid = (int)$r['parent_id'];
            if (!isset($byParent[$pid])) {
                $byParent[$pid] = [];
            }
            $byParent[$pid][] = $r;
        }
        $out = [];
        $walk = function ($parentId, $prefix, $depth) use (&$walk, &$out, $byParent) {
            if (empty($byParent[$parentId])) {
                return;
            }
            foreach ($byParent[$parentId] as $r) {
                $path = $prefix === '' ? $r['name'] : ($prefix . ' / ' . $r['name']);
                $out[] = [
                    'id'    => (int)$r['id'],
                    'name'  => $r['name'],
                    'path'  => $path,
                    'depth' => $depth,
                ];
                $walk((int)$r['id'], $path, $depth + 1);
            }
        };
        $walk(0, '', 1);
        return $out;
    }

    /**
     * 收集某节点全部子孙组织 id（含自身），供看板/批次按组织下钻。
     *
     * @param int $orgId 组织 id
     * @return array 组织 id 列表
     */
    public function collectDescendantIds($orgId)
    {
        $ids = [(int)$orgId];
        $children = Db::name('tm_org')->where('parent_id', $orgId)->whereNull('deleted_at')->column('id');
        foreach ($children as $cid) {
            $ids = array_merge($ids, $this->collectDescendantIds($cid));
        }
        return $ids;
    }
}

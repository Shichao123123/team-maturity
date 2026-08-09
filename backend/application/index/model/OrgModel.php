<?php
namespace app\index\model;

use think\Db;
use think\Model;

class OrgModel extends Model
{
    protected $table = 'tm_org';

    public function getAllActive()
    {
        return Db::name('tm_org')
            ->whereNull('deleted_at')
            ->order('sort_no asc,id asc')
            ->select();
    }

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

    public function find($id)
    {
        return Db::name('tm_org')->where('id', $id)->whereNull('deleted_at')->find();
    }

    public function addRow($data)
    {
        $data['created_at'] = tmNow();
        return Db::name('tm_org')->insertGetId($data);
    }

    public function editRow($id, $data)
    {
        $data['updated_at'] = tmNow();
        return Db::name('tm_org')->where('id', $id)->update($data);
    }

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
     * 下拉选项：带路径，兼容 2 层 / 3 层 / 更深
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

    /** 某节点全部子孙 ID（含自身），供后期看板/批次按组织下钻 */
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

<?php
namespace app\index\model;

use think\Db;
use think\Model;

class PowerModel extends Model
{
    protected $table = 'tm_power';

    /**
     * 按角色 id 获取 Pear Admin 侧栏菜单树 JSON。
     *
     * @param int $roleId 角色 id
     * @return array Pear Admin 菜单树节点列表
     */
    public function getMenuByRoleId($roleId)
    {
        $powerIds = Db::name('tm_role_power')->where('role_id', $roleId)->column('power_id');
        if (empty($powerIds)) {
            return [];
        }

        $rows = Db::name('tm_power')
            ->where('id', 'in', $powerIds)
            ->where('status', 1)
            ->where('type', 1)
            ->order('sort_no asc,id asc')
            ->select();

        return $this->buildPearTree($rows, 0);
    }

    /**
     * 递归构建 Pear Admin 菜单树。
     *
     * @param array $rows     全部权限行
     * @param int   $parentId 父级权限 id
     * @return array 含 title、icon、href、children 的菜单节点列表
     */
    private function buildPearTree($rows, $parentId)
    {
        $tree = [];
        foreach ($rows as $row) {
            if ((int)$row['parent_id'] !== (int)$parentId) {
                continue;
            }
            $children = $this->buildPearTree($rows, $row['id']);
            $icon = $row['icon'] ?: 'layui-icon-app';
            if (strpos($icon, 'layui-icon') === false) {
                $icon = 'layui-icon ' . $icon;
            } elseif (strpos($icon, 'layui-icon ') === false) {
                $icon = 'layui-icon ' . $icon;
            }

            $node = [
                'id'    => (int)$row['id'],
                'title' => $row['name'],
                'icon'  => $icon,
                'href'  => $row['url'] ?: '',
            ];

            if (!empty($children)) {
                $node['type'] = 0;
                $node['children'] = $children;
            } else {
                $node['type'] = 1;
                $node['openType'] = '_iframe';
            }
            $tree[] = $node;
        }
        return $tree;
    }
}

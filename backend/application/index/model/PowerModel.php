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
     * 判断角色是否拥有访问某后台路径的菜单权限。
     * 与侧栏同源：tm_role_power + tm_power.url。
     *
     * @param int    $roleId 角色 ID
     * @param string $path   如 /TmAdmin/index 或 /TmAdmin/save
     * @return bool
     */
    public function roleCanAccessPath($roleId, $path)
    {
        $roleId = (int)$roleId;
        $path = self::normalizePowerPath($path);
        if ($roleId <= 0 || $path === '') {
            return false;
        }

        $urls = Db::name('tm_power')->alias('p')
            ->join('tm_role_power rp', 'rp.power_id = p.id')
            ->where('rp.role_id', $roleId)
            ->where('p.status', 1)
            ->where('p.url', '<>', '')
            ->column('p.url');

        $allowed = [];
        foreach ($urls as $url) {
            $n = self::normalizePowerPath($url);
            if ($n !== '') {
                $allowed[$n] = true;
            }
        }
        if (empty($allowed)) {
            return false;
        }

        // 精确命中菜单 URL（如 /TmDashboard/trend）
        if (isset($allowed[$path])) {
            return true;
        }

        // 同控制器下：拥有任一菜单页即可调用该控制器其它 action（如有 /TmAdmin/index 则可 save）
        // 但对同一控制器挂多个菜单页的（看板），仅允许「已授权菜单页」及其专属 API，见 Base 映射
        $parts = explode('/', ltrim($path, '/'));
        if (count($parts) < 1) {
            return false;
        }
        $controller = $parts[0];
        foreach (array_keys($allowed) as $url) {
            $up = explode('/', ltrim($url, '/'));
            if (isset($up[0]) && strcasecmp($up[0], $controller) === 0) {
                // 多入口控制器（TmDashboard）：不能用「任意页」放行，需精确或走 action 映射
                if (strcasecmp($controller, 'TmDashboard') === 0) {
                    continue;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * 规范化权限路径：去域名、去查询串、统一前导斜杠。
     *
     * @param string $path
     * @return string
     */
    public static function normalizePowerPath($path)
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (strpos($path, '://') !== false) {
            $path = parse_url($path, PHP_URL_PATH) ?: '';
        }
        $path = strtok($path, '?');
        $path = '/' . trim(str_replace('\\', '/', (string)$path), '/');
        return $path === '/' ? '' : $path;
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

<?php
namespace app\index\service;

use think\Db;

/**
 * 组织 / 班组 / 成员管理页的树表（扁平 id/pid）数据。
 */
class ArchiveTreeService
{
    /**
     * 组织树扁平列表（pear treetable）。
     *
     * @param string $keyword 名称关键词；命中时连带祖先节点一并返回
     * @return array
     */
    public static function orgFlat($keyword = '')
    {
        $rows = Db::name('tm_org')
            ->whereNull('deleted_at')
            ->order('sort_no asc,id asc')
            ->select();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int)$r['id']] = $r;
        }

        $keep = null;
        $keyword = trim((string)$keyword);
        if ($keyword !== '') {
            $keep = [];
            foreach ($rows as $r) {
                if (mb_strpos($r['name'], $keyword) === false) {
                    continue;
                }
                $id = (int)$r['id'];
                $keep[$id] = true;
                $pid = (int)$r['parent_id'];
                while ($pid > 0 && isset($byId[$pid])) {
                    $keep[$pid] = true;
                    $pid = (int)$byId[$pid]['parent_id'];
                }
            }
        }

        $list = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if ($keep !== null && empty($keep[$id])) {
                continue;
            }
            $pid = (int)$r['parent_id'];
            $list[] = [
                'id'          => $id,
                'pid'         => $pid > 0 ? $pid : 0,
                'node_type'   => 'org',
                'name'        => $r['name'],
                'org_type'    => (int)$r['org_type'],
                'sort_no'     => (int)$r['sort_no'],
                'status'      => (int)$r['status'],
                'parent_id'   => $pid,
                'parent_name' => ($pid > 0 && isset($byId[$pid])) ? $byId[$pid]['name'] : '',
            ];
        }
        return $list;
    }

    /**
     * 班组管理树：组织节点 + 班组叶子（id 前缀避免冲突）。
     *
     * @param int|null $orgId   仅展示某组织子树（含自身）
     * @param string   $keyword 班组名称关键词
     * @return array
     */
    public static function teamFlat($orgId = null, $keyword = '')
    {
        $orgs = Db::name('tm_org')->whereNull('deleted_at')->order('sort_no asc,id asc')->select();
        $teams = Db::name('tm_team')->whereNull('deleted_at')->order('id asc')->select();

        $orgById = [];
        foreach ($orgs as $o) {
            $orgById[(int)$o['id']] = $o;
        }

        $orgId = ($orgId !== null && $orgId !== '') ? (int)$orgId : 0;
        $allowedOrg = null;
        if ($orgId > 0) {
            $allowedOrg = self::orgSubtreeIds($orgById, $orgId);
        }

        $keyword = trim((string)$keyword);
        $matchedTeamOrgIds = [];
        $matchedTeamIds = [];
        foreach ($teams as $t) {
            $tid = (int)$t['id'];
            $oid = (int)$t['org_id'];
            if ($allowedOrg !== null && empty($allowedOrg[$oid])) {
                continue;
            }
            if ($keyword !== '' && mb_strpos($t['name'], $keyword) === false) {
                continue;
            }
            $matchedTeamIds[$tid] = true;
            $matchedTeamOrgIds[$oid] = true;
        }

        $keepOrg = null;
        if ($keyword !== '') {
            $keepOrg = [];
            foreach ($matchedTeamOrgIds as $oid => $_) {
                self::collectAncestors($orgById, (int)$oid, $keepOrg);
            }
        } elseif ($allowedOrg !== null) {
            $keepOrg = $allowedOrg;
        }

        $list = [];
        foreach ($orgs as $o) {
            $id = (int)$o['id'];
            if ($keepOrg !== null && empty($keepOrg[$id])) {
                continue;
            }
            if ($allowedOrg !== null && empty($allowedOrg[$id])) {
                continue;
            }
            $pid = (int)$o['parent_id'];
            // 筛选某组织时，将该组织提为根，避免父级不在结果里导致断树
            if ($orgId > 0 && $id === $orgId) {
                $treePid = 0;
            } else {
                $treePid = ($pid > 0) ? ('o_' . $pid) : 0;
                if ($keepOrg !== null && $pid > 0 && empty($keepOrg[$pid])) {
                    $treePid = 0;
                }
            }
            $list[] = [
                'id'        => 'o_' . $id,
                'pid'       => $treePid,
                'node_type' => 'org',
                'real_id'   => $id,
                'name'      => $o['name'],
                'org_name'  => '',
                'status'    => (int)$o['status'],
                'status_text' => ((int)$o['status'] === 1) ? '启用' : '停用',
            ];
        }

        foreach ($teams as $t) {
            $tid = (int)$t['id'];
            $oid = (int)$t['org_id'];
            if ($keyword !== '') {
                if (empty($matchedTeamIds[$tid])) {
                    continue;
                }
            } elseif ($allowedOrg !== null && empty($allowedOrg[$oid])) {
                continue;
            }
            $list[] = [
                'id'          => 't_' . $tid,
                'pid'         => 'o_' . $oid,
                'node_type'   => 'team',
                'real_id'     => $tid,
                'name'        => $t['name'],
                'org_id'      => $oid,
                'org_name'    => isset($orgById[$oid]) ? $orgById[$oid]['name'] : '',
                'status'      => (int)$t['status'],
                'status_text' => ((int)$t['status'] === 1) ? '启用' : '停用',
            ];
        }
        return $list;
    }

    /**
     * 成员管理树：组织 → 班组 → 成员。
     *
     * @param int|null $teamId
     * @param string   $keyword
     * @param string   $roleCode
     * @param mixed    $status
     * @return array
     */
    public static function memberFlat($teamId = null, $keyword = '', $roleCode = '', $status = null)
    {
        $orgs = Db::name('tm_org')->whereNull('deleted_at')->order('sort_no asc,id asc')->select();
        $teams = Db::name('tm_team')->whereNull('deleted_at')->order('id asc')->select();
        $members = Db::name('tm_member')->whereNull('deleted_at')->order('id asc')->select();

        $orgById = [];
        foreach ($orgs as $o) {
            $orgById[(int)$o['id']] = $o;
        }
        $teamById = [];
        foreach ($teams as $t) {
            $teamById[(int)$t['id']] = $t;
        }

        $teamId = ($teamId !== null && $teamId !== '') ? (int)$teamId : 0;
        $keyword = trim((string)$keyword);
        $roleCode = trim((string)$roleCode);
        $statusFilter = ($status !== null && $status !== '') ? (int)$status : null;

        $matchedMembers = [];
        $needTeamIds = [];
        $needOrgIds = [];
        foreach ($members as $m) {
            $mid = (int)$m['id'];
            $tid = (int)$m['team_id'];
            if ($teamId > 0 && $tid !== $teamId) {
                continue;
            }
            if ($roleCode !== '' && $m['role_code'] !== $roleCode) {
                continue;
            }
            if ($statusFilter !== null && (int)$m['status'] !== $statusFilter) {
                continue;
            }
            if ($keyword !== '') {
                $hit = (mb_strpos($m['name'], $keyword) !== false)
                    || (mb_strpos((string)$m['mobile'], $keyword) !== false);
                if (!$hit) {
                    continue;
                }
            }
            $matchedMembers[$mid] = $m;
            $needTeamIds[$tid] = true;
            if (isset($teamById[$tid])) {
                $needOrgIds[(int)$teamById[$tid]['org_id']] = true;
            }
        }

        // 无成员筛选条件时仍展示组织/班组骨架；有筛选则只展示命中链路
        $filtered = ($teamId > 0 || $keyword !== '' || $roleCode !== '' || $statusFilter !== null);
        $keepOrg = [];
        $keepTeam = [];
        if ($filtered) {
            foreach ($needTeamIds as $tid => $_) {
                $keepTeam[(int)$tid] = true;
            }
            foreach ($needOrgIds as $oid => $_) {
                self::collectAncestors($orgById, (int)$oid, $keepOrg);
            }
            if ($teamId > 0 && isset($teamById[$teamId])) {
                $keepTeam[$teamId] = true;
                self::collectAncestors($orgById, (int)$teamById[$teamId]['org_id'], $keepOrg);
            }
        }

        $labels = function_exists('tmRoleLabels') ? tmRoleLabels() : [];
        $list = [];

        foreach ($orgs as $o) {
            $id = (int)$o['id'];
            if ($filtered && empty($keepOrg[$id])) {
                continue;
            }
            $pid = (int)$o['parent_id'];
            $treePid = ($pid > 0) ? ('o_' . $pid) : 0;
            if ($filtered && $pid > 0 && empty($keepOrg[$pid])) {
                $treePid = 0;
            }
            $list[] = [
                'id'        => 'o_' . $id,
                'pid'       => $treePid,
                'node_type' => 'org',
                'real_id'   => $id,
                'name'      => $o['name'],
                'team_name' => '',
                'mobile'    => '',
                'role_name' => '',
                'status_text' => '',
            ];
        }

        foreach ($teams as $t) {
            $tid = (int)$t['id'];
            $oid = (int)$t['org_id'];
            if ($filtered && empty($keepTeam[$tid])) {
                continue;
            }
            $list[] = [
                'id'        => 't_' . $tid,
                'pid'       => 'o_' . $oid,
                'node_type' => 'team',
                'real_id'   => $tid,
                'name'      => $t['name'],
                'team_name' => $t['name'],
                'mobile'    => '',
                'role_name' => '',
                'status_text' => ((int)$t['status'] === 1) ? '启用' : '停用',
            ];
        }

        foreach ($matchedMembers as $m) {
            $mid = (int)$m['id'];
            $tid = (int)$m['team_id'];
            $list[] = [
                'id'          => 'm_' . $mid,
                'pid'         => 't_' . $tid,
                'node_type'   => 'member',
                'real_id'     => $mid,
                'name'        => $m['name'],
                'team_id'     => $tid,
                'team_name'   => isset($teamById[$tid]) ? $teamById[$tid]['name'] : '',
                'mobile'      => $m['mobile'],
                'role_code'   => $m['role_code'],
                'role_name'   => isset($labels[$m['role_code']]) ? $labels[$m['role_code']] : $m['role_code'],
                'status'      => (int)$m['status'],
                'status_text' => ((int)$m['status'] === 1) ? '启用' : '停用',
                'post_name'   => isset($m['post_name']) ? $m['post_name'] : '',
            ];
        }

        return $list;
    }

    /**
     * @param array $orgById
     * @param int   $orgId
     * @return array id => true
     */
    private static function orgSubtreeIds(array $orgById, $orgId)
    {
        $children = [];
        foreach ($orgById as $id => $o) {
            $pid = (int)$o['parent_id'];
            if (!isset($children[$pid])) {
                $children[$pid] = [];
            }
            $children[$pid][] = (int)$id;
        }
        $keep = [];
        $stack = [(int)$orgId];
        while ($stack) {
            $cur = array_pop($stack);
            if (isset($keep[$cur])) {
                continue;
            }
            $keep[$cur] = true;
            if (!empty($children[$cur])) {
                foreach ($children[$cur] as $cid) {
                    $stack[] = $cid;
                }
            }
        }
        return $keep;
    }

    /**
     * @param array $orgById
     * @param int   $orgId
     * @param array $keep
     * @return void
     */
    private static function collectAncestors(array $orgById, $orgId, array &$keep)
    {
        $id = (int)$orgId;
        while ($id > 0 && isset($orgById[$id])) {
            $keep[$id] = true;
            $id = (int)$orgById[$id]['parent_id'];
        }
    }

    /**
     * 某组织及其全部下级组织 id 列表。
     *
     * @param int $orgId
     * @return int[]
     */
    public static function orgSubtreeIdList($orgId)
    {
        $orgId = (int)$orgId;
        if ($orgId <= 0) {
            return [];
        }
        $orgs = Db::name('tm_org')->whereNull('deleted_at')->field('id,parent_id')->select();
        $orgById = [];
        foreach ($orgs as $o) {
            $orgById[(int)$o['id']] = $o;
        }
        if (!isset($orgById[$orgId])) {
            return [];
        }
        return array_map('intval', array_keys(self::orgSubtreeIds($orgById, $orgId)));
    }

    /**
     * 左侧组织树（嵌套 children），供班组管理点选。
     *
     * @return array
     */
    public static function nestedOrgTree()
    {
        $rows = Db::name('tm_org')
            ->whereNull('deleted_at')
            ->order('sort_no asc,id asc')
            ->field('id,parent_id,name,status')
            ->select();
        return self::buildNested($rows, 0, 'org');
    }

    /**
     * 左侧组织+班组树：组织下挂班组叶子，供成员管理点选。
     *
     * @return array
     */
    public static function nestedOrgTeamTree()
    {
        $orgs = Db::name('tm_org')
            ->whereNull('deleted_at')
            ->order('sort_no asc,id asc')
            ->field('id,parent_id,name,status')
            ->select();
        $teams = Db::name('tm_team')
            ->whereNull('deleted_at')
            ->order('id asc')
            ->field('id,org_id,name,status')
            ->select();
        $teamsByOrg = [];
        foreach ($teams as $t) {
            $oid = (int)$t['org_id'];
            if (!isset($teamsByOrg[$oid])) {
                $teamsByOrg[$oid] = [];
            }
            $teamsByOrg[$oid][] = [
                'id'        => (int)$t['id'],
                'name'      => $t['name'],
                'node_type' => 'team',
                'status'    => (int)$t['status'],
                'children'  => [],
            ];
        }
        $build = function ($parentId) use (&$build, $orgs, $teamsByOrg) {
            $nodes = [];
            foreach ($orgs as $o) {
                if ((int)$o['parent_id'] !== (int)$parentId) {
                    continue;
                }
                $oid = (int)$o['id'];
                $children = $build($oid);
                if (!empty($teamsByOrg[$oid])) {
                    foreach ($teamsByOrg[$oid] as $tn) {
                        $children[] = $tn;
                    }
                }
                $nodes[] = [
                    'id'        => $oid,
                    'name'      => $o['name'],
                    'node_type' => 'org',
                    'status'    => (int)$o['status'],
                    'children'  => $children,
                ];
            }
            return $nodes;
        };
        return $build(0);
    }

    /**
     * @param array  $rows
     * @param int    $parentId
     * @param string $nodeType
     * @return array
     */
    private static function buildNested(array $rows, $parentId, $nodeType)
    {
        $nodes = [];
        foreach ($rows as $r) {
            if ((int)$r['parent_id'] !== (int)$parentId) {
                continue;
            }
            $id = (int)$r['id'];
            $nodes[] = [
                'id'        => $id,
                'name'      => $r['name'],
                'node_type' => $nodeType,
                'status'    => isset($r['status']) ? (int)$r['status'] : 1,
                'children'  => self::buildNested($rows, $id, $nodeType),
            ];
        }
        return $nodes;
    }
}

<?php
namespace app\index\service;

use think\Db;
use think\Request;

/**
 * Excel 批量导入：上传、任务记录及组织/班组/成员导入。
 */
class ImportService
{
    /**
     * 接收并保存上传的导入文件到 runtime/import 目录。
     *
     * @param string $field 表单文件字段名，默认 'file'
     * @return array [bool ok, string msg|path] 成功时第二项为本地绝对路径
     */
    public static function saveUpload($field = 'file')
    {
        $file = Request::instance()->file($field);
        if (!$file) {
            return [false, '请上传文件'];
        }
        $dir = ROOT_PATH . 'runtime' . DS . 'import';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $info = $file->validate(['size' => 10 * 1024 * 1024, 'ext' => 'xls,xlsx,csv'])
            ->move($dir);
        if (!$info) {
            return [false, $file->getError()];
        }
        return [true, $dir . DS . $info->getSaveName()];
    }

    /**
     * 创建导入任务记录（status=0 进行中）。
     *
     * @param string $type     导入类型（org/team/member 等）
     * @param string $filePath 源文件路径标识
     * @param int    $adminId  操作管理员 ID
     * @return int 新任务 ID
     */
    public static function beginJob($type, $filePath, $adminId)
    {
        return Db::name('tm_import_job')->insertGetId([
            'type'       => $type,
            'file_path'  => $filePath,
            'total_cnt'  => 0,
            'success_cnt'=> 0,
            'fail_cnt'   => 0,
            'error_json' => null,
            'status'     => 0,
            'created_by' => $adminId,
            'created_at' => tmNow(),
        ]);
    }

    /**
     * 完结导入任务并写入统计与错误明细。
     *
     * @param int   $jobId   任务 ID
     * @param int   $total   总行数
     * @param int   $success 成功行数
     * @param array $errors  失败明细列表（每项含 line、msg 等）
     * @return array 含 job_id、total_cnt、success_cnt、fail_cnt、status、errors
     */
    public static function finishJob($jobId, $total, $success, $errors, $meta = null)
    {
        $fail = count($errors);
        $status = 1;
        if ($fail > 0 && $success > 0) {
            $status = 2;
        } elseif ($fail > 0 && $success === 0) {
            $status = 3;
        }
        if (is_array($meta)) {
            $errorJson = json_encode([
                'errors' => $errors ? array_values($errors) : [],
                'meta'   => $meta,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $errorJson = $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null;
        }
        Db::name('tm_import_job')->where('id', $jobId)->update([
            'total_cnt'   => $total,
            'success_cnt' => $success,
            'fail_cnt'    => $fail,
            'error_json'  => $errorJson,
            'status'      => $status,
            'finished_at' => tmNow(),
        ]);
        return [
            'job_id'      => $jobId,
            'total_cnt'   => $total,
            'success_cnt' => $success,
            'fail_cnt'    => $fail,
            'status'      => $status,
            'errors'      => $errors,
            'meta'        => $meta,
        ];
    }

    /**
     * 解析导入任务 error_json（兼容旧版纯错误数组与新版 {errors, meta}）。
     *
     * @param string|null $errorJson
     * @return array [array $errors, array|null $meta]
     */
    public static function parseJobPayload($errorJson)
    {
        if ($errorJson === null || $errorJson === '') {
            return [[], null];
        }
        $decoded = json_decode($errorJson, true);
        if (!is_array($decoded)) {
            return [[], null];
        }
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : null;
            return [$decoded['errors'], $meta];
        }
        return [$decoded, null];
    }

    /**
     * 将 Excel 中的组织类型文本或数字映射为内部 org_type 编码。
     *
     * @param mixed $v 单元格值（如「公司」「2」等）
     * @return int 0 其他、1 公司/集团、2 厂/分厂、3 工段/部门；未识别时返回 0
     */
    public static function mapOrgType($v)
    {
        $v = trim((string)$v);
        if ($v === '') {
            return 0; // 未填类型：不限制层级，标为其他
        }
        $map = [
            '0' => 0, '其他' => 0,
            '1' => 1, '公司' => 1, '集团' => 1, '公司/集团' => 1,
            '2' => 2, '厂' => 2, '分厂' => 2, '厂/分厂' => 2,
            '3' => 3, '工段' => 3, '部门' => 3, '工段/部门' => 3,
        ];
        if (isset($map[$v])) {
            return $map[$v];
        }
        $n = (int)$v;
        return in_array($n, [0, 1, 2, 3], true) ? $n : 0;
    }

    /**
     * 将 Excel 中的状态文本映射为 0/1 启用标志。
     *
     * @param mixed $v       单元格值
     * @param int   $default 空值时的默认值，默认 1（启用）
     * @return int 0 停用，1 启用
     */
    public static function mapStatus($v, $default = 1)
    {
        $v = trim((string)$v);
        if ($v === '') {
            return $default;
        }
        if (in_array($v, ['0', '停用', '禁用', '否'], true)) {
            return 0;
        }
        return 1;
    }

    /**
     * 将 Excel 中的角色文本映射为内部 role_code。
     *
     * @param mixed $v 单元格值（如「班组长」「member」等）
     * @return string leader/deputy/safety/part_safety/member；未识别时返回 member
     */
    public static function mapRole($v)
    {
        $v = trim((string)$v);
        $map = [
            'leader' => 'leader', '班组长' => 'leader',
            'deputy' => 'deputy', '组长' => 'deputy',
            'safety' => 'safety', '安全员' => 'safety',
            'part_safety' => 'part_safety', '兼职安全员' => 'part_safety',
            'member' => 'member', '普通成员' => 'member', '成员' => 'member',
        ];
        return isset($map[$v]) ? $map[$v] : 'member';
    }

    /**
     * 批量导入组织：按「组织名称 + 上级组织（名称）」多轮解析；同名同上级则更新，否则新增。
     *
     * @param array  $rows     关联数组行（组织名称、上级组织必填逻辑；组织类型/排序可选；状态固定启用）
     * @param int    $adminId  操作管理员 ID
     * @param string $filePath 上传文件本地路径，写入导入任务 file_path
     * @return array finishJob 返回的任务结果摘要
     */
    public static function importOrgs($rows, $adminId, $filePath = 'upload')
    {
        $jobId = self::beginJob('org', $filePath ?: 'upload', $adminId);
        $errors = [];
        $success = 0;
        $insertedIds = [];
        $updatedBefore = []; // id => 导入前快照

        // name => id（用于解析上级；同名取 id 较大者）
        // parentId|name => id（用于同级去重更新）
        $nameMap = [];
        $existKey = [];
        $existById = [];
        $exist = Db::name('tm_org')->whereNull('deleted_at')->order('id asc')->select();
        foreach ($exist as $e) {
            $nameMap[$e['name']] = (int)$e['id'];
            $existKey[(int)$e['parent_id'] . '|' . $e['name']] = (int)$e['id'];
            $existById[(int)$e['id']] = $e;
        }

        // 多轮解析，保证上级先入库
        $pending = $rows;
        $guard = 0;
        while (!empty($pending) && $guard < 20) {
            $guard++;
            $next = [];
            foreach ($pending as $idx => $row) {
                $lineNo = $idx + 2;
                $name = isset($row['组织名称']) ? trim($row['组织名称']) : '';
                $parentName = '';
                if (isset($row['上级组织'])) {
                    $parentName = trim($row['上级组织']);
                } elseif (isset($row['上级组织名称'])) {
                    $parentName = trim($row['上级组织名称']);
                }
                if ($name === '') {
                    $errors[] = ['line' => $lineNo, 'msg' => '组织名称不能为空'];
                    continue;
                }

                $parentId = 0;
                if ($parentName !== '') {
                    if (!isset($nameMap[$parentName])) {
                        // 上级尚未处理或不存在，下一轮再试
                        $next[$idx] = $row;
                        continue;
                    }
                    $parentId = $nameMap[$parentName];
                }

                // 组织类型可要可不要：有则用，无则按是否有上级给默认（顶级=公司/集团，下级=工段/部门）
                $orgTypeRaw = isset($row['组织类型']) ? trim((string)$row['组织类型']) : '';
                if ($orgTypeRaw === '') {
                    $orgType = ($parentName === '') ? 1 : 3;
                } else {
                    $orgType = self::mapOrgType($orgTypeRaw);
                    if (!in_array($orgType, [0, 1, 2, 3], true)) {
                        $orgType = ($parentName === '') ? 1 : 3;
                    }
                }
                $sortNo = isset($row['排序']) && $row['排序'] !== '' ? (int)$row['排序'] : 0;
                // 导入一律启用
                $status = 1;

                $key = $parentId . '|' . $name;
                if (isset($existKey[$key])) {
                    $id = $existKey[$key];
                    if (!isset($updatedBefore[$id]) && isset($existById[$id])) {
                        $old = $existById[$id];
                        $updatedBefore[$id] = [
                            'parent_id' => (int)$old['parent_id'],
                            'org_type'  => (int)$old['org_type'],
                            'sort_no'   => (int)$old['sort_no'],
                            'status'    => (int)$old['status'],
                        ];
                    }
                    Db::name('tm_org')->where('id', $id)->update([
                        'parent_id'  => $parentId,
                        'org_type'   => $orgType,
                        'sort_no'    => $sortNo,
                        'status'     => $status,
                        'updated_at' => tmNow(),
                    ]);
                } else {
                    $id = (int)Db::name('tm_org')->insertGetId([
                        'parent_id'  => $parentId,
                        'name'       => $name,
                        'org_type'   => $orgType,
                        'sort_no'    => $sortNo,
                        'status'     => $status,
                        'created_at' => tmNow(),
                    ]);
                    $existKey[$key] = $id;
                    $insertedIds[] = $id;
                }
                $nameMap[$name] = $id;
                $success++;
            }
            if (count($next) === count($pending)) {
                foreach ($next as $idx => $row) {
                    $p = isset($row['上级组织']) ? trim($row['上级组织']) : '';
                    if ($p === '' && isset($row['上级组织名称'])) {
                        $p = trim($row['上级组织名称']);
                    }
                    $errors[] = ['line' => $idx + 2, 'msg' => '找不到上级组织: ' . $p . '（请确认名称与表格中「组织名称」一致，且上级行写在前面）'];
                }
                break;
            }
            $pending = $next;
        }

        $meta = [
            'can_undo'      => ($success > 0),
            'undone_at'     => null,
            'inserted_ids'  => $insertedIds,
            'updated'       => $updatedBefore,
        ];
        return self::finishJob($jobId, count($rows), $success, $errors, $meta);
    }

    /**
     * 撤回最近一次组织导入。
     *
     * @return array [bool ok, string msg, array|null data]
     */
    public static function undoLastOrgImport()
    {
        return self::undoLastImport('org');
    }

    /**
     * 撤回最近一次班组导入。
     *
     * @return array [bool ok, string msg, array|null data]
     */
    public static function undoLastTeamImport()
    {
        return self::undoLastImport('team');
    }

    /**
     * 撤回最近一次成员导入。
     *
     * @return array [bool ok, string msg, array|null data]
     */
    public static function undoLastMemberImport()
    {
        return self::undoLastImport('member');
    }

    /**
     * 撤回最近一次指定类型导入：删除本次新增、还原本次更新。
     *
     * @param string $type org|team|member
     * @return array [bool ok, string msg, array|null data]
     */
    public static function undoLastImport($type)
    {
        $labels = ['org' => '组织', 'team' => '班组', 'member' => '成员'];
        if (!isset($labels[$type])) {
            return [false, '不支持的导入类型', null];
        }
        $label = $labels[$type];

        $job = Db::name('tm_import_job')
            ->where('type', $type)
            ->where('success_cnt', '>', 0)
            ->whereIn('status', [1, 2])
            ->order('id desc')
            ->find();
        if (!$job) {
            return [false, '没有可撤回的' . $label . '导入记录', null];
        }

        list($errors, $meta) = self::parseJobPayload(isset($job['error_json']) ? $job['error_json'] : null);
        if (!$meta || empty($meta['can_undo'])) {
            return [false, '该次导入不支持撤回（可能是旧数据或已撤回）', null];
        }
        if (!empty($meta['undone_at'])) {
            return [false, '最近一次' . $label . '导入已经撤回过了', null];
        }

        $insertedIds = isset($meta['inserted_ids']) && is_array($meta['inserted_ids'])
            ? array_map('intval', $meta['inserted_ids']) : [];
        $updated = isset($meta['updated']) && is_array($meta['updated']) ? $meta['updated'] : [];

        Db::startTrans();
        try {
            if ($type === 'org') {
                foreach ($updated as $id => $before) {
                    $id = (int)$id;
                    if ($id <= 0 || !is_array($before)) {
                        continue;
                    }
                    Db::name('tm_org')->where('id', $id)->whereNull('deleted_at')->update([
                        'parent_id'  => isset($before['parent_id']) ? (int)$before['parent_id'] : 0,
                        'org_type'   => isset($before['org_type']) ? (int)$before['org_type'] : 1,
                        'sort_no'    => isset($before['sort_no']) ? (int)$before['sort_no'] : 0,
                        'status'     => isset($before['status']) ? (int)$before['status'] : 1,
                        'updated_at' => tmNow(),
                    ]);
                }
                rsort($insertedIds);
                $deleted = 0;
                foreach ($insertedIds as $id) {
                    $id = (int)$id;
                    if ($id <= 0) {
                        continue;
                    }
                    $row = Db::name('tm_org')->where('id', $id)->whereNull('deleted_at')->find();
                    if (!$row) {
                        continue;
                    }
                    $child = Db::name('tm_org')->where('parent_id', $id)->whereNull('deleted_at')->count();
                    if ($child > 0) {
                        throw new \RuntimeException('组织「' . $row['name'] . '」下仍有下级，无法撤回');
                    }
                    $team = Db::name('tm_team')->where('org_id', $id)->whereNull('deleted_at')->count();
                    if ($team > 0) {
                        throw new \RuntimeException('组织「' . $row['name'] . '」下已有班组，无法撤回');
                    }
                    Db::name('tm_org')->where('id', $id)->update(['deleted_at' => tmNow()]);
                    $deleted++;
                }
            } elseif ($type === 'team') {
                foreach ($updated as $id => $before) {
                    $id = (int)$id;
                    if ($id <= 0 || !is_array($before)) {
                        continue;
                    }
                    Db::name('tm_team')->where('id', $id)->whereNull('deleted_at')->update([
                        'org_id'     => isset($before['org_id']) ? (int)$before['org_id'] : 0,
                        'status'     => isset($before['status']) ? (int)$before['status'] : 1,
                        'updated_at' => tmNow(),
                    ]);
                }
                $deleted = 0;
                foreach ($insertedIds as $id) {
                    $id = (int)$id;
                    if ($id <= 0) {
                        continue;
                    }
                    $row = Db::name('tm_team')->where('id', $id)->whereNull('deleted_at')->find();
                    if (!$row) {
                        continue;
                    }
                    $member = Db::name('tm_member')->where('team_id', $id)->whereNull('deleted_at')->count();
                    if ($member > 0) {
                        throw new \RuntimeException('班组「' . $row['name'] . '」下已有成员，无法撤回');
                    }
                    Db::name('tm_team')->where('id', $id)->update(['deleted_at' => tmNow()]);
                    $deleted++;
                }
            } else { // member
                foreach ($updated as $id => $before) {
                    $id = (int)$id;
                    if ($id <= 0 || !is_array($before)) {
                        continue;
                    }
                    Db::name('tm_member')->where('id', $id)->whereNull('deleted_at')->update([
                        'team_id'    => isset($before['team_id']) ? (int)$before['team_id'] : 0,
                        'name'       => isset($before['name']) ? $before['name'] : '',
                        'mobile'     => isset($before['mobile']) ? $before['mobile'] : '',
                        'post_name'  => isset($before['post_name']) ? $before['post_name'] : '',
                        'role_code'  => isset($before['role_code']) ? $before['role_code'] : 'member',
                        'status'     => isset($before['status']) ? (int)$before['status'] : 1,
                        'updated_at' => tmNow(),
                    ]);
                }
                $deleted = 0;
                foreach ($insertedIds as $id) {
                    $id = (int)$id;
                    if ($id <= 0) {
                        continue;
                    }
                    $row = Db::name('tm_member')->where('id', $id)->whereNull('deleted_at')->find();
                    if (!$row) {
                        continue;
                    }
                    $paper = Db::name('tm_paper')->where('member_id', $id)->count();
                    if ($paper > 0) {
                        throw new \RuntimeException('成员「' . $row['name'] . '」已有答卷，无法撤回');
                    }
                    Db::name('tm_member')->where('id', $id)->update(['deleted_at' => tmNow()]);
                    $deleted++;
                }
            }

            $meta['can_undo'] = false;
            $meta['undone_at'] = tmNow();
            $meta['undo_deleted'] = $deleted;
            $meta['undo_restored'] = count($updated);
            Db::name('tm_import_job')->where('id', (int)$job['id'])->update([
                'error_json' => json_encode([
                    'errors' => $errors,
                    'meta'   => $meta,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            Db::commit();
            return [true, '已撤回上次' . $label . '导入：还原 ' . count($updated) . ' 条，删除新增 ' . $deleted . ' 条', [
                'job_id'   => (int)$job['id'],
                'type'     => $type,
                'restored' => count($updated),
                'deleted'  => $deleted,
            ]];
        } catch (\Exception $e) {
            Db::rollback();
            return [false, $e->getMessage(), null];
        }
    }

    /**
     * 批量导入班组：按班组名称 + 所属组织名称匹配，存在则更新状态。
     *
     * @param array  $rows     关联数组行（班组名称、所属组织等列）
     * @param int    $adminId  操作管理员 ID
     * @param string $filePath 上传文件本地路径，写入导入任务 file_path
     * @return array finishJob 返回的任务结果摘要
     */
    public static function importTeams($rows, $adminId, $filePath = 'upload')
    {
        $jobId = self::beginJob('team', $filePath ?: 'upload', $adminId);
        $errors = [];
        $success = 0;
        $insertedIds = [];
        $updatedBefore = [];

        $orgs = Db::name('tm_org')->whereNull('deleted_at')->select();
        $orgByName = [];
        foreach ($orgs as $o) {
            $orgByName[$o['name']] = $o['id'];
        }

        foreach ($rows as $idx => $row) {
            $lineNo = $idx + 2;
            $name = isset($row['班组名称']) ? trim($row['班组名称']) : '';
            $orgName = isset($row['所属组织']) ? trim($row['所属组织']) : '';
            if ($orgName === '' && isset($row['所属组织名称'])) {
                $orgName = trim($row['所属组织名称']);
            }
            if ($name === '' || $orgName === '') {
                $errors[] = ['line' => $lineNo, 'msg' => '班组名称、所属组织不能为空'];
                continue;
            }
            if (!isset($orgByName[$orgName])) {
                $errors[] = ['line' => $lineNo, 'msg' => '所属组织不存在: ' . $orgName];
                continue;
            }
            $status = self::mapStatus(isset($row['状态']) ? $row['状态'] : 1);
            $orgId = (int)$orgByName[$orgName];
            $exist = Db::name('tm_team')
                ->where('name', $name)
                ->where('org_id', $orgId)
                ->whereNull('deleted_at')
                ->find();
            if ($exist) {
                $id = (int)$exist['id'];
                if (!isset($updatedBefore[$id])) {
                    $updatedBefore[$id] = [
                        'org_id' => (int)$exist['org_id'],
                        'status' => (int)$exist['status'],
                    ];
                }
                Db::name('tm_team')->where('id', $id)->update([
                    'status'     => $status,
                    'updated_at' => tmNow(),
                ]);
            } else {
                $id = (int)Db::name('tm_team')->insertGetId([
                    'org_id'     => $orgId,
                    'name'       => $name,
                    'status'     => $status,
                    'created_at' => tmNow(),
                ]);
                $insertedIds[] = $id;
            }
            $success++;
        }
        $meta = [
            'can_undo'     => ($success > 0),
            'undone_at'    => null,
            'inserted_ids' => $insertedIds,
            'updated'      => $updatedBefore,
        ];
        return self::finishJob($jobId, count($rows), $success, $errors, $meta);
    }

    /**
     * 批量导入成员：按手机号唯一，校验格式与班组存在性。
     *
     * @param array  $rows     关联数组行（姓名、手机号、所属班组、角色等列）
     * @param int    $adminId  操作管理员 ID
     * @param string $filePath 上传文件本地路径，写入导入任务 file_path
     * @return array finishJob 返回的任务结果摘要
     */
    public static function importMembers($rows, $adminId, $filePath = 'upload')
    {
        $jobId = self::beginJob('member', $filePath ?: 'upload', $adminId);
        $errors = [];
        $success = 0;
        $insertedIds = [];
        $updatedBefore = [];

        $teams = Db::name('tm_team')->whereNull('deleted_at')->select();
        $teamByName = [];
        foreach ($teams as $t) {
            $teamByName[$t['name']] = $t['id'];
        }

        foreach ($rows as $idx => $row) {
            $lineNo = $idx + 2;
            $name = isset($row['姓名']) ? trim($row['姓名']) : '';
            $mobile = isset($row['手机号']) ? trim($row['手机号']) : '';
            $teamName = isset($row['所属班组']) ? trim($row['所属班组']) : '';
            if ($teamName === '' && isset($row['班组名称'])) {
                $teamName = trim($row['班组名称']);
            }
            $post = isset($row['岗位']) ? trim($row['岗位']) : '';
            $role = self::mapRole(isset($row['角色']) ? $row['角色'] : 'member');
            $status = self::mapStatus(isset($row['状态']) ? $row['状态'] : 1);

            if ($name === '' || $mobile === '' || $teamName === '') {
                $errors[] = ['line' => $lineNo, 'msg' => '姓名、手机号、所属班组不能为空'];
                continue;
            }
            if (!preg_match('/^1\d{10}$/', $mobile)) {
                $errors[] = ['line' => $lineNo, 'msg' => '手机号格式不正确: ' . $mobile];
                continue;
            }
            if (!isset($teamByName[$teamName])) {
                $errors[] = ['line' => $lineNo, 'msg' => '所属班组不存在: ' . $teamName];
                continue;
            }

            $exist = Db::name('tm_member')->where('mobile', $mobile)->whereNull('deleted_at')->find();
            $data = [
                'team_id'   => $teamByName[$teamName],
                'name'      => $name,
                'mobile'    => $mobile,
                'post_name' => $post,
                'role_code' => $role,
                'status'    => $status,
            ];
            if ($exist) {
                $id = (int)$exist['id'];
                if (!isset($updatedBefore[$id])) {
                    $updatedBefore[$id] = [
                        'team_id'   => (int)$exist['team_id'],
                        'name'      => $exist['name'],
                        'mobile'    => $exist['mobile'],
                        'post_name' => isset($exist['post_name']) ? $exist['post_name'] : '',
                        'role_code' => $exist['role_code'],
                        'status'    => (int)$exist['status'],
                    ];
                }
                $data['updated_at'] = tmNow();
                Db::name('tm_member')->where('id', $id)->update($data);
            } else {
                $data['created_at'] = tmNow();
                $id = (int)Db::name('tm_member')->insertGetId($data);
                $insertedIds[] = $id;
            }
            $success++;
        }
        $meta = [
            'can_undo'     => ($success > 0),
            'undone_at'    => null,
            'inserted_ids' => $insertedIds,
            'updated'      => $updatedBefore,
        ];
        return self::finishJob($jobId, count($rows), $success, $errors, $meta);
    }
}

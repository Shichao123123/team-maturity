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
    public static function finishJob($jobId, $total, $success, $errors)
    {
        $fail = count($errors);
        $status = 1;
        if ($fail > 0 && $success > 0) {
            $status = 2;
        } elseif ($fail > 0 && $success === 0) {
            $status = 3;
        }
        Db::name('tm_import_job')->where('id', $jobId)->update([
            'total_cnt'   => $total,
            'success_cnt' => $success,
            'fail_cnt'    => $fail,
            'error_json'  => $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
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
        ];
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
     * 批量导入组织：按编码与上级关系多轮解析，同名更新、否则新增。
     *
     * @param array  $rows     关联数组行（组织编码、组织名称、上级组织编码等列）
     * @param int    $adminId  操作管理员 ID
     * @param string $filePath 上传文件本地路径，写入导入任务 file_path
     * @return array finishJob 返回的任务结果摘要
     */
    public static function importOrgs($rows, $adminId, $filePath = 'upload')
    {
        $jobId = self::beginJob('org', $filePath ?: 'upload', $adminId);
        $errors = [];
        $success = 0;
        $codeMap = []; // code => id

        // 已有组织按名称缓存（同名取最新）
        $exist = Db::name('tm_org')->whereNull('deleted_at')->order('id asc')->select();
        $nameMap = [];
        foreach ($exist as $e) {
            $nameMap[$e['name']] = $e['id'];
        }

        // 多轮解析，保证上级先入库
        $pending = $rows;
        $guard = 0;
        while (!empty($pending) && $guard < 20) {
            $guard++;
            $next = [];
            foreach ($pending as $idx => $row) {
                $lineNo = $idx + 2;
                $code = isset($row['组织编码']) ? trim($row['组织编码']) : '';
                $name = isset($row['组织名称']) ? trim($row['组织名称']) : '';
                $parentCode = isset($row['上级组织编码']) ? trim($row['上级组织编码']) : '';
                if ($code === '' || $name === '') {
                    $errors[] = ['line' => $lineNo, 'msg' => '组织编码、组织名称不能为空'];
                    continue;
                }
                if (isset($codeMap[$code])) {
                    $errors[] = ['line' => $lineNo, 'msg' => '组织编码重复: ' . $code];
                    continue;
                }

                $parentId = 0;
                if ($parentCode !== '') {
                    if (!isset($codeMap[$parentCode])) {
                        // 上级尚未处理，下一轮
                        $next[$idx] = $row;
                        continue;
                    }
                    $parentId = $codeMap[$parentCode];
                }

                // 类型仅标识，不校验必须三层；空则按是否有上级给默认标签
                $orgTypeRaw = isset($row['组织类型']) ? $row['组织类型'] : '';
                if (trim((string)$orgTypeRaw) === '') {
                    $orgType = ($parentCode === '') ? 1 : 3;
                } else {
                    $orgType = self::mapOrgType($orgTypeRaw);
                }
                if (!in_array($orgType, [0, 1, 2, 3], true)) {
                    $errors[] = ['line' => $lineNo, 'msg' => '组织类型无效'];
                    continue;
                }
                $sortNo = isset($row['排序']) && $row['排序'] !== '' ? (int)$row['排序'] : 0;
                $status = self::mapStatus(isset($row['状态']) ? $row['状态'] : 1);

                // 同名则更新，否则新增
                if (isset($nameMap[$name])) {
                    $id = $nameMap[$name];
                    Db::name('tm_org')->where('id', $id)->update([
                        'parent_id'  => $parentId,
                        'org_type'   => $orgType,
                        'sort_no'    => $sortNo,
                        'status'     => $status,
                        'updated_at' => tmNow(),
                    ]);
                } else {
                    $id = Db::name('tm_org')->insertGetId([
                        'parent_id'  => $parentId,
                        'name'       => $name,
                        'org_type'   => $orgType,
                        'sort_no'    => $sortNo,
                        'status'     => $status,
                        'created_at' => tmNow(),
                    ]);
                    $nameMap[$name] = $id;
                }
                $codeMap[$code] = $id;
                $success++;
            }
            if (count($next) === count($pending)) {
                foreach ($next as $idx => $row) {
                    $errors[] = ['line' => $idx + 2, 'msg' => '找不到上级组织编码: ' . (isset($row['上级组织编码']) ? $row['上级组织编码'] : '')];
                }
                break;
            }
            $pending = $next;
        }

        return self::finishJob($jobId, count($rows), $success, $errors);
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
            $exist = Db::name('tm_team')
                ->where('name', $name)
                ->where('org_id', $orgByName[$orgName])
                ->whereNull('deleted_at')
                ->find();
            if ($exist) {
                Db::name('tm_team')->where('id', $exist['id'])->update([
                    'status'     => $status,
                    'updated_at' => tmNow(),
                ]);
            } else {
                Db::name('tm_team')->insert([
                    'org_id'     => $orgByName[$orgName],
                    'name'       => $name,
                    'status'     => $status,
                    'created_at' => tmNow(),
                ]);
            }
            $success++;
        }
        return self::finishJob($jobId, count($rows), $success, $errors);
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
                $data['updated_at'] = tmNow();
                Db::name('tm_member')->where('id', $exist['id'])->update($data);
            } else {
                $data['created_at'] = tmNow();
                Db::name('tm_member')->insert($data);
            }
            $success++;
        }
        return self::finishJob($jobId, count($rows), $success, $errors);
    }
}

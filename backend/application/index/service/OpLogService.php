<?php
namespace app\index\service;

use think\Db;
use think\Request;
use think\Session;

/**
 * 管理端操作日志写入与中文展示。
 */
class OpLogService
{
    /** 模块英文标识 => 中文名（与 OpLogService::write 写入的 module 对齐） */
    private static $moduleLabels = [
        'login'             => '登录认证',
        'admin'             => '管理员',
        'role'              => '角色权限',
        'import'            => '数据导入',
        'batch'             => '测评批次',
        'indicator_version' => '指标版本',
        'indicator'         => '指标树',
        'question'          => '题库题目',
        'option'            => '题目选项',
        'mock'              => '模拟测评',
    ];

    /** 动作英文标识 => 中文名（通用） */
    private static $actionLabels = [
        'login'          => '登录',
        'logout'         => '退出',
        'add'            => '新增',
        'edit'           => '编辑',
        'delete'         => '删除',
        'save'           => '保存',
        'publish'        => '发布',
        'disable'        => '停用',
        'copy'           => '复制',
        'reset_password' => '重置密码',
        'save_powers'    => '分配权限',
        'member'         => '导入成员',
        'team'           => '导入班组',
        'org'            => '导入组织',
        'bindTeams'      => '绑定班组',
        'start'          => '启动',
        'finish'         => '结束',
        'score'          => '算分',
        'weight'         => '调整权重',
        'submit'         => '提交',
    ];

    /** 明细字段英文键 => 中文标签 */
    private static $fieldLabels = [
        'username'       => '账号',
        'name'           => '名称',
        'from'           => '源版本',
        'via'            => '来源',
        'version_id'     => '所属版本',
        'score_ratio'    => '选项权重',
        'power_cnt'      => '权限数',
        'team_ids'       => '班组',
        'batch_id'       => '批次',
        'question_cnt'   => '题目数',
        'pick_mode'      => '抽题策略',
        'total_score'    => '得分',
        'level'          => '等级',
        'answered'       => '已答数',
        'job_id'         => '导入任务',
        'success_cnt'    => '成功数',
        'fail_cnt'       => '失败数',
        'total_cnt'      => '总行数',
        'file_path'      => '文件',
        'file_name'      => '文件名',
        'teams'          => '班组结果数',
        'snapshot_cnt'   => '快照题数',
        'indicator_cnt'  => '指标数',
    ];

    private static $pickModeLabels = [
        'random'     => '随机抽一',
        'first'      => '取首题',
        'sequential' => '轮询抽一',
    ];

    private static $levelLabels = [
        'essential'   => '本质安全型',
        'independent' => '自立型',
        'dependent'   => '依赖型',
        'passive'     => '被动型',
    ];

    /**
     * 写入一条操作日志到 tm_op_log。
     *
     * @param string      $module  业务模块标识
     * @param string      $action  操作动作标识
     * @param mixed       $bizId   关联业务 ID（可为 null）
     * @param mixed       $detail  详情；数组/对象会自动 JSON 编码
     * @param int|null    $adminId 管理员 ID；null 时从 Session tm_user 读取
     * @return int 新插入日志记录的 ID
     */
    public static function write($module, $action, $bizId = null, $detail = null, $adminId = null)
    {
        if ($adminId === null) {
            $user = Session::get('tm_user');
            $adminId = $user && isset($user['id']) ? (int)$user['id'] : null;
        }
        if (is_array($detail) || is_object($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        $ip = '';
        try {
            $ip = Request::instance()->ip();
        } catch (\Exception $e) {
            $ip = '';
        }
        return Db::name('tm_op_log')->insertGetId([
            'admin_id'   => $adminId,
            'module'     => (string)$module,
            'action'     => (string)$action,
            'biz_id'     => $bizId === null || $bizId === '' ? null : (string)$bizId,
            'detail'     => $detail,
            'ip'         => $ip,
            'created_at' => tmNow(),
        ]);
    }

    /**
     * 模块筛选项（value=英文码，label=中文）。
     * 固定字典 + 库中已出现过的 module，避免历史数据筛不到。
     *
     * @return array
     */
    public static function moduleOptions()
    {
        $labels = self::$moduleLabels;
        try {
            $dbModules = Db::name('tm_op_log')->distinct(true)->column('module');
            if (is_array($dbModules)) {
                foreach ($dbModules as $code) {
                    $code = trim((string)$code);
                    if ($code === '' || isset($labels[$code])) {
                        continue;
                    }
                    $labels[$code] = $code;
                }
            }
        } catch (\Exception $e) {
            // 忽略，仅返回字典
        }

        $opts = [];
        foreach ($labels as $code => $name) {
            $opts[] = ['value' => $code, 'label' => $name];
        }
        return $opts;
    }

    /**
     * 模块中文名。
     *
     * @param string $module
     * @return string
     */
    public static function moduleLabel($module)
    {
        $module = (string)$module;
        return isset(self::$moduleLabels[$module]) ? self::$moduleLabels[$module] : $module;
    }

    /**
     * 动作中文名。
     *
     * @param string $module
     * @param string $action
     * @return string
     */
    public static function actionLabel($module, $action)
    {
        $action = (string)$action;
        $module = (string)$module;
        // 导入模块动作即导入类型
        if ($module === 'import' && isset(self::$actionLabels[$action])) {
            return self::$actionLabels[$action];
        }
        return isset(self::$actionLabels[$action]) ? self::$actionLabels[$action] : $action;
    }

    /**
     * 批量装饰日志列表：模块/动作/业务名称/明细均为中文。
     *
     * @param array $list
     * @return array
     */
    public static function decorateList(array $list)
    {
        $nameMap = self::prefetchBizNames($list);
        foreach ($list as &$row) {
            $row = self::decorateRow($row, $nameMap);
        }
        unset($row);
        return $list;
    }

    /**
     * 将日志行装饰为中文展示字段（不改库内原始值）。
     *
     * @param array $row
     * @param array $nameMap prefetchBizNames 结果
     * @return array
     */
    public static function decorateRow(array $row, array $nameMap = [])
    {
        $module = isset($row['module']) ? $row['module'] : '';
        $action = isset($row['action']) ? $row['action'] : '';
        $bizId = isset($row['biz_id']) ? $row['biz_id'] : null;
        $data = self::parseDetail(isset($row['detail']) ? $row['detail'] : null);
        if (empty($nameMap)) {
            $nameMap = self::prefetchBizNames([$row]);
        }

        $bizName = self::resolveBizName($module, $action, $bizId, $data, $nameMap);
        $row['module_text'] = self::moduleLabel($module);
        $row['action_text'] = self::actionLabel($module, $action);
        $row['biz_name'] = $bizName !== '' ? $bizName : '—';
        $row['detail_text'] = self::formatDetail($module, $action, $bizName, $data, $nameMap);
        return $row;
    }

    /**
     * 生成中文明细描述（不展示原始业务 ID）。
     *
     * @param string $module
     * @param string $action
     * @param string $bizName 业务中文名
     * @param array  $data    已解析 detail
     * @param array  $nameMap 名称缓存
     * @return string
     */
    public static function formatDetail($module, $action, $bizName = '', $data = [], $nameMap = [])
    {
        if (!is_array($data)) {
            $data = self::parseDetail($data);
        }
        $biz = $bizName !== '' ? $bizName : '';
        $quote = function ($name) {
            return $name !== '' ? '「' . $name . '」' : '';
        };
        $verName = self::mapName($nameMap, 'version', self::val($data, 'version_id'));
        $fromName = self::mapName($nameMap, 'version', self::val($data, 'from'));

        switch ($module . '/' . $action) {
            case 'login/login':
                return '账号' . $quote(self::val($data, 'username', $biz)) . '登录系统';
            case 'login/logout':
                return '账号' . $quote(self::val($data, 'username', $biz)) . '退出系统';

            case 'admin/add':
                return '新增管理员' . $quote(self::val($data, 'username', $biz));
            case 'admin/edit':
                return '编辑管理员' . $quote(self::val($data, 'username', $biz));
            case 'admin/delete':
                return '删除管理员' . $quote(self::val($data, 'username', $biz));
            case 'admin/reset_password':
                return '重置管理员' . $quote(self::val($data, 'username', $biz)) . '的密码';

            case 'role/add':
                return '新增角色' . $quote(self::val($data, 'name', $biz));
            case 'role/edit':
                return '编辑角色' . $quote(self::val($data, 'name', $biz));
            case 'role/delete':
                return '删除角色' . $quote($biz);
            case 'role/save_powers':
                return '为角色' . $quote($biz) . '分配菜单权限，共 '
                    . self::val($data, 'power_cnt', '0') . ' 项';

            case 'import/member':
                return self::formatImportDetail('成员', $data, $biz);
            case 'import/team':
                return self::formatImportDetail('班组', $data, $biz);
            case 'import/org':
                return self::formatImportDetail('组织', $data, $biz);

            case 'batch/add':
                return '创建测评批次' . $quote(self::val($data, 'name', $biz));
            case 'batch/edit':
                return '编辑测评批次' . $quote(self::val($data, 'name', $biz));
            case 'batch/bindTeams':
                $teamNames = self::resolveTeamNames($data, $nameMap);
                $msg = '为批次' . $quote($biz) . '绑定班组 ' . count($teamNames) . ' 个';
                if ($teamNames) {
                    $msg .= '：' . implode('、', $teamNames);
                }
                return $msg;
            case 'batch/start':
                $qc = self::val($data, 'question_cnt', self::val($data, 'snapshot_cnt', ''));
                return '启动批次' . $quote($biz)
                    . ($qc !== '' ? '，生成试卷题目 ' . $qc . ' 道' : '');
            case 'batch/finish':
                return self::formatBatchFinishDetail($data, $biz);
            case 'batch/score':
                return self::formatBatchScoreDetail($data, $biz);
            case 'batch/delete':
                return '删除测评批次' . $quote($biz);

            case 'indicator_version/add':
                return '新增指标版本' . $quote(self::val($data, 'name', $biz));
            case 'indicator_version/edit':
                return '编辑指标版本' . $quote($biz);
            case 'indicator_version/publish':
                return '发布指标版本' . $quote($biz);
            case 'indicator_version/disable':
                return '停用指标版本' . $quote($biz);
            case 'indicator_version/copy':
                return '从版本' . $quote($fromName !== '' ? $fromName : '源版本')
                    . '复制为新草稿' . $quote($biz);
            case 'indicator_version/delete':
                return '删除指标版本' . $quote($biz);

            case 'indicator/save':
                return '保存指标' . $quote($biz)
                    . ($verName !== '' ? '（所属版本' . $quote($verName) . '）' : '');
            case 'indicator/delete':
                return '删除指标' . $quote($biz)
                    . ($verName !== '' ? '（所属版本' . $quote($verName) . '）' : '');

            case 'question/save':
                return '保存题目' . $quote($biz)
                    . ($verName !== '' ? '（所属版本' . $quote($verName) . '）' : '');
            case 'question/delete':
                return '删除题目' . $quote($biz)
                    . ($verName !== '' ? '（所属版本' . $quote($verName) . '）' : '');

            case 'option/weight':
                return '调整选项' . $quote($biz) . '权重为 ' . self::val($data, 'score_ratio')
                    . ($verName !== '' ? '（所属版本' . $quote($verName) . '）' : '');

            case 'mock/start':
                return '开始模拟测评' . $quote($biz !== '' ? $biz : $verName)
                    . '，抽题 ' . self::val($data, 'question_cnt', '0') . ' 道，策略：'
                    . self::pickModeLabel(self::val($data, 'pick_mode'));
            case 'mock/submit':
                return '提交模拟测评' . $quote($biz !== '' ? $biz : $verName)
                    . '，得分 ' . self::val($data, 'total_score', '-')
                    . '，等级：' . self::levelLabel(self::val($data, 'level'))
                    . '，已答 ' . self::val($data, 'answered', '0') . ' 题';
        }

        $parts = [];
        if ($biz !== '') {
            $parts[] = '业务：' . $biz;
        }
        foreach ($data as $k => $v) {
            if ($v === null || $v === '' || in_array($k, ['from', 'version_id', 'via'], true)) {
                continue;
            }
            if (is_array($v)) {
                if ($k === 'team_ids') {
                    $names = self::resolveTeamNames($data, $nameMap);
                    if ($names) {
                        $parts[] = '班组：' . implode('、', $names);
                    }
                    continue;
                }
                if ($k === 'teams') {
                    $parts[] = '班组结果数：' . count($v);
                    continue;
                }
                continue;
            }
            if ($k === 'pick_mode') {
                $v = self::pickModeLabel((string)$v);
            } elseif ($k === 'level') {
                $v = self::levelLabel((string)$v);
            } elseif ($k === 'file_path') {
                $v = basename((string)$v);
                $k = 'file_name';
            }
            $parts[] = self::fieldLabel((string)$k) . '：' . $v;
        }
        return $parts ? implode('；', $parts) : '—';
    }

    /**
     * 预取本页日志涉及的业务名称。
     *
     * @param array $list
     * @return array
     */
    private static function prefetchBizNames(array $list)
    {
        $buckets = [
            'admin'     => [],
            'role'      => [],
            'batch'     => [],
            'version'   => [],
            'indicator' => [],
            'question'  => [],
            'option'    => [],
            'team'      => [],
            'import'    => [],
        ];
        foreach ($list as $row) {
            $module = isset($row['module']) ? (string)$row['module'] : '';
            $bizId = isset($row['biz_id']) ? trim((string)$row['biz_id']) : '';
            $data = self::parseDetail(isset($row['detail']) ? $row['detail'] : null);
            $key = self::bizBucket($module);
            if ($key && $bizId !== '' && ctype_digit($bizId)) {
                $buckets[$key][] = (int)$bizId;
            }
            if (!empty($data['from']) && ctype_digit((string)$data['from'])) {
                $buckets['version'][] = (int)$data['from'];
            }
            if (!empty($data['version_id']) && ctype_digit((string)$data['version_id'])) {
                $buckets['version'][] = (int)$data['version_id'];
            }
            if (!empty($data['team_ids']) && is_array($data['team_ids'])) {
                foreach ($data['team_ids'] as $tid) {
                    if (ctype_digit((string)$tid)) {
                        $buckets['team'][] = (int)$tid;
                    }
                }
            }
        }

        $map = [];
        foreach ($buckets as $k => $ids) {
            $map[$k] = [];
            $ids = array_values(array_unique(array_filter($ids)));
            if (!$ids) {
                continue;
            }
            try {
                if ($k === 'admin') {
                    $rows = Db::name('tm_admin')->where('id', 'in', $ids)->field('id,name,username')->select();
                    foreach ($rows as $r) {
                        $map[$k][(int)$r['id']] = $r['name'] ?: $r['username'];
                    }
                } elseif ($k === 'role') {
                    $map[$k] = Db::name('tm_role')->where('id', 'in', $ids)->column('name', 'id');
                } elseif ($k === 'batch') {
                    $map[$k] = Db::name('tm_batch')->where('id', 'in', $ids)->column('name', 'id');
                } elseif ($k === 'version') {
                    $map[$k] = Db::name('tm_indicator_version')->where('id', 'in', $ids)->column('name', 'id');
                } elseif ($k === 'indicator') {
                    $map[$k] = Db::name('tm_indicator')->where('id', 'in', $ids)->column('name', 'id');
                } elseif ($k === 'question') {
                    $rows = Db::name('tm_question')->where('id', 'in', $ids)->field('id,title')->select();
                    foreach ($rows as $r) {
                        $title = trim((string)$r['title']);
                        if (function_exists('mb_substr')) {
                            $title = mb_substr($title, 0, 30);
                        } else {
                            $title = substr($title, 0, 30);
                        }
                        $map[$k][(int)$r['id']] = $title !== '' ? $title : ('题目');
                    }
                } elseif ($k === 'option') {
                    $rows = Db::name('tm_option')->where('id', 'in', $ids)->field('id,content')->select();
                    foreach ($rows as $r) {
                        $c = trim((string)$r['content']);
                        if (function_exists('mb_substr')) {
                            $c = mb_substr($c, 0, 20);
                        } else {
                            $c = substr($c, 0, 20);
                        }
                        $map[$k][(int)$r['id']] = $c !== '' ? $c : '选项';
                    }
                } elseif ($k === 'team') {
                    $map[$k] = Db::name('tm_team')->where('id', 'in', $ids)->column('name', 'id');
                } elseif ($k === 'import') {
                    $rows = Db::name('tm_import_job')->where('id', 'in', $ids)->field('id,type,file_path')->select();
                    $typeNames = ['org' => '组织', 'team' => '班组', 'member' => '成员'];
                    foreach ($rows as $r) {
                        $type = isset($typeNames[$r['type']]) ? $typeNames[$r['type']] : '数据';
                        $file = $r['file_path'] ? basename($r['file_path']) : '';
                        $map[$k][(int)$r['id']] = $file !== '' ? ($type . '导入·' . $file) : ($type . '导入任务');
                    }
                }
            } catch (\Exception $e) {
                // 单类查询失败不影响其它
            }
        }
        return $map;
    }

    /**
     * @param string $module
     * @return string
     */
    private static function bizBucket($module)
    {
        $map = [
            'login'             => 'admin',
            'admin'             => 'admin',
            'role'              => 'role',
            'batch'             => 'batch',
            'indicator_version' => 'version',
            'mock'              => 'version',
            'indicator'         => 'indicator',
            'question'          => 'question',
            'option'            => 'option',
            'import'            => 'import',
        ];
        return isset($map[$module]) ? $map[$module] : '';
    }

    /**
     * @param string     $module
     * @param string     $action
     * @param mixed      $bizId
     * @param array      $data
     * @param array      $nameMap
     * @return string
     */
    private static function resolveBizName($module, $action, $bizId, array $data, array $nameMap)
    {
        // 优先 detail 中已有名称
        if ($module === 'admin' || $module === 'login') {
            if (self::val($data, 'username') !== '') {
                return self::val($data, 'username');
            }
        }
        if (in_array($module, ['role', 'batch', 'indicator_version'], true) && self::val($data, 'name') !== '') {
            return self::val($data, 'name');
        }
        if ($module === 'import') {
            $file = self::val($data, 'file_name');
            if ($file === '' && !empty($data['file_path'])) {
                $file = basename((string)$data['file_path']);
            }
            $typeNames = ['member' => '成员导入', 'team' => '班组导入', 'org' => '组织导入'];
            $prefix = isset($typeNames[$action]) ? $typeNames[$action] : '数据导入';
            if ($file !== '') {
                return $prefix . '·' . $file;
            }
        }

        $bucket = self::bizBucket($module);
        $id = trim((string)$bizId);
        if ($bucket && $id !== '') {
            $name = self::mapName($nameMap, $bucket, $id);
            if ($name !== '') {
                return $name;
            }
        }
        return '';
    }

    /**
     * @param array  $nameMap
     * @param string $bucket
     * @param mixed  $id
     * @return string
     */
    private static function mapName(array $nameMap, $bucket, $id)
    {
        if ($id === '' || $id === null) {
            return '';
        }
        $id = (int)$id;
        return (isset($nameMap[$bucket][$id]) && $nameMap[$bucket][$id] !== '')
            ? (string)$nameMap[$bucket][$id]
            : '';
    }

    /**
     * @param array $data
     * @param array $nameMap
     * @return string[]
     */
    private static function resolveTeamNames(array $data, array $nameMap)
    {
        $ids = isset($data['team_ids']) ? (array)$data['team_ids'] : [];
        $names = [];
        foreach ($ids as $tid) {
            $n = self::mapName($nameMap, 'team', $tid);
            if ($n !== '') {
                $names[] = $n;
            }
        }
        return $names;
    }

    /**
     * @param mixed $detail
     * @return array
     */
    private static function parseDetail($detail)
    {
        if (is_array($detail)) {
            return $detail;
        }
        if (!is_string($detail) || $detail === '') {
            return [];
        }
        $decoded = json_decode($detail, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array  $data
     * @param string $key
     * @param string $default
     * @return string
     */
    private static function val(array $data, $key, $default = '')
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return $default;
        }
        if (is_array($data[$key])) {
            return (string)count($data[$key]);
        }
        return (string)$data[$key];
    }

    /**
     * @param string $typeName
     * @param array  $data
     * @param string $bizName
     * @return string
     */
    private static function formatImportDetail($typeName, array $data, $bizName)
    {
        $ok = self::val($data, 'success_cnt', '0');
        $fail = self::val($data, 'fail_cnt', '0');
        $total = self::val($data, 'total_cnt', '');
        $file = self::val($data, 'file_name', '');
        if ($file === '' && !empty($data['file_path'])) {
            $file = basename((string)$data['file_path']);
        }
        $msg = '导入' . $typeName . '完成：成功 ' . $ok . '，失败 ' . $fail;
        if ($total !== '') {
            $msg .= '，共 ' . $total . ' 行';
        }
        if ($file !== '') {
            $msg .= '，文件「' . $file . '」';
        } elseif ($bizName !== '') {
            $msg .= '，' . $bizName;
        }
        return $msg;
    }

    /**
     * @param array  $data
     * @param string $bizName
     * @return string
     */
    private static function formatBatchFinishDetail(array $data, $bizName)
    {
        $q = $bizName !== '' ? '「' . $bizName . '」' : '';
        if (empty($data)) {
            return '结束测评批次' . $q;
        }
        if (isset($data['teams']) && is_array($data['teams'])) {
            return '结束批次' . $q . '并完成算分，共 ' . count($data['teams']) . ' 个班组';
        }
        $extra = self::formatKvFallback($data);
        return '结束测评批次' . $q . ($extra !== '' ? '；' . $extra : '');
    }

    /**
     * @param array  $data
     * @param string $bizName
     * @return string
     */
    private static function formatBatchScoreDetail(array $data, $bizName)
    {
        $cnt = 0;
        if (isset($data['teams']) && is_array($data['teams'])) {
            $cnt = count($data['teams']);
        }
        $q = $bizName !== '' ? '「' . $bizName . '」' : '';
        return '执行批次' . $q . '算分'
            . ($cnt > 0 ? '，产出班组结果 ' . $cnt . ' 条' : '');
    }

    /**
     * @param array $data
     * @return string
     */
    private static function formatKvFallback(array $data)
    {
        $parts = [];
        foreach ($data as $k => $v) {
            if ($v === null || $v === '' || is_array($v)) {
                continue;
            }
            $parts[] = self::fieldLabel((string)$k) . '：' . $v;
        }
        return $parts ? implode('；', $parts) : '';
    }

    /**
     * @param string $key
     * @return string
     */
    private static function fieldLabel($key)
    {
        return isset(self::$fieldLabels[$key]) ? self::$fieldLabels[$key] : $key;
    }

    /**
     * @param string $mode
     * @return string
     */
    private static function pickModeLabel($mode)
    {
        return isset(self::$pickModeLabels[$mode]) ? self::$pickModeLabels[$mode] : ($mode !== '' ? $mode : '—');
    }

    /**
     * @param string $code
     * @return string
     */
    private static function levelLabel($code)
    {
        return isset(self::$levelLabels[$code]) ? self::$levelLabels[$code] : ($code !== '' ? $code : '—');
    }
}

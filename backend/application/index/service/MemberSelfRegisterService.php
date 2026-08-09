<?php
namespace app\index\service;

use think\Db;

/**
 * 小程序扫码后自助登记：选择班组 + 姓名 → 写入 tm_member 并绑定微信用户。
 * 不要求事先导入人员、不要求手机号。
 */
class MemberSelfRegisterService
{
    /**
     * 校验批次可答，且班组在批次内。
     * @return array [ok(bool), msg(string), batch(array|null)]
     */
    public static function assertBatchTeam($batchId, $teamId)
    {
        $batchId = (int)$batchId;
        $teamId = (int)$teamId;
        if ($batchId <= 0 || $teamId <= 0) {
            return [false, '请选择测评批次与班组', null];
        }

        $batch = Db::name('tm_batch')->where('id', $batchId)->whereNull('deleted_at')->find();
        if (!$batch) {
            return [false, '测评批次不存在', null];
        }

        $now = tmNow();
        $status = (int)$batch['status'];
        // 须管理员显式开测（写快照且 status=1）后才可登记/作答
        if ($status === 0) {
            return [false, '测评尚未开测，请等待管理员开始', $batch];
        }
        if ($status === 2) {
            return [false, '测评已结束', $batch];
        }
        if ($status !== 1) {
            return [false, '当前批次不可作答', $batch];
        }
        if (!empty($batch['end_time']) && $batch['end_time'] < $now) {
            return [false, '测评已超过截止时间', $batch];
        }

        $bind = Db::name('tm_batch_team')
            ->where('batch_id', $batchId)
            ->where('team_id', $teamId)
            ->find();
        if (!$bind) {
            return [false, '所选班组不在本批次参评范围', $batch];
        }

        $team = Db::name('tm_team')
            ->where('id', $teamId)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->find();
        if (!$team) {
            return [false, '班组不存在或已停用', $batch];
        }

        return [true, 'ok', $batch];
    }

    /**
     * 按微信用户 + 班组 + 姓名落库成员并双向绑定。
     * @return array 成员行
     */
    public static function ensureMember($wxUserId, $teamId, $name, $roleCode = 'member')
    {
        $wxUserId = (int)$wxUserId;
        $teamId = (int)$teamId;
        $name = trim((string)$name);
        if ($wxUserId <= 0 || $teamId <= 0 || $name === '') {
            throw new \InvalidArgumentException('班组与姓名不能为空');
        }
        if (mb_strlen($name) > 64) {
            throw new \InvalidArgumentException('姓名过长');
        }
        $roles = tmRoleLabels();
        if (!isset($roles[$roleCode])) {
            $roleCode = 'member';
        }

        $wx = Db::name('tm_wx_user')->where('id', $wxUserId)->find();
        if (!$wx) {
            throw new \RuntimeException('微信用户不存在');
        }

        Db::startTrans();
        try {
            $member = null;
            if (!empty($wx['member_id'])) {
                $member = Db::name('tm_member')
                    ->where('id', (int)$wx['member_id'])
                    ->whereNull('deleted_at')
                    ->find();
            }

            $now = tmNow();
            if ($member) {
                Db::name('tm_member')->where('id', $member['id'])->update([
                    'team_id'    => $teamId,
                    'name'       => $name,
                    'role_code'  => $roleCode,
                    'status'     => 1,
                    'wx_user_id' => $wxUserId,
                    'updated_at' => $now,
                ]);
                $memberId = (int)$member['id'];
            } else {
                $memberId = (int)Db::name('tm_member')->insertGetId([
                    'team_id'    => $teamId,
                    'name'       => $name,
                    'mobile'     => null,
                    'post_name'  => null,
                    'role_code'  => $roleCode,
                    'status'     => 1,
                    'wx_user_id' => $wxUserId,
                    'created_at' => $now,
                ]);
            }

            Db::name('tm_wx_user')->where('id', $wxUserId)->update([
                'member_id'  => $memberId,
                'updated_at' => $now,
            ]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }

        return Db::name('tm_member')->where('id', $memberId)->find();
    }

    /**
     * 批次参评班组列表（含组织路径）。
     */
    public static function listBatchTeams($batchId)
    {
        $batchId = (int)$batchId;
        $rows = Db::name('tm_batch_team')->alias('bt')
            ->join('tm_team t', 't.id = bt.team_id')
            ->join('tm_org o', 'o.id = t.org_id', 'LEFT')
            ->where('bt.batch_id', $batchId)
            ->whereNull('t.deleted_at')
            ->where('t.status', 1)
            ->field('t.id as team_id, t.name as team_name, t.org_id, o.name as org_name')
            ->order('t.id asc')
            ->select();

        $pathMap = [];
        $result = [];
        foreach ($rows as $row) {
            $orgId = (int)$row['org_id'];
            if (!isset($pathMap[$orgId])) {
                $pathMap[$orgId] = self::orgPath($orgId);
            }
            $result[] = [
                'team_id'   => (int)$row['team_id'],
                'team_name' => $row['team_name'],
                'org_id'    => $orgId,
                'org_path'  => $pathMap[$orgId],
            ];
        }
        return $result;
    }

    private static function orgPath($orgId)
    {
        if ($orgId <= 0) {
            return '';
        }
        $names = [];
        $guard = 0;
        $id = $orgId;
        while ($id > 0 && $guard < 20) {
            $org = Db::name('tm_org')->where('id', $id)->whereNull('deleted_at')->find();
            if (!$org) {
                break;
            }
            array_unshift($names, $org['name']);
            $id = (int)$org['parent_id'];
            $guard++;
        }
        return implode(' / ', $names);
    }
}

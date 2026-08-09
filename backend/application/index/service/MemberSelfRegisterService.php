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
     * 校验测评批次可登记/作答，且指定班组在批次参评范围内。
     *
     * @param int $batchId 批次 ID
     * @param int $teamId  班组 ID
     * @return array [bool ok, string msg, array|null batch] 成功时 batch 为批次行
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
     * 按微信用户、班组与姓名创建或更新成员，并双向绑定 tm_wx_user。
     * 自助登记角色固定为 member；若本批次已有答卷则禁止更换班组。
     *
     * @param int    $wxUserId 微信用户 ID
     * @param int    $teamId   班组 ID
     * @param string $name     成员姓名
     * @param int    $batchId  批次 ID（>0 时校验是否已在他班作答）
     * @return array 落库后的 tm_member 行
     * @throws \InvalidArgumentException 参数为空或姓名过长
     * @throws \RuntimeException         微信用户不存在或禁止换班
     */
    public static function ensureMember($wxUserId, $teamId, $name, $batchId = 0)
    {
        $wxUserId = (int)$wxUserId;
        $teamId = (int)$teamId;
        $batchId = (int)$batchId;
        $name = trim((string)$name);
        // 自助登记禁止客户端指定角色，避免提权为班组长/安全员等
        $roleCode = 'member';
        if ($wxUserId <= 0 || $teamId <= 0 || $name === '') {
            throw new \InvalidArgumentException('班组与姓名不能为空');
        }
        if (mb_strlen($name) > 64) {
            throw new \InvalidArgumentException('姓名过长');
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
                if ($batchId > 0) {
                    $paperTeamId = Db::name('tm_paper')
                        ->where('batch_id', $batchId)
                        ->where('member_id', (int)$member['id'])
                        ->value('team_id');
                    if ($paperTeamId !== null && $paperTeamId !== '') {
                        if ((int)$paperTeamId !== $teamId) {
                            throw new \RuntimeException('您已在本批次其他班组登记或作答，不能更换班组');
                        }
                    }
                }
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
     * 列出批次下可参评的启用班组（含组织路径）。
     *
     * @param int $batchId 批次 ID
     * @return array 班组列表，每项含 team_id、team_name、org_id、org_path
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

    /**
     * 递归拼接组织名称路径（根到当前节点，以 " / " 分隔）。
     *
     * @param int $orgId 组织 ID
     * @return string 组织路径；无效 ID 返回空字符串
     */
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

<?php
namespace app\index\controller;

use app\index\service\MemberSelfRegisterService;
use app\index\service\MiniJwtService;
use think\Db;
use think\Request;

/**
 * 小程序业务：扫码进批次 → 选班组+姓名 → 成员自助入库。
 */
class TmMini extends MiniBase
{
    /**
     * 本批次可参评班组列表 API（供小程序选择单位）。
     *
     * @return \think\response\Json 返回 batch_id、batch_name 及 teams 列表
     */
    public function batchTeams()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        if ($batchId <= 0) {
            return $this->fail('缺少 batch_id');
        }
        $batch = Db::name('tm_batch')->where('id', $batchId)->whereNull('deleted_at')->find();
        if (!$batch) {
            return $this->fail('测评批次不存在');
        }
        $list = MemberSelfRegisterService::listBatchTeams($batchId);
        return $this->ok([
            'batch_id'   => $batchId,
            'batch_name' => $batch['name'],
            'teams'      => $list,
        ]);
    }

    /**
     * 确认现场身份并写入成员档案 API（主路径，POST）。
     * 入参：batch_id, team_id, name（角色固定为普通成员，不接受客户端 role_code）。
     *
     * @return \think\response\Json 登记成功返回含 member_id 的新 JWT token
     */
    public function enterBatch()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return $this->fail('method not allowed', 405);
        }
        $batchId = (int)$req->param('batch_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        $name = $req->param('name', '', 'trim');

        if ($name === '') {
            return $this->fail('请填写姓名');
        }

        list($ok, $msg, $batch) = MemberSelfRegisterService::assertBatchTeam($batchId, $teamId);
        if (!$ok) {
            $code = (strpos($msg, '不在本批次') !== false || strpos($msg, '不可作答') !== false
                || strpos($msg, '已结束') !== false || strpos($msg, '尚未开始') !== false
                || strpos($msg, '截止') !== false) ? 403 : 201;
            return $this->fail($msg, $code);
        }

        try {
            $member = MemberSelfRegisterService::ensureMember(
                (int)$this->wxUser['id'],
                $teamId,
                $name,
                $batchId
            );
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }

        $team = Db::name('tm_team')->where('id', $teamId)->find();
        $orgPath = '';
        if ($team) {
            $teams = MemberSelfRegisterService::listBatchTeams($batchId);
            foreach ($teams as $t) {
                if ((int)$t['team_id'] === $teamId) {
                    $orgPath = $t['org_path'];
                    break;
                }
            }
        }

        $token = MiniJwtService::encode([
            'uid'       => (int)$this->wxUser['id'],
            'batch_id'  => $batchId,
            'team_id'   => $teamId,
            'name'      => $name,
            'member_id' => (int)$member['id'],
        ]);

        return $this->ok([
            'token'      => $token,
            'batch_id'   => $batchId,
            'batch_name' => $batch['name'],
            'team_id'    => $teamId,
            'team_name'  => $team ? $team['name'] : '',
            'org_path'   => $orgPath,
            'name'       => $name,
            'member_id'  => (int)$member['id'],
            'role_code'  => $member['role_code'],
        ], '登记成功');
    }

    /**
     * 当前微信用户与已绑定成员信息 API。
     *
     * @return \think\response\Json 返回 user、member 及 JWT session 上下文
     */
    public function profile()
    {
        $wx = $this->wxUser;
        $member = null;
        if (!empty($wx['member_id'])) {
            $member = Db::name('tm_member')->alias('m')
                ->join('tm_team t', 't.id = m.team_id', 'LEFT')
                ->where('m.id', (int)$wx['member_id'])
                ->whereNull('m.deleted_at')
                ->field('m.id, m.name, m.team_id, m.role_code, m.mobile, t.name as team_name')
                ->find();
        }
        return $this->ok([
            'user' => [
                'id'         => (int)$wx['id'],
                'nickname'   => $wx['nickname'],
                'avatar_url' => $wx['avatar_url'],
            ],
            'member' => $member,
            'session' => [
                'batch_id'  => isset($this->jwt['batch_id']) ? (int)$this->jwt['batch_id'] : null,
                'team_id'   => isset($this->jwt['team_id']) ? (int)$this->jwt['team_id'] : null,
                'name'      => isset($this->jwt['name']) ? $this->jwt['name'] : null,
                'member_id' => isset($this->jwt['member_id']) ? (int)$this->jwt['member_id'] : null,
            ],
        ]);
    }
}

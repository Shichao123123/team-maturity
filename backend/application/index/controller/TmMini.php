<?php
namespace app\index\controller;

use app\index\service\MemberSelfRegisterService;
use app\index\service\MiniJwtService;
use app\index\service\MiniPaperService;
use think\Db;
use think\Request;

/**
 * 小程序业务：扫码进批次 → 选班组+姓名 → 答题提交 → 查结果。
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
     * 扫码进场分流：已做过题则跳过选班组填姓名。
     *
     * @return \think\response\Json
     */
    public function entryStatus()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg, $data, $code) = MiniPaperService::entryStatus((int)$this->wxUser['id'], $batchId);
        if (!$ok) {
            return $this->fail($msg, $code, $data);
        }
        return $this->ok($data, $msg);
    }

    /**
     * 测试账号重置答卷（仅 config.local.php 中 dev_login=true）。
     *
     * @return \think\response\Json
     */
    public function resetPaper()
    {
        $req = Request::instance();
        if (!$this->requirePost()) {
            return $this->fail('method not allowed', 405);
        }
        $batchId = (int)$req->param('batch_id', isset($this->jwt['batch_id']) ? $this->jwt['batch_id'] : 0);
        list($ok, $msg, $data, $code) = MiniPaperService::resetPaper((int)$this->wxUser['id'], $batchId);
        if (!$ok) {
            return $this->fail($msg, $code, $data);
        }
        return $this->ok($data, $msg);
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
        if (!$this->requirePost()) {
            return $this->fail('method not allowed', 405);
        }
        $batchId = (int)$req->param('batch_id', 0);
        $teamId = (int)$req->param('team_id', 0);
        $name = $req->param('name', '', 'trim');

        if ($name === '') {
            return $this->fail('请填写姓名');
        }

        // 若本批次已有答卷，直接签发 token，无需重复登记
        $existPaper = Db::name('tm_paper')
            ->where('batch_id', $batchId)
            ->where('wx_user_id', (int)$this->wxUser['id'])
            ->find();
        if (!$existPaper && !empty($this->wxUser['member_id'])) {
            $existPaper = Db::name('tm_paper')
                ->where('batch_id', $batchId)
                ->where('member_id', (int)$this->wxUser['member_id'])
                ->find();
        }
        if ($existPaper) {
            $member = Db::name('tm_member')->where('id', (int)$existPaper['member_id'])->find();
            $teamId = (int)$existPaper['team_id'];
            $name = $member ? $member['name'] : $name;
            $batch = Db::name('tm_batch')->where('id', $batchId)->whereNull('deleted_at')->find();
            $team = Db::name('tm_team')->where('id', $teamId)->find();
            $token = MiniJwtService::encode([
                'uid'       => (int)$this->wxUser['id'],
                'batch_id'  => $batchId,
                'team_id'   => $teamId,
                'name'      => $name,
                'member_id' => (int)$existPaper['member_id'],
            ]);
            return $this->ok([
                'token'      => $token,
                'batch_id'   => $batchId,
                'batch_name' => $batch ? $batch['name'] : '',
                'team_id'    => $teamId,
                'team_name'  => $team ? $team['name'] : '',
                'org_path'   => '',
                'name'       => $name,
                'member_id'  => (int)$existPaper['member_id'],
                'role_code'  => $member ? $member['role_code'] : 'member',
                'paper_id'   => (int)$existPaper['id'],
                'paper_status' => (int)$existPaper['status'],
            ], '已登记，直接进入');
        }

        list($ok, $msg, $batch) = MemberSelfRegisterService::assertBatchTeam($batchId, $teamId);
        if (!$ok && MiniPaperService::allowRetake() && $batch && (int)$batch['status'] !== 0) {
            $bind = Db::name('tm_batch_team')->where('batch_id', $batchId)->where('team_id', $teamId)->find();
            $ok = (bool)$bind;
            if (!$ok) {
                $msg = '所选班组不在本批次参评范围';
            }
        }
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

    /**
     * 开始/继续答题：从批次快照拉题（不下发权重）。
     *
     * @return \think\response\Json
     */
    public function startPaper()
    {
        $req = Request::instance();
        if (!$this->requirePost()) {
            return $this->fail('method not allowed', 405);
        }
        $batchId = (int)$req->param('batch_id', isset($this->jwt['batch_id']) ? $this->jwt['batch_id'] : 0);
        $teamId = (int)$req->param('team_id', isset($this->jwt['team_id']) ? $this->jwt['team_id'] : 0);
        $name = $req->param('name', isset($this->jwt['name']) ? $this->jwt['name'] : '', 'trim');
        $memberId = (int)$req->param('member_id', isset($this->jwt['member_id']) ? $this->jwt['member_id'] : 0);

        list($ok, $msg, $data, $code) = MiniPaperService::startPaper(
            (int)$this->wxUser['id'],
            $batchId,
            $teamId,
            $name,
            $memberId
        );
        if (!$ok) {
            return $this->fail($msg, $code, $data);
        }
        if ($code === 202) {
            return json(DefaultResponse(202, true, $msg, $data));
        }
        return $this->ok($data, $msg);
    }

    /**
     * 保存单题答案。
     *
     * @return \think\response\Json
     */
    public function answer()
    {
        $req = Request::instance();
        if (!$this->requirePost()) {
            return $this->fail('method not allowed', 405);
        }
        list($ok, $msg, $data, $code) = MiniPaperService::answer(
            (int)$this->wxUser['id'],
            (int)$req->param('paper_id', 0),
            (int)$req->param('question_id', 0),
            (int)$req->param('option_id', 0)
        );
        if (!$ok) {
            return $this->fail($msg, $code, $data);
        }
        return $this->ok($data, $msg);
    }

    /**
     * 提交答卷并计算个人得分。
     *
     * @return \think\response\Json
     */
    public function submit()
    {
        $req = Request::instance();
        if (!$this->requirePost()) {
            return $this->fail('method not allowed', 405);
        }
        list($ok, $msg, $data, $code) = MiniPaperService::submit(
            (int)$this->wxUser['id'],
            (int)$req->param('paper_id', 0)
        );
        if (!$ok) {
            return $this->fail($msg, $code, $data);
        }
        if ($code === 202) {
            return json(DefaultResponse(202, true, $msg, $data));
        }
        return $this->ok($data, $msg);
    }

    /**
     * 我的测评结果。
     *
     * @return \think\response\Json
     */
    public function myResult()
    {
        $batchId = (int)Request::instance()->param('batch_id', isset($this->jwt['batch_id']) ? $this->jwt['batch_id'] : 0);
        list($ok, $msg, $data, $code) = MiniPaperService::myResult((int)$this->wxUser['id'], $batchId);
        if (!$ok) {
            return $this->fail($msg, $code, $data);
        }
        return $this->ok($data, $msg);
    }

    /**
     * 我参与过的批次列表。
     *
     * @return \think\response\Json
     */
    public function myBatches()
    {
        list($ok, $msg, $data, $code) = MiniPaperService::myBatches((int)$this->wxUser['id']);
        if (!$ok) {
            return $this->fail($msg, $code, $data);
        }
        return $this->ok($data, $msg);
    }

    /**
     * 批次详情（含本人答卷与班组结果）。
     *
     * @return \think\response\Json
     */
    public function batchDetail()
    {
        $batchId = (int)Request::instance()->param('batch_id', 0);
        list($ok, $msg, $data, $code) = MiniPaperService::batchDetail((int)$this->wxUser['id'], $batchId);
        if (!$ok) {
            return $this->fail($msg, $code, $data);
        }
        return $this->ok($data, $msg);
    }
}

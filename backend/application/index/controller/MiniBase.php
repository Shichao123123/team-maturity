<?php
namespace app\index\controller;

use app\index\service\MiniJwtService;
use think\Controller;
use think\Db;
use think\Request;

/**
 * 小程序接口基类：不走 PC Session，使用 Header token（JWT）。
 */
class MiniBase extends Controller
{
    /** @var array|null JWT payload */
    protected $jwt;

    /** @var array|null tm_wx_user */
    protected $wxUser;

    protected $needLogin = true;

    public function _initialize()
    {
        if (!$this->needLogin) {
            return;
        }
        $token = $this->readToken();
        $payload = MiniJwtService::decode($token);
        if (!$payload) {
            $this->jsonOut(401, false, '未登录或 token 无效');
        }
        $wx = Db::name('tm_wx_user')->where('id', (int)$payload['uid'])->find();
        if (!$wx) {
            $this->jsonOut(401, false, '用户不存在，请重新登录');
        }
        $this->jwt = $payload;
        $this->wxUser = $wx;
    }

    protected function readToken()
    {
        $req = Request::instance();
        $token = $req->header('token', '');
        if ($token === '') {
            $token = $req->header('Token', '');
        }
        if ($token === '') {
            $auth = $req->header('Authorization', '');
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        }
        if ($token === '') {
            $token = $req->param('token', '', 'trim');
        }
        return is_string($token) ? trim($token) : '';
    }

    protected function jsonOut($code, $success, $msg, $data = null)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(DefaultResponse($code, $success, $msg, $data), JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function ok($data = null, $msg = 'ok')
    {
        return json(DefaultResponse(200, true, $msg, $data));
    }

    protected function fail($msg, $code = 201, $data = null)
    {
        return json(DefaultResponse($code, false, $msg, $data));
    }
}

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

    /**
     * 控制器初始化：校验 JWT token 并加载微信用户。
     * 未登录或 token 无效时直接输出 401 JSON 并终止。
     *
     * @return void
     */
    public function _initialize()
    {
        // 真机调试跨域会先发 OPTIONS；必须直接放行，否则会被业务里的 isPost 打成 405
        if ($this->isOptionsRequest()) {
            $this->corsHeaders();
            header('Content-Length: 0');
            http_response_code(204);
            exit;
        }
        $this->corsHeaders();

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

    /**
     * @return bool
     */
    protected function isOptionsRequest()
    {
        $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        return $m === 'OPTIONS';
    }

    /**
     * 小程序真机调试 / 局域网跨域所需响应头。
     *
     * @return void
     */
    protected function corsHeaders()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string)$_SERVER['HTTP_ORIGIN']) : '';
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, token, Token, Authorization');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * 是否为 POST（兼容部分代理对 method 的改写）。
     *
     * @return bool
     */
    protected function requirePost()
    {
        $req = Request::instance();
        if ($req->isPost()) {
            return true;
        }
        $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        return $m === 'POST';
    }

    /**
     * 从请求头或参数中读取 JWT token。
     * 依次尝试 token/Token 头、Authorization Bearer、query 参数 token。
     *
     * @return string 去除首尾空白的 token 字符串，未找到时返回空串
     */
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

    /**
     * 输出标准 JSON 响应并终止脚本（用于鉴权失败等场景）。
     *
     * @param int         $code    业务状态码
     * @param bool        $success 是否成功
     * @param string      $msg     提示信息
     * @param mixed|null  $data    附加数据
     * @return void
     */
    protected function jsonOut($code, $success, $msg, $data = null)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(DefaultResponse($code, $success, $msg, $data), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 返回成功 JSON 响应（HTTP 200，不终止脚本）。
     *
     * @param mixed|null $data 响应数据
     * @param string     $msg  提示信息
     * @return \think\response\Json
     */
    protected function ok($data = null, $msg = 'ok')
    {
        return json(DefaultResponse(200, true, $msg, $data));
    }

    /**
     * 返回失败 JSON 响应。
     *
     * @param string     $msg  错误提示
     * @param int        $code 业务状态码，默认 201
     * @param mixed|null $data 附加数据
     * @return \think\response\Json
     */
    protected function fail($msg, $code = 201, $data = null)
    {
        return json(DefaultResponse($code, false, $msg, $data));
    }
}

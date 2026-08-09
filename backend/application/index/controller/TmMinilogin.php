<?php
namespace app\index\controller;

use app\index\service\MiniJwtService;
use think\Db;
use think\Request;

/**
 * 小程序登录：仅建立微信会话，不要求已有成员档案。
 */
class TmMinilogin extends MiniBase
{
    protected $needLogin = false;

    public function login()
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return $this->fail('method not allowed', 405);
        }
        $code = $req->param('code', '', 'trim');
        if ($code === '') {
            return $this->fail('缺少 code');
        }

        list($ok, $openId, $unionId, $err) = $this->resolveOpenId($code);
        if (!$ok) {
            return $this->fail($err);
        }

        $now = tmNow();
        $wx = Db::name('tm_wx_user')->where('open_id', $openId)->find();
        if ($wx) {
            $upd = ['updated_at' => $now];
            if ($unionId !== '' && empty($wx['union_id'])) {
                $upd['union_id'] = $unionId;
            }
            Db::name('tm_wx_user')->where('id', $wx['id'])->update($upd);
            $wxId = (int)$wx['id'];
            $wx = Db::name('tm_wx_user')->where('id', $wxId)->find();
        } else {
            $wxId = (int)Db::name('tm_wx_user')->insertGetId([
                'open_id'    => $openId,
                'union_id'   => $unionId !== '' ? $unionId : null,
                'nickname'   => '',
                'avatar_url' => '',
                'mobile'     => null,
                'member_id'  => null,
                'created_at' => $now,
            ]);
            $wx = Db::name('tm_wx_user')->where('id', $wxId)->find();
        }

        $token = MiniJwtService::encode(['uid' => $wxId]);
        return $this->ok([
            'token' => $token,
            'user'  => [
                'id'         => $wxId,
                'nickname'   => isset($wx['nickname']) ? $wx['nickname'] : '',
                'avatar_url' => isset($wx['avatar_url']) ? $wx['avatar_url'] : '',
                'member_id'  => !empty($wx['member_id']) ? (int)$wx['member_id'] : null,
            ],
        ]);
    }

    /**
     * @return array [ok, openId, unionId, err]
     */
    private function resolveOpenId($code)
    {
        $cfg = config('mini');
        $appid = isset($cfg['wx_appid']) ? trim($cfg['wx_appid']) : '';
        $secret = isset($cfg['wx_secret']) ? trim($cfg['wx_secret']) : '';
        $dev = !empty($cfg['dev_login']);

        if ($appid === '' || $secret === '') {
            if (!$dev) {
                return [false, '', '', '未配置微信小程序 appid/secret'];
            }
            // 本地联调：code 即 open_id
            $openId = 'dev_' . substr(md5($code), 0, 28);
            return [true, $openId, '', ''];
        }

        $url = 'https://api.weixin.qq.com/sns/jscode2session?appid='
            . urlencode($appid)
            . '&secret=' . urlencode($secret)
            . '&js_code=' . urlencode($code)
            . '&grant_type=authorization_code';
        $raw = $this->httpGet($url);
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['openid'])) {
            $msg = isset($json['errmsg']) ? $json['errmsg'] : '微信登录失败';
            return [false, '', '', $msg];
        }
        $union = isset($json['unionid']) ? $json['unionid'] : '';
        return [true, $json['openid'], $union, ''];
    }

    private function httpGet($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $body = curl_exec($ch);
            curl_close($ch);
            return $body === false ? '' : $body;
        }
        return (string)@file_get_contents($url);
    }
}

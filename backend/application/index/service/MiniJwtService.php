<?php
namespace app\index\service;

/**
 * 轻量 JWT（HS256），供小程序鉴权。不引入第三方库。
 */
class MiniJwtService
{
    public static function encode(array $payload, $ttl = null)
    {
        $cfg = config('mini');
        $secret = isset($cfg['jwt_secret']) ? $cfg['jwt_secret'] : 'tm_mini_dev_secret';
        $ttl = $ttl !== null ? (int)$ttl : (isset($cfg['jwt_ttl']) ? (int)$cfg['jwt_ttl'] : 604800);
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttl;

        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $sig = self::b64(hash_hmac('sha256', $header . '.' . $body, $secret, true));
        return $header . '.' . $body . '.' . $sig;
    }

    /**
     * @return array|null
     */
    public static function decode($token)
    {
        if (!is_string($token) || $token === '' || substr_count($token, '.') !== 2) {
            return null;
        }
        list($header, $body, $sig) = explode('.', $token, 3);
        $cfg = config('mini');
        $secret = isset($cfg['jwt_secret']) ? $cfg['jwt_secret'] : 'tm_mini_dev_secret';
        $expect = self::b64(hash_hmac('sha256', $header . '.' . $body, $secret, true));
        if (!hash_equals($expect, $sig)) {
            return null;
        }
        $payload = json_decode(self::ub64($body), true);
        if (!is_array($payload) || empty($payload['uid'])) {
            return null;
        }
        if (isset($payload['exp']) && (int)$payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function ub64($b64)
    {
        $pad = 4 - (strlen($b64) % 4);
        if ($pad < 4) {
            $b64 .= str_repeat('=', $pad);
        }
        return base64_decode(strtr($b64, '-_', '+/'));
    }
}

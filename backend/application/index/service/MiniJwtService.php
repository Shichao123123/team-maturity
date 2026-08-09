<?php
namespace app\index\service;

/**
 * 轻量 JWT（HS256），供小程序鉴权。不引入第三方库。
 */
class MiniJwtService
{
    /**
     * 签发 HS256 JWT，自动写入 iat、exp。
     *
     * @param array    $payload 自定义载荷（须含 uid 等业务字段）
     * @param int|null $ttl     有效秒数；null 时读取 config('mini.jwt_ttl')，默认 604800
     * @return string JWT 字符串（header.body.signature）
     */
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
     * 校验并解析 JWT，验证签名、过期时间及 uid 字段。
     *
     * @param string $token JWT 字符串
     * @return array|null 解析后的载荷；无效、过期或验签失败时返回 null
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

    /**
     * URL 安全的 Base64 编码（无填充）。
     *
     * @param string $raw 原始二进制或字符串
     * @return string Base64URL 编码结果
     */
    private static function b64($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * URL 安全的 Base64 解码。
     *
     * @param string $b64 Base64URL 编码字符串
     * @return string|false 解码后的原始内容
     */
    private static function ub64($b64)
    {
        $pad = 4 - (strlen($b64) % 4);
        if ($pad < 4) {
            $b64 .= str_repeat('=', $pad);
        }
        return base64_decode(strtr($b64, '-_', '+/'));
    }
}

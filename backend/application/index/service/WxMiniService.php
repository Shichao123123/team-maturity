<?php
namespace app\index\service;

use think\Config;

/**
 * 微信小程序：access_token 与正式小程序码（getwxacodeunlimit）。
 */
class WxMiniService
{
    /**
     * 是否已配置可用的 appid/secret。
     *
     * @return bool
     */
    public static function isConfigured()
    {
        $cfg = Config::get('mini') ?: [];
        $appid = isset($cfg['wx_appid']) ? trim((string)$cfg['wx_appid']) : '';
        $secret = isset($cfg['wx_secret']) ? trim((string)$cfg['wx_secret']) : '';
        return $appid !== '' && $secret !== '';
    }

    /**
     * 当前小程序码环境版本：develop / trial / release。
     *
     * @return string
     */
    public static function envVersion()
    {
        $cfg = Config::get('mini') ?: [];
        if (!empty($cfg['wxacode_env'])) {
            $env = strtolower(trim((string)$cfg['wxacode_env']));
            if (in_array($env, ['develop', 'trial', 'release'], true)) {
                return $env;
            }
        }
        // 本地/测试默认 develop，避免未发版小程序无法出码
        return !empty($cfg['dev_login']) ? 'develop' : 'release';
    }

    /**
     * 获取并缓存 access_token。
     *
     * @param bool $forceRefresh 强制刷新
     * @return array [ok, token|errmsg]
     */
    public static function getAccessToken($forceRefresh = false)
    {
        if (!self::isConfigured()) {
            return [false, '未配置微信小程序 appid/secret'];
        }
        $cfg = Config::get('mini') ?: [];
        $appid = trim((string)$cfg['wx_appid']);
        $secret = trim((string)$cfg['wx_secret']);
        $cacheFile = self::runtimeDir() . 'wx_access_token.json';

        if (!$forceRefresh && is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $cached = $raw ? json_decode($raw, true) : null;
            if (is_array($cached)
                && !empty($cached['access_token'])
                && !empty($cached['expire_at'])
                && (int)$cached['expire_at'] > time() + 60
                && isset($cached['appid']) && $cached['appid'] === $appid
            ) {
                return [true, (string)$cached['access_token']];
            }
        }

        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential'
            . '&appid=' . urlencode($appid)
            . '&secret=' . urlencode($secret);
        list($ok, $body) = self::httpGet($url);
        if (!$ok) {
            return [false, $body];
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            $err = isset($data['errmsg']) ? $data['errmsg'] : '获取 access_token 失败';
            $code = isset($data['errcode']) ? ('[' . $data['errcode'] . '] ') : '';
            return [false, $code . $err];
        }
        $ttl = isset($data['expires_in']) ? (int)$data['expires_in'] : 7200;
        @file_put_contents($cacheFile, json_encode([
            'appid'         => $appid,
            'access_token'  => $data['access_token'],
            'expire_at'     => time() + max(300, $ttl - 120),
        ], JSON_UNESCAPED_UNICODE));
        return [true, (string)$data['access_token']];
    }

    /**
     * 生成批次正式小程序码 PNG 字节。
     * 使用 getwxacodeunlimit：码本身长期有效（不随 access_token 过期），可打印发给员工反复使用。
     * scene 使用 b={batchId}（≤32 字符），小程序端需解析 scene。
     *
     * @param int  $batchId
     * @param bool $forceRefresh 忽略本地缓存重新拉码
     * @param int  $width        码边长 px（280~1280）
     * @return array [ok, pngBinary|errmsg, meta]
     */
    public static function getBatchWxacode($batchId, $forceRefresh = false, $width = 280)
    {
        $batchId = (int)$batchId;
        if ($batchId <= 0) {
            return [false, '批次无效', []];
        }
        if (!self::isConfigured()) {
            return [false, '未配置微信小程序 appid/secret', []];
        }

        $width = (int)$width;
        if ($width < 280) {
            $width = 280;
        }
        if ($width > 1280) {
            $width = 1280;
        }

        $cfg = Config::get('mini') ?: [];
        $page = isset($cfg['qr_path']) && $cfg['qr_path'] !== ''
            ? trim((string)$cfg['qr_path'])
            : 'pages/exam/index';
        $page = ltrim($page, '/');
        $env = self::envVersion();
        $scene = 'b=' . $batchId;
        if (strlen($scene) > 32) {
            return [false, 'scene 超长', []];
        }

        $cacheDir = self::runtimeDir() . 'wxacode' . DIRECTORY_SEPARATOR;
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . 'batch_' . $batchId . '_' . $env . '_w' . $width . '.png';
        if (!$forceRefresh && is_file($cacheFile) && filesize($cacheFile) > 100) {
            $bin = @file_get_contents($cacheFile);
            if ($bin !== false) {
                return [true, $bin, [
                    'scene'       => $scene,
                    'page'        => $page,
                    'env_version' => $env,
                    'width'       => $width,
                    'cached'      => true,
                    'permanent'   => true,
                ]];
            }
        }

        list($tokOk, $tokenOrErr) = self::getAccessToken(false);
        if (!$tokOk) {
            return [false, $tokenOrErr, []];
        }

        $payload = [
            'scene'       => $scene,
            'page'        => $page,
            'width'       => $width,
            'check_path'  => false,
            'env_version' => $env,
        ];
        $url = 'https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token=' . urlencode($tokenOrErr);
        list($ok, $body) = self::httpPostJson($url, $payload);
        if (!$ok) {
            return [false, $body, []];
        }

        // 失败时微信返回 JSON；成功时直接返回图片二进制
        $trim = ltrim($body);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $err = json_decode($body, true);
            $msg = is_array($err) && isset($err['errmsg']) ? $err['errmsg'] : '生成小程序码失败';
            $code = is_array($err) && isset($err['errcode']) ? (int)$err['errcode'] : 0;
            // token 失效则刷新一次重试
            if (in_array($code, [40001, 40014, 42001], true)) {
                list($tokOk2, $token2) = self::getAccessToken(true);
                if ($tokOk2) {
                    $url2 = 'https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token=' . urlencode($token2);
                    list($ok2, $body2) = self::httpPostJson($url2, $payload);
                    if ($ok2) {
                        $trim2 = ltrim($body2);
                        if ($trim2 !== '' && $trim2[0] !== '{' && $trim2[0] !== '[') {
                            @file_put_contents($cacheFile, $body2);
                            return [true, $body2, [
                                'scene'       => $scene,
                                'page'        => $page,
                                'env_version' => $env,
                                'width'       => $width,
                                'cached'      => false,
                                'permanent'   => true,
                            ]];
                        }
                        $err2 = json_decode($body2, true);
                        $msg = is_array($err2) && isset($err2['errmsg']) ? $err2['errmsg'] : $msg;
                        $code = is_array($err2) && isset($err2['errcode']) ? (int)$err2['errcode'] : $code;
                    }
                }
            }
            return [false, ($code ? ('[' . $code . '] ') : '') . $msg, [
                'scene'       => $scene,
                'page'        => $page,
                'env_version' => $env,
            ]];
        }

        @file_put_contents($cacheFile, $body);
        return [true, $body, [
            'scene'       => $scene,
            'page'        => $page,
            'env_version' => $env,
            'width'       => $width,
            'cached'      => false,
            'permanent'   => true,
        ]];
    }

    /**
     * @return string
     */
    private static function runtimeDir()
    {
        $dir = defined('RUNTIME_PATH') ? RUNTIME_PATH : (dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * @param string $url
     * @return array [ok, body|errmsg]
     */
    private static function httpGet($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($errno) {
                return [false, '网络错误：' . $err];
            }
            return [true, (string)$body];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 20]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return [false, '网络请求失败'];
        }
        return [true, $body];
    }

    /**
     * @param string $url
     * @param array  $data
     * @return array [ok, body|errmsg]
     */
    private static function httpPostJson($url, array $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($errno) {
                return [false, '网络错误：' . $err];
            }
            return [true, (string)$body];
        }
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 30,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return [false, '网络请求失败'];
        }
        return [true, $body];
    }
}

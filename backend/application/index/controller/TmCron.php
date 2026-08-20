<?php
namespace app\index\controller;

use app\index\service\BatchService;
use think\Controller;
use think\Request;

/**
 * 定时任务入口（无需登录）。
 * 宝塔计划任务示例：
 * curl -s "https://你的域名/TmCron/batchAutoFinish?key=你的密钥"
 */
class TmCron extends Controller
{
    /**
     * 自动结束已过截止时间的进行中批次并算分。
     *
     * @return \think\response\Json
     */
    public function batchAutoFinish()
    {
        $req = Request::instance();
        $key = (string)$req->param('key', '');
        $expect = self::cronKey();
        if ($expect === '' || !hash_equals($expect, $key)) {
            return json(DefaultResponse(403, false, 'forbidden'));
        }

        list($count, $items) = BatchService::autoFinishExpired(20, 10, true);
        return json(DefaultResponse(0, true, 'ok', [
            'processed' => $count,
            'items'     => $items,
        ]));
    }

    /**
     * @return string
     */
    private static function cronKey()
    {
        $env = getenv('TM_CRON_KEY');
        if ($env !== false && $env !== '') {
            return (string)$env;
        }
        $local = [];
        $file = APP_PATH . 'config.local.php';
        if (is_file($file)) {
            $tmp = include $file;
            if (is_array($tmp)) {
                $local = $tmp;
            }
        }
        return isset($local['cron_key']) ? (string)$local['cron_key'] : '';
    }
}

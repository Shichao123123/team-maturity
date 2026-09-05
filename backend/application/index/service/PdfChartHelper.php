<?php
namespace app\index\service;

/**
 * 用 GD + 中文 TTF 生成图表 PNG，供 PDF 嵌入。
 */
class PdfChartHelper
{
    /**
     * 解析可用于 imagettftext 的中文 TTF。
     *
     * @return string
     */
    public static function ttfPath()
    {
        $candidates = [
            ROOT_PATH . 'extend' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'simhei.ttf',
            'C:\\Windows\\Fonts\\simhei.ttf',
            'C:\\Windows\\Fonts\\msyh.ttf',
            'C:\\Windows\\Fonts\\simkai.ttf',
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        throw new \RuntimeException('未找到中文字体文件，无法绘制图表文字');
    }

    /**
     * 临时 PNG 路径。
     *
     * @param string $prefix
     * @return string
     */
    public static function tempPngPath($prefix = 'chart')
    {
        $dir = ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR . 'pdf-charts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $prefix . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.png';
    }

    /**
     * 绘制成熟度等级环形饼图。
     *
     * @param array $levels [['level_name'=>'','count'=>0,'percent'=>0], ...]
     * @return string PNG 绝对路径
     */
    public static function renderPieLevels(array $levels)
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('PHP GD 扩展未启用');
        }
        $w = 920;
        $h = 480;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        $white = imagecolorallocate($img, 255, 255, 255);
        $text = imagecolorallocate($img, 51, 65, 85);
        $muted = imagecolorallocate($img, 148, 163, 184);
        imagefilledrectangle($img, 0, 0, $w, $h, $white);

        $colorsHex = ['0b3d91', '1677ff', '69b1ff', 'bae0ff', '94a3b8', '64748b'];
        $palette = [];
        foreach ($colorsHex as $hex) {
            $palette[] = imagecolorallocate(
                $img,
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2))
            );
        }

        $total = 0;
        foreach ($levels as $lv) {
            $total += (int)$lv['count'];
        }

        $cx = 280;
        $cy = 250;
        $rOut = 150;
        $rIn = 88;
        $ttf = self::ttfPath();
        self::ttf($img, 16, 24, 28, $text, $ttf, '成熟度等级分布');

        if ($total <= 0) {
            self::ttf($img, 14, $cx - 60, $cy, $muted, $ttf, '暂无测评结果');
        } else {
            $start = -90.0; // 从顶部开始
            $i = 0;
            foreach ($levels as $lv) {
                $cnt = (int)$lv['count'];
                if ($cnt <= 0) {
                    $i++;
                    continue;
                }
                $sweep = 360.0 * $cnt / $total;
                $color = $palette[$i % count($palette)];
                self::filledArc($img, $cx, $cy, $rOut * 2, $rOut * 2, $start, $start + $sweep, $color);
                $start += $sweep;
                $i++;
            }
            // 挖空成环形
            imagefilledellipse($img, $cx, $cy, $rIn * 2, $rIn * 2, $white);
            self::ttf($img, 13, $cx - 28, $cy - 6, $text, $ttf, '班组数');
            self::ttf($img, 22, $cx - 18, $cy + 26, $text, $ttf, (string)$total);

            // 图例
            $lx = 520;
            $ly = 120;
            $i = 0;
            foreach ($levels as $lv) {
                $color = $palette[$i % count($palette)];
                imagefilledrectangle($img, $lx, $ly + $i * 48, $lx + 22, $ly + 22 + $i * 48, $color);
                $name = isset($lv['level_name']) ? $lv['level_name'] : '';
                $cnt = (int)$lv['count'];
                $pct = isset($lv['percent_text']) ? $lv['percent_text'] : (isset($lv['percent']) ? ($lv['percent'] . '%') : '');
                self::ttf($img, 13, $lx + 34, $ly + 18 + $i * 48, $text, $ttf, $name);
                self::ttf($img, 12, $lx + 34, $ly + 38 + $i * 48, $muted, $ttf, $cnt . ' 个 · ' . $pct);
                $i++;
            }
        }

        $path = self::tempPngPath('pie');
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    /**
     * 绘制班组成长趋势折线图。
     *
     * @param array $batches [['batch_name'=>''], ...]
     * @param array $series  [['team_name'=>'', 'scores'=>[float|null,...]], ...]
     * @return string PNG 绝对路径
     */
    public static function renderTrendLines(array $batches, array $series)
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('PHP GD 扩展未启用');
        }
        $seriesCnt = count($series);
        $legendW = 240;
        $rowH = 22;
        $w = 1180;
        $nPreview = count($batches);
        $perLinePreview = $nPreview >= 8 ? 4 : ($nPreview >= 5 ? 5 : 6);
        $maxNameLines = 1;
        foreach ($batches as $b) {
            $nm = isset($b['batch_name']) ? (string)$b['batch_name'] : '';
            $len = function_exists('mb_strlen') ? mb_strlen($nm, 'UTF-8') : strlen($nm);
            $maxNameLines = max($maxNameLines, max(1, (int)ceil($len / max(1, $perLinePreview))));
        }
        // 序号行 + 完整批次名换行（不加 …）
        $labelPad = max(48, 24 + $maxNameLines * 14);
        $h = max(520, 120 + $seriesCnt * $rowH, 420 + $labelPad + 40);
        if ($h > 2000) {
            $h = 2000;
        }

        $img = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($img, 255, 255, 255);
        $grid = imagecolorallocate($img, 226, 232, 240);
        $axis = imagecolorallocate($img, 148, 163, 184);
        $text = imagecolorallocate($img, 51, 65, 85);
        $muted = imagecolorallocate($img, 100, 116, 139);
        $panel = imagecolorallocate($img, 250, 251, 252);
        $border = imagecolorallocate($img, 232, 238, 246);
        imagefilledrectangle($img, 0, 0, $w, $h, $white);

        $paletteHex = [
            '1677ff', '13c2c2', '52c41a', 'faad14', 'eb2f96', '722ed1',
            'fa541c', '2f54eb', '08979c', 'd48806', 'c41d7f', '531dab',
        ];
        $palette = [];
        foreach ($paletteHex as $hex) {
            $palette[] = imagecolorallocate(
                $img,
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2))
            );
        }

        $ttf = self::ttfPath();
        self::ttf($img, 16, 24, 30, $text, $ttf, '班组成长趋势');

        $left = 56;
        $right = $w - $legendW - 28;
        $top = 56;
        $bottom = $h - $labelPad;
        $plotW = $right - $left;
        $plotH = $bottom - $top;

        // 右侧图例面板
        $panelX = $right + 16;
        imagefilledrectangle($img, $panelX, $top - 8, $w - 16, $bottom + 8, $panel);
        imagerectangle($img, $panelX, $top - 8, $w - 16, $bottom + 8, $border);
        self::ttf($img, 12, $panelX + 12, $top + 10, $text, $ttf, '图例（共 ' . $seriesCnt . ' 个）');

        // 坐标轴与网格（0~100）
        for ($g = 0; $g <= 5; $g++) {
            $y = $bottom - (int)round($plotH * $g / 5);
            imageline($img, $left, $y, $right, $y, $grid);
            $label = (string)($g * 20);
            self::ttf($img, 10, $left - 34, $y + 4, $muted, $ttf, $label);
        }
        imageline($img, $left, $top, $left, $bottom, $axis);
        imageline($img, $left, $bottom, $right, $bottom, $axis);
        self::ttf($img, 11, 14, $top + 10, $muted, $ttf, '得分');

        $n = count($batches);
        if ($n < 1 || empty($series)) {
            self::ttf($img, 14, $left + 160, (int)(($top + $bottom) / 2), $muted, $ttf, '暂无趋势数据');
            $path = self::tempPngPath('trend');
            imagepng($img, $path);
            imagedestroy($img);
            return $path;
        }

        $xs = [];
        $perLine = $perLinePreview;
        for ($i = 0; $i < $n; $i++) {
            // boundaryGap：点落在各等分中心
            $xs[$i] = $left + (int)round($plotW * ($i + 0.5) / $n);
            $name = isset($batches[$i]['batch_name']) ? $batches[$i]['batch_name'] : ('批次' . ($i + 1));
            // 序号 + 完整批次名（按字换行，不截断）
            $idxLabel = (string)($i + 1);
            $twIdx = self::textWidth($idxLabel, 10, $ttf);
            self::ttf($img, 10, (int)($xs[$i] - $twIdx / 2), $bottom + 14, $muted, $ttf, $idxLabel);

            $lines = [];
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                $len = mb_strlen($name, 'UTF-8');
                for ($p = 0; $p < $len; $p += $perLine) {
                    $lines[] = mb_substr($name, $p, $perLine, 'UTF-8');
                }
            } else {
                $lines[] = $name;
            }
            $ly = $bottom + 28;
            foreach ($lines as $line) {
                if ($ly > $h - 6) {
                    break;
                }
                $tw = self::textWidth($line, 10, $ttf);
                self::ttf($img, 10, (int)($xs[$i] - $tw / 2), $ly, $text, $ttf, $line);
                $ly += 14;
            }
        }

        foreach ($series as $si => $s) {
            $color = $palette[$si % count($palette)];
            $scores = isset($s['scores']) ? $s['scores'] : [];
            $points = [];
            for ($i = 0; $i < $n; $i++) {
                if (!array_key_exists($i, $scores) || $scores[$i] === null || $scores[$i] === '') {
                    $points[$i] = null;
                    continue;
                }
                $val = max(0, min(100, (float)$scores[$i]));
                $y = $bottom - (int)round($plotH * $val / 100);
                $points[$i] = [$xs[$i], $y];
            }
            $prev = null;
            foreach ($points as $pt) {
                if ($pt === null) {
                    $prev = null;
                    continue;
                }
                if ($prev !== null) {
                    imageline($img, $prev[0], $prev[1], $pt[0], $pt[1], $color);
                    imageline($img, $prev[0], $prev[1] - 1, $pt[0], $pt[1] - 1, $color);
                }
                imagefilledellipse($img, $pt[0], $pt[1], 8, 8, $color);
                $prev = $pt;
            }
        }

        // 右侧图例列表（完整班组名，超出画布则截断并提示）
        $ly = $top + 28;
        $maxLegend = (int)floor(($bottom - $ly) / $rowH);
        $shown = 0;
        foreach ($series as $si => $s) {
            if ($shown >= $maxLegend) {
                $more = $seriesCnt - $shown;
                self::ttf($img, 10, $panelX + 12, $ly + 12, $muted, $ttf, '…另有 ' . $more . ' 个班组未显示');
                break;
            }
            $color = $palette[$si % count($palette)];
            $name = isset($s['team_name']) ? $s['team_name'] : ('班组' . ($si + 1));
            if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 14) {
                $name = mb_substr($name, 0, 14, 'UTF-8') . '…';
            }
            imagefilledellipse($img, $panelX + 18, $ly + 6, 10, 10, $color);
            self::ttf($img, 11, $panelX + 30, $ly + 10, $text, $ttf, $name);
            $ly += $rowH;
            $shown++;
        }

        $path = self::tempPngPath('trend');
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    /**
     * 排行横向柱状图（可选复用）。
     *
     * @param array $list [['team_name'=>'','team_score'=>0], ...] 已按排名
     * @param int   $limit
     * @return string
     */
    public static function renderRankBars(array $list, $limit = 12, $title = '班组得分排行')
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('PHP GD 扩展未启用');
        }
        $list = array_slice($list, 0, $limit);
        $n = count($list);
        $rowH = 36;
        $w = 920;
        $h = max(220, 80 + $n * $rowH + 40);
        $img = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($img, 255, 255, 255);
        $bar = imagecolorallocate($img, 22, 119, 255);
        $text = imagecolorallocate($img, 51, 65, 85);
        $muted = imagecolorallocate($img, 100, 116, 139);
        $track = imagecolorallocate($img, 241, 245, 249);
        imagefilledrectangle($img, 0, 0, $w, $h, $white);
        $ttf = self::ttfPath();
        self::ttf($img, 16, 24, 30, $text, $ttf, (string)$title);

        $left = 180;
        $right = $w - 70;
        $plotW = $right - $left;
        $y0 = 60;
        foreach ($list as $i => $r) {
            $y = $y0 + $i * $rowH;
            $name = isset($r['team_name']) ? $r['team_name'] : '';
            if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 10) {
                $name = mb_substr($name, 0, 10, 'UTF-8') . '…';
            }
            self::ttf($img, 11, 20, $y + 18, $text, $ttf, $name);
            imagefilledrectangle($img, $left, $y + 4, $right, $y + 22, $track);
            $score = isset($r['team_score']) ? (float)$r['team_score'] : 0;
            $bw = (int)round($plotW * max(0, min(100, $score)) / 100);
            if ($bw > 0) {
                imagefilledrectangle($img, $left, $y + 4, $left + $bw, $y + 22, $bar);
            }
            self::ttf($img, 11, $left + $bw + 8, $y + 18, $muted, $ttf, number_format($score, 1, '.', ''));
        }

        $path = self::tempPngPath('rank');
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    /**
     * GD 扇形：0°=3 点钟，顺时针。传入角度可为负（-90 即 12 点）。
     *
     * @param resource|\GdImage $img
     * @param int               $cx
     * @param int               $cy
     * @param int               $w
     * @param int               $h
     * @param float             $startDeg
     * @param float             $endDeg
     * @param int               $color
     * @return void
     */
    private static function filledArc($img, $cx, $cy, $w, $h, $startDeg, $endDeg, $color)
    {
        if ($endDeg - $startDeg < 0.5) {
            return;
        }
        // 跨过整圆时分段，避免起止角异常
        $a0 = $startDeg;
        $a1 = $endDeg;
        if ($a1 - $a0 >= 359.9) {
            imagefilledellipse($img, $cx, $cy, $w, $h, $color);
            return;
        }
        imagefilledarc($img, $cx, $cy, $w, $h, (int)round($a0), (int)round($a1), $color, IMG_ARC_PIE);
    }

    /**
     * @param resource|\GdImage $img
     * @param float             $size
     * @param int               $x
     * @param int               $y
     * @param int               $color
     * @param string            $ttf
     * @param string            $str
     * @return void
     */
    private static function ttf($img, $size, $x, $y, $color, $ttf, $str)
    {
        $str = (string)$str;
        if ($str === '') {
            return;
        }
        // imagettftext 的 y 是基线
        imagettftext($img, $size, 0, (int)$x, (int)$y, $color, $ttf, $str);
    }

    /**
     * @param string $str
     * @param float  $size
     * @param string $ttf
     * @return int
     */
    private static function textWidth($str, $size, $ttf)
    {
        $box = @imagettfbbox($size, 0, $ttf, $str);
        if (!$box) {
            return 80;
        }
        return (int)abs($box[2] - $box[0]) + 16;
    }
}

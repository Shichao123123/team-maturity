<?php
namespace app\index\service;

/**
 * 基于 TCPDF 的中文 PDF 报告辅助（下载流）。
 *
 * 关键点：使用系统 TrueType 中文字体并完整嵌入，避免子集/CID 轻量字体导致缺字、方框。
 */
class PdfHelper
{
    /** @var string|null 已解析的中文字体名（TCPDF SetFont 用） */
    private static $fontName;

    /**
     * 确保 TCPDF 可用。
     *
     * @return void
     */
    public static function ensureAutoload()
    {
        if (!class_exists('\\TCPDF')) {
            $autoload = ROOT_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
        if (!class_exists('\\TCPDF')) {
            throw new \RuntimeException('TCPDF 未安装，请在 backend 目录执行 composer require tecnickcom/tcpdf');
        }
        if (!class_exists(__NAMESPACE__ . '\\TmReportPdf', false)) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'TmReportPdf.php';
        }
    }

    /**
     * TCPDF fonts 目录。
     *
     * @return string
     */
    private static function tcpdfFontDir()
    {
        if (defined('K_PATH_FONTS')) {
            return K_PATH_FONTS;
        }
        return ROOT_PATH . 'vendor' . DIRECTORY_SEPARATOR . 'tecnickcom'
            . DIRECTORY_SEPARATOR . 'tcpdf' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR;
    }

    /**
     * 项目内字体缓存目录（转换后的 php/z 可落在 TCPDF fonts，源 TTF 备份在此）。
     *
     * @return string
     */
    private static function projectFontDir()
    {
        $dir = ROOT_PATH . 'extend' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 解析并缓存中文字体名。
     * 优先已转换的完整 Unicode TTF（simhei/msyh），禁止默认落到缺字严重的 stsongstdlight。
     *
     * @return string TCPDF 字体名
     */
    public static function chineseFont()
    {
        if (self::$fontName !== null) {
            return self::$fontName;
        }
        self::ensureAutoload();

        $metaDir = ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR . 'pdf-fonts';
        if (!is_dir($metaDir)) {
            @mkdir($metaDir, 0755, true);
        }
        $metaFile = $metaDir . DIRECTORY_SEPARATOR . 'font-name.txt';
        $fontDir = self::tcpdfFontDir();

        // 备份系统黑体到项目目录，便于其他环境重新转换
        $projectSimhei = self::projectFontDir() . 'simhei.ttf';
        $winSimhei = 'C:\\Windows\\Fonts\\simhei.ttf';
        if (!is_file($projectSimhei) && is_file($winSimhei)) {
            @copy($winSimhei, $projectSimhei);
        }

        // 已转换的完整字体优先（不再信任历史缓存里的 stsongstdlight）
        $preferred = ['simhei', 'msyh', 'simsun', 'simkai', 'wqy_microhei', 'notosanscjksc'];
        foreach ($preferred as $name) {
            if (is_file($fontDir . $name . '.php') && is_file($fontDir . $name . '.z')) {
                self::$fontName = $name;
                @file_put_contents($metaFile, $name);
                return self::$fontName;
            }
        }

        if (is_file($metaFile)) {
            $cached = strtolower(trim((string)@file_get_contents($metaFile)));
            if ($cached !== '' && $cached !== 'stsongstdlight'
                && is_file($fontDir . $cached . '.php')
                && is_file($fontDir . $cached . '.z')) {
                self::$fontName = $cached;
                return self::$fontName;
            }
        }

        $candidates = [
            self::projectFontDir() . 'simhei.ttf',
            self::projectFontDir() . 'msyh.ttf',
            'C:\\Windows\\Fonts\\simhei.ttf',
            'C:\\Windows\\Fonts\\simkai.ttf',
            'C:\\Windows\\Fonts\\msyh.ttc',
            'C:\\Windows\\Fonts\\simsun.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            try {
                // flags=32：生成 Unicode 字体定义；具体子集/全量由 setFontSubsetting 控制
                $name = \TCPDF_FONTS::addTTFfont($path, 'TrueTypeUnicode', '', 32);
                if ($name) {
                    $name = strtolower($name);
                    // addTTFfont 可能返回内部名 SimHei，文件实际为 simhei.php
                    if (!is_file($fontDir . $name . '.php')) {
                        $alt = strtolower(preg_replace('/[^a-z0-9_]/i', '', $name));
                        if ($alt && is_file($fontDir . $alt . '.php')) {
                            $name = $alt;
                        } elseif (is_file($fontDir . 'simhei.php')) {
                            $name = 'simhei';
                        }
                    }
                    if (is_file($fontDir . $name . '.php')) {
                        @file_put_contents($metaFile, $name);
                        self::$fontName = $name;
                        return self::$fontName;
                    }
                }
            } catch (\Exception $e) {
                // try next
            } catch (\Throwable $e) {
                // try next
            }
        }

        // 最后兜底：CID 字体（依赖阅读器字体，可能缺字，仅作最后手段）
        self::$fontName = 'cid0cs';
        @file_put_contents($metaFile, self::$fontName);
        return self::$fontName;
    }

    /**
     * 统一设置正文字体（中文 TTF 不使用 B/I，避免伪粗体缺画、乱码）。
     *
     * @param \TCPDF $pdf
     * @param int    $size
     * @return void
     */
    public static function applyFont($pdf, $size = 10)
    {
        $font = self::chineseFont();
        $pdf->SetFont($font, '', (float)$size);
    }

    /**
     * 创建带中文页眉页脚的报告文档。
     *
     * @param string $reportTitle 报告类型标题
     * @param string $subTitle    副标题（如批次名）
     * @return TmReportPdf
     */
    public static function create($reportTitle, $subTitle = '')
    {
        self::ensureAutoload();
        $font = self::chineseFont();

        $pdf = new TmReportPdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->tmFont = $font;
        $pdf->tmExportedAt = date('Y-m-d H:i');
        $headerLine = '班组安全成熟度测评系统 · ' . $reportTitle;
        if ($subTitle !== '') {
            $headerLine .= ' — ' . $subTitle;
        }
        $pdf->tmHeaderLine = $headerLine;

        $pdf->SetCreator('班组安全成熟度测评系统');
        $pdf->SetAuthor('班组安全成熟度测评系统');
        $pdf->SetTitle($reportTitle);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->SetHeaderMargin(8);
        $pdf->SetFooterMargin(10);
        $pdf->SetMargins(12, 22, 12);
        $pdf->SetAutoPageBreak(true, 16);

        // 完整嵌入中文字体，避免子集裁剪导致个别汉字空白/方框
        if (method_exists($pdf, 'setFontSubsetting')) {
            $pdf->setFontSubsetting(false);
        }

        $pdf->AddPage();
        self::applyFont($pdf, 16);
        $pdf->Cell(0, 10, $reportTitle, 0, 1, 'C');
        if ($subTitle !== '') {
            self::applyFont($pdf, 11);
            $pdf->Cell(0, 7, $subTitle, 0, 1, 'C');
        }
        $pdf->Ln(2);
        self::applyFont($pdf, 10);
        return $pdf;
    }

    /**
     * 写入一段键值说明。
     *
     * @param \TCPDF $pdf
     * @param array  $pairs [['label'=>'', 'value'=>''], ...]
     * @return void
     */
    public static function writeMeta($pdf, array $pairs)
    {
        self::applyFont($pdf, 10);
        foreach ($pairs as $pair) {
            $label = isset($pair['label']) ? $pair['label'] : '';
            $value = isset($pair['value']) ? (string)$pair['value'] : '';
            if ($value === '') {
                $value = '—';
            }
            $pdf->MultiCell(0, 6, $label . '：' . $value, 0, 'L', false, 1);
        }
        $pdf->Ln(2);
    }

    /**
     * 写入小节标题。
     *
     * @param \TCPDF $pdf
     * @param string $title
     * @return void
     */
    public static function writeSection($pdf, $title)
    {
        $pdf->Ln(1);
        self::applyFont($pdf, 12);
        $pdf->Cell(0, 8, $title, 0, 1, 'L');
        self::applyFont($pdf, 9);
    }

    /**
     * 写入正文段落。
     *
     * @param \TCPDF $pdf
     * @param string $text
     * @return void
     */
    public static function writeParagraph($pdf, $text)
    {
        self::applyFont($pdf, 9);
        $pdf->MultiCell(0, 5.5, $text === '' ? '—' : $text, 0, 'L', false, 1);
    }

    /**
     * 将 PNG/JPG 图表写入 PDF（宽度单位 mm，默认铺满内容区）。
     *
     * @param \TCPDF $pdf
     * @param string $imagePath
     * @param float  $widthMm
     * @return void
     */
    public static function writeImage($pdf, $imagePath, $widthMm = 186)
    {
        if (!$imagePath || !is_file($imagePath)) {
            return;
        }
        $pdf->Ln(1);
        $pdf->Image($imagePath, '', '', $widthMm, 0, '', '', 'N', false, 300, '', false, false, 0, false, false, false);
        $pdf->Ln(2);
    }

    /**
     * 删除临时图表文件。
     *
     * @param string|array $paths
     * @return void
     */
    public static function cleanupImages($paths)
    {
        foreach ((array)$paths as $p) {
            if ($p && is_file($p)) {
                @unlink($p);
            }
        }
    }

    /**
     * 输出简易表格（HTML table，强制中文字体）。
     *
     * @param \TCPDF $pdf
     * @param array  $headers 列标题
     * @param array  $rows    二维数组，按列顺序
     * @param array  $widths  列宽百分比，总和约 100；空则均分
     * @return void
     */
    public static function writeTable($pdf, array $headers, array $rows, array $widths = [])
    {
        $font = self::chineseFont();
        $colCnt = count($headers);
        if ($colCnt < 1) {
            return;
        }
        if (count($widths) !== $colCnt) {
            $w = round(100 / $colCnt, 2);
            $widths = array_fill(0, $colCnt, $w);
        }

        // 不用 <b>：中文 TTF 无独立 Bold 文件时 TCPDF 易掉字
        $html = '<table border="1" cellpadding="4" cellspacing="0" width="100%" '
            . 'style="font-family:' . self::esc($font) . '; font-size:8pt;">';
        $html .= '<thead><tr style="background-color:#f1f5f9;">';
        foreach ($headers as $i => $h) {
            $html .= '<th width="' . $widths[$i] . '%" align="center" '
                . 'style="font-family:' . self::esc($font) . ';">'
                . self::esc($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if (!$rows) {
            $html .= '<tr><td colspan="' . $colCnt . '" align="center" '
                . 'style="font-family:' . self::esc($font) . ';">暂无数据</td></tr>';
        } else {
            foreach ($rows as $row) {
                $html .= '<tr>';
                for ($i = 0; $i < $colCnt; $i++) {
                    $val = isset($row[$i]) ? $row[$i] : '';
                    if ($val === null || $val === '') {
                        $val = '—';
                    }
                    $align = ($i === 0 || is_numeric($val)) ? 'center' : 'left';
                    $html .= '<td width="' . $widths[$i] . '%" align="' . $align . '" '
                        . 'style="font-family:' . self::esc($font) . ';">'
                        . self::esc((string)$val) . '</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table>';

        self::applyFont($pdf, 8);
        $pdf->writeHTML($html, true, false, true, false, '');
        self::applyFont($pdf, 9);
    }

    /**
     * @param string $s
     * @return string
     */
    public static function esc($s)
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 安全文件名。
     *
     * @param string $name
     * @return string
     */
    public static function safeFilename($name)
    {
        $name = preg_replace('/[\\\\\/:*?"<>|]+/', '_', (string)$name);
        $name = trim($name);
        return $name !== '' ? $name : 'export';
    }

    /**
     * 格式化分数展示。
     *
     * @param mixed $v
     * @return string
     */
    public static function fmtScore($v)
    {
        if ($v === null || $v === '') {
            return '—';
        }
        return number_format((float)$v, 1, '.', '');
    }

    /**
     * 截断过长文本。
     *
     * @param string $text
     * @param int    $max
     * @return string
     */
    public static function clip($text, $max = 48)
    {
        $text = (string)$text;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $max) {
                return mb_substr($text, 0, $max, 'UTF-8') . '…';
            }
            return $text;
        }
        if (strlen($text) > $max) {
            return substr($text, 0, $max) . '...';
        }
        return $text;
    }

    /**
     * 输出 PDF 并触发下载（调用后 exit）。
     *
     * @param \TCPDF $pdf
     * @param string $filename 含 .pdf 后缀
     * @return void
     */
    public static function download($pdf, $filename)
    {
        $filename = self::safeFilename($filename);
        if (!preg_match('/\.pdf$/i', $filename)) {
            $filename .= '.pdf';
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $pdf->Output($filename, 'D');
        exit;
    }
}

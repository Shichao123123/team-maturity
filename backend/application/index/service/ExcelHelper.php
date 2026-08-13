<?php
namespace app\index\service;

/**
 * 简易 Excel 读写（基于 PHPExcel，支持 xls/xlsx/csv）
 */
class ExcelHelper
{
    /**
     * 确保 PHPExcel 类已加载（按需 require vendor 入口）。
     *
     * @return void
     */
    public static function ensureAutoload()
    {
        if (!class_exists('\\PHPExcel')) {
            require_once ROOT_PATH . 'vendor/phpoffice/phpexcel/Classes/PHPExcel.php';
        }
    }

    /**
     * 读取 Excel/CSV 首 sheet，按首行表头解析为关联数组行。
     *
     * @param string $filePath 本地文件路径（xls/xlsx/csv）
     * @return array [array headers, array rows] rows 为以表头为键的关联数组列表；空文件返回 [[], []]
     */
    public static function readAssoc($filePath)
    {
        self::ensureAutoload();
        // PHPExcel 已停维；解析前关闭外部实体，降低 xlsx/XML XXE 风险
        $prevEntityLoader = null;
        if (function_exists('libxml_disable_entity_loader')) {
            $prevEntityLoader = libxml_disable_entity_loader(true);
        }
        $prevLibxml = libxml_use_internal_errors(true);
        try {
            $excel = \PHPExcel_IOFactory::load($filePath);
        } finally {
            libxml_use_internal_errors($prevLibxml);
            if (function_exists('libxml_disable_entity_loader') && $prevEntityLoader !== null) {
                libxml_disable_entity_loader($prevEntityLoader);
            }
        }
        $sheet = $excel->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);
        if (empty($data)) {
            return [[], []];
        }

        $headers = [];
        foreach ($data[0] as $i => $h) {
            $headers[$i] = trim((string)$h);
        }

        $rows = [];
        for ($r = 1; $r < count($data); $r++) {
            $line = $data[$r];
            $empty = true;
            $row = [];
            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $val = isset($line[$i]) ? trim((string)$line[$i]) : '';
                if ($val !== '') {
                    $empty = false;
                }
                $row[$key] = $val;
            }
            if (!$empty) {
                $rows[] = $row;
            }
        }
        return [$headers, $rows];
    }

    /**
     * 生成 Excel 并通过 HTTP 响应直接触发浏览器下载（调用后 exit）。
     *
     * @param string $filename 下载文件名（含扩展名）
     * @param array  $headers  表头列名列表（有序）
     * @param array  $rows     数据行；每项为以表头为键的关联数组或按列索引的数组
     * @return void
     */
    public static function download($filename, array $headers, array $rows = [])
    {
        // 使用 CSV（带 BOM），避免 PHPExcel Excel5/OLE 在 PHP7.3+ 的 continue 告警被框架打成 500
        $filename = preg_replace('/\.(xls|xlsx)$/i', '.csv', (string)$filename);
        if (!preg_match('/\.csv$/i', $filename)) {
            $filename .= '.csv';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        // Excel 打开 UTF-8 CSV 需要 BOM
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $c => $h) {
                $line[] = isset($row[$h]) ? $row[$h] : (isset($row[$c]) ? $row[$c] : '');
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    /**
     * 将表头与数据行写入 xls 文件并保存到磁盘。
     *
     * @param string $filePath 目标文件完整路径（目录不存在时自动创建）
     * @param array  $headers  表头列名列表
     * @param array  $rows     数据行
     * @return string 保存后的文件路径（与 $filePath 相同）
     */
    public static function saveXls($filePath, array $headers, array $rows = [])
    {
        self::ensureAutoload();
        $excel = new \PHPExcel();
        $sheet = $excel->getActiveSheet();
        foreach ($headers as $c => $h) {
            $sheet->setCellValueByColumnAndRow($c, 1, $h);
        }
        foreach ($rows as $r => $row) {
            foreach ($headers as $c => $h) {
                $val = isset($row[$h]) ? $row[$h] : (isset($row[$c]) ? $row[$c] : '');
                $sheet->setCellValueByColumnAndRow($c, $r + 2, $val);
            }
        }
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $writer = \PHPExcel_IOFactory::createWriter($excel, 'Excel5');
        $writer->save($filePath);
        return $filePath;
    }
}

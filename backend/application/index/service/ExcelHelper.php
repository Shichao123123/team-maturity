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
        $excel = \PHPExcel_IOFactory::load($filePath);
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
        self::ensureAutoload();
        $excel = new \PHPExcel();
        $sheet = $excel->getActiveSheet();
        $sheet->setTitle('Sheet1');

        foreach ($headers as $c => $h) {
            $sheet->setCellValueByColumnAndRow($c, 1, $h);
            $sheet->getStyleByColumnAndRow($c, 1)->getFont()->setBold(true);
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
        foreach ($rows as $r => $row) {
            foreach ($headers as $c => $h) {
                $val = isset($row[$h]) ? $row[$h] : (isset($row[$c]) ? $row[$c] : '');
                $sheet->setCellValueByColumnAndRow($c, $r + 2, $val);
            }
        }

        // 清理缓冲，避免损坏文件
        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = \PHPExcel_IOFactory::createWriter($excel, 'Excel5');
        $writer->save('php://output');
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

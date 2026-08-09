<?php
namespace app\index\service;

/**
 * 简易 Excel 读写（基于 PHPExcel，支持 xls/xlsx/csv）
 */
class ExcelHelper
{
    public static function ensureAutoload()
    {
        if (!class_exists('\\PHPExcel')) {
            require_once ROOT_PATH . 'vendor/phpoffice/phpexcel/Classes/PHPExcel.php';
        }
    }

    /**
     * @return array [headers, rows] rows 为关联数组（按表头）
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
     * 生成并直接输出下载
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
     * 保存为 xls 文件
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

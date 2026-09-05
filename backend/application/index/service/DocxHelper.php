<?php
namespace app\index\service;

/**
 * 轻量 OOXML（.docx）报告生成：标题、元信息、段落、表格、PNG 图表，浏览器下载。
 * 不依赖 PhpWord，仅需 ZipArchive + GD 生成的 PNG。
 */
class DocxHelper
{
    /** @var string */
    private $body = '';

    /** @var array [['path'=>'', 'name'=>'', 'rid'=>int], ...] */
    private $images = [];

    /** @var int */
    private $nextRid = 1;

    /**
     * @param string $title
     * @param string $subtitle
     * @return self
     */
    public static function create($title, $subtitle = '')
    {
        $doc = new self();
        $doc->body .= self::p((string)$title, 'Title');
        if ($subtitle !== '' && $subtitle !== null) {
            $doc->body .= self::p((string)$subtitle, 'Subtitle');
        }
        return $doc;
    }

    /**
     * @param array $pairs [['label'=>'', 'value'=>''], ...]
     * @return self
     */
    public function writeMeta(array $pairs)
    {
        $rows = [['项目', '内容']];
        foreach ($pairs as $pair) {
            $label = isset($pair['label']) ? (string)$pair['label'] : '';
            $value = isset($pair['value']) ? (string)$pair['value'] : '';
            if ($value === '') {
                $value = '—';
            }
            $rows[] = [$label, $value];
        }
        $this->body .= self::tableXml($rows, true);
        return $this;
    }

    /**
     * @param string $title
     * @return self
     */
    public function writeSection($title)
    {
        $this->body .= self::p((string)$title, 'H2');
        return $this;
    }

    /**
     * @param string $text
     * @return self
     */
    public function writeParagraph($text)
    {
        $this->body .= self::p($text === '' || $text === null ? '—' : (string)$text, 'Normal');
        return $this;
    }

    /**
     * 嵌入 PNG/JPG 图表（按内容区宽度等比缩放）。
     *
     * @param string $imagePath
     * @param int    $displayWEmu 显示宽度 EMU（默认约 6 英寸）
     * @param string $name
     * @return self
     */
    public function writeImage($imagePath, $displayWEmu = 5486400, $name = 'chart')
    {
        if (!$imagePath || !is_file($imagePath)) {
            return $this;
        }
        $info = @getimagesize($imagePath);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return $this;
        }
        $rid = $this->nextRid++;
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'png';
        }
        $mediaName = 'image' . $rid . '.' . $ext;
        $this->images[] = [
            'path' => $imagePath,
            'name' => $mediaName,
            'rid'  => $rid,
        ];
        $this->body .= self::drawingXml($rid, (int)$info[0], (int)$info[1], (int)$displayWEmu, $name);
        return $this;
    }

    /**
     * @param array $headers
     * @param array $rows 二维数组，按列顺序
     * @return self
     */
    public function writeTable(array $headers, array $rows)
    {
        $all = [$headers];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $cell) {
                if ($cell === null || $cell === '') {
                    $line[] = '—';
                } else {
                    $line[] = (string)$cell;
                }
            }
            $all[] = $line;
        }
        $this->body .= self::tableXml($all, true);
        return $this;
    }

    /**
     * 输出下载并结束请求。
     *
     * @param string $filename
     * @return void
     */
    public function download($filename)
    {
        $filename = self::safeFilename($filename);
        if (!preg_match('/\.docx$/i', $filename)) {
            $filename .= '.docx';
        }
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive 扩展未启用，无法生成 Word');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tm_docx_');
        if ($tmp === false) {
            throw new \RuntimeException('无法创建临时文件');
        }
        $docxPath = $tmp . '.docx';
        @unlink($tmp);

        $relsItems = '';
        foreach ($this->images as $img) {
            $relsItems .= '<Relationship Id="rId' . (int)$img['rid'] . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
                . ' Target="media/' . self::xml($img['name']) . '"/>';
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:body>' . $this->body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr>'
            . '</w:body></w:document>';

        $defaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Default Extension="jpg" ContentType="image/jpeg"/>'
            . '<Default Extension="jpeg" ContentType="image/jpeg"/>';
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . $defaults
            . '<Override PartName="/word/document.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="word/document.xml"/>'
            . '</Relationships>';

        $wordRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relsItems
            . '</Relationships>';

        $zip = new \ZipArchive();
        if ($zip->open($docxPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建 Word 文件');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $wordRels);
        foreach ($this->images as $img) {
            $zip->addFile($img['path'], 'word/media/' . $img['name']);
        }
        $zip->close();
        foreach ($this->images as $img) {
            if (is_file($img['path'])) {
                @unlink($img['path']);
            }
        }

        if (ob_get_level()) {
            @ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($docxPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($docxPath);
        @unlink($docxPath);
        exit;
    }

    /**
     * @param string|array $paths
     * @return void
     */
    public static function cleanupImages($paths)
    {
        PdfHelper::cleanupImages($paths);
    }

    /**
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
     * @param mixed $s
     * @return string
     */
    private static function xml($s)
    {
        return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param string $text
     * @param string $style
     * @return string
     */
    private static function p($text, $style = 'Normal')
    {
        $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="21"/><w:szCs w:val="21"/></w:rPr>';
        $pPr = '<w:pPr><w:spacing w:after="80" w:line="360" w:lineRule="auto"/></w:pPr>';
        if ($style === 'Title') {
            $pPr = '<w:pPr><w:jc w:val="center"/><w:spacing w:before="80" w:after="120"/></w:pPr>';
            $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr>';
        } elseif ($style === 'Subtitle') {
            $pPr = '<w:pPr><w:jc w:val="center"/><w:spacing w:after="160"/></w:pPr>';
            $rPr = '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:color w:val="666666"/></w:rPr>';
        } elseif ($style === 'H2') {
            $pPr = '<w:pPr><w:spacing w:before="200" w:after="80"/></w:pPr>';
            $rPr = '<w:rPr><w:b/><w:rFonts w:ascii="黑体" w:hAnsi="黑体" w:eastAsia="黑体"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr>';
        }
        return '<w:p>' . $pPr . '<w:r>' . $rPr . '<w:t xml:space="preserve">' . self::xml($text) . '</w:t></w:r></w:p>';
    }

    /**
     * @param array $rows
     * @param bool  $header
     * @return string
     */
    private static function tableXml(array $rows, $header = true)
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="999999"/>'
            . '<w:left w:val="single" w:sz="4" w:color="999999"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="999999"/>'
            . '<w:right w:val="single" w:sz="4" w:color="999999"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '</w:tblBorders></w:tblPr>';
        foreach ($rows as $i => $row) {
            $bold = ($header && $i === 0);
            $xml .= '<w:tr>';
            foreach ($row as $cell) {
                $rPr = $bold
                    ? '<w:rPr><w:b/><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="20"/></w:rPr>'
                    : '<w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="19"/></w:rPr>';
                $shd = ($header && $i === 0)
                    ? '<w:tcPr><w:shd w:val="clear" w:fill="D9E8FB"/></w:tcPr>'
                    : '<w:tcPr/>';
                $xml .= '<w:tc>' . $shd . '<w:p><w:r>' . $rPr
                    . '<w:t xml:space="preserve">' . self::xml($cell) . '</w:t></w:r></w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl><w:p><w:r><w:t></w:t></w:r></w:p>';
        return $xml;
    }

    /**
     * @param int    $rid
     * @param int    $pxW
     * @param int    $pxH
     * @param int    $displayWEmu
     * @param string $name
     * @return string
     */
    private static function drawingXml($rid, $pxW, $pxH, $displayWEmu, $name)
    {
        $cx = (int)$displayWEmu;
        $cy = (int)round($displayWEmu * $pxH / max(1, $pxW));
        // 过高时压到约半页
        $maxH = 7200000;
        if ($cy > $maxH) {
            $cx = (int)round($cx * $maxH / $cy);
            $cy = $maxH;
        }
        return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:docPr id="' . $rid . '" name="' . self::xml($name) . '"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $rid . '" name="' . self::xml($name) . '"/>'
            . '<pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="rId' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }
}

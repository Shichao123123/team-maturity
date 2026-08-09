<?php
namespace app\index\service;

if (!class_exists('\\TCPDF', false)) {
    $autoload = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

/**
 * TCPDF 报告页：自定义中文页眉页脚。
 */
class TmReportPdf extends \TCPDF
{
    /** @var string */
    public $tmHeaderLine = '';
    /** @var string */
    public $tmExportedAt = '';
    /** @var string */
    public $tmFont = 'stsongstdlight';

    public function Header()
    {
        // 中文 TTF 勿用 B/I，避免伪粗体缺画
        $this->SetFont($this->tmFont, '', 9);
        if (method_exists($this, 'setFontSubsetting')) {
            $this->setFontSubsetting(false);
        }
        $this->SetY(8);
        $this->Cell(0, 6, $this->tmHeaderLine, 0, 1, 'L');
        $this->SetDrawColor(200, 200, 200);
        $this->Line(12, 15, $this->getPageWidth() - 12, 15);
    }

    public function Footer()
    {
        $this->SetY(-12);
        $this->SetFont($this->tmFont, '', 8);
        if (method_exists($this, 'setFontSubsetting')) {
            $this->setFontSubsetting(false);
        }
        $this->SetDrawColor(200, 200, 200);
        $this->Line(12, $this->GetY() - 2, $this->getPageWidth() - 12, $this->GetY() - 2);
        $this->Cell(
            0,
            8,
            '导出时间 ' . $this->tmExportedAt . '    第 ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages() . ' 页',
            0,
            0,
            'C'
        );
    }
}

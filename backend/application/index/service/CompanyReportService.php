<?php
namespace app\index\service;

/** Vector charts retain sharp labels and the dashboard palette in downloaded PDFs. */
class CompanyReportService
{
    private static function room($pdf, $height)
    {
        if ($pdf->GetY() + $height > $pdf->getPageHeight() - 18) $pdf->AddPage();
    }

    private static function section($pdf, $title)
    {
        self::room($pdf, 26);
        $pdf->Ln(3);
        $pdf->SetTextColor(104, 229, 255);
        PdfHelper::writeSection($pdf, $title);
        $pdf->SetTextColor(218, 237, 255);
    }

    private static function paragraphs($pdf, array $items, $empty)
    {
        if (!$items) $items = [$empty];
        foreach ($items as $item) PdfHelper::writeParagraph($pdf, (string)$item);
    }

    private static function bars($pdf, array $rows, $max = 100, $unit = '%', $color = [37, 211, 245])
    {
        if (!$rows) { PdfHelper::writeParagraph($pdf, '当前范围暂无可统计数据'); return; }
        foreach ($rows as $row) {
            PdfHelper::applyFont($pdf, 9);
            $height = max(13, $pdf->getStringHeight(65, $row[0]) + 4);
            self::room($pdf, $height);
            $y = $pdf->GetY();
            $pdf->SetFillColor(7, 39, 64);
            $pdf->Rect(12, $y, 186, $height - 1, 'F');
            $pdf->SetXY(15, $y + 2);
            $pdf->MultiCell(65, 5, $row[0], 0, 'L');
            $pdf->SetFillColor(24, 64, 90);
            $pdf->Rect(84, $y + 4, 88, 4, 'F');
            if ($row[1] !== null) {
                $c = isset($row[2]) ? $row[2] : $color;
                $pdf->SetFillColor($c[0], $c[1], $c[2]);
                $width = 88 * max(0, min(1, (float)$row[1] / max(1, $max)));
                if ($width > 0) $pdf->Rect(84, $y + 4, $width, 4, 'F');
            }
            $pdf->SetXY(173, $y + 2);
            $pdf->Cell(24, 7, $row[1] === null ? '—' : $row[1] . $unit, 0, 0, 'R');
            $pdf->SetXY(12, $y + $height);
        }
    }

    private static function trend($pdf, array $history)
    {
        $points = array_values(array_filter($history, function($h){return isset($h['team_score']) && is_numeric($h['team_score']);}));
        usort($points, function($a,$b){return strcmp($a['scored_at'], $b['scored_at']);});
        if (!$points) { PdfHelper::writeParagraph($pdf, '暂无有效历史得分'); return; }
        self::room($pdf, 60);
        $top = $pdf->GetY() + 4;
        $pdf->SetDrawColor(31, 79, 110);
        PdfHelper::applyFont($pdf, 8);
        for ($v = 0; $v <= 100; $v += 25) {
            $y = $top + 38 * (1 - $v / 100);
            $pdf->Line(25, $y, 192, $y);
            $pdf->SetXY(12, $y - 2); $pdf->Cell(11, 4, (string)$v, 0, 0, 'R');
        }
        $previous = null;
        foreach ($points as $i => $point) {
            $x = count($points) > 1 ? 28 + 160 * $i / (count($points) - 1) : 108;
            $y = $top + 38 * (1 - max(0, min(100, $point['team_score'])) / 100);
            $pdf->SetDrawColor(37, 211, 245); $pdf->SetFillColor(37, 211, 245);
            if ($previous) $pdf->Line($previous[0], $previous[1], $x, $y);
            $pdf->Circle($x, $y, 0.8, 0, 360, 'F');
            if (count($points) <= 8 || $i === 0 || $i === count($points) - 1) {
                $pdf->SetXY($x - 10, $y - 6); $pdf->Cell(20, 4, $point['team_score'], 0, 0, 'C');
            }
            if ($i === 0 || $i === count($points) - 1) {
                $pdf->SetXY($x - 13, $top + 40); $pdf->Cell(26, 5, substr($point['scored_at'], 0, 10), 0, 0, 'C');
            }
            $previous = [$x, $y];
        }
        $pdf->SetXY(12, $top + 49);
        PdfHelper::writeParagraph($pdf, '纵轴：综合得分（0–100分）；横轴：按算分时间排列的测评批次。');
    }

    public static function title(array $d, array $teams)
    {
        if (!empty($d['report_team_scope'])) {
            if (count($teams) === 1) return $d['org']['name'].' — '.$teams[0]['team_name'].'班组诊断报告';
            return $d['org']['name'].' — '.count($teams).'个班组诊断报告';
        }
        return $d['org']['name'].'企业诊断报告';
    }

    public static function build(array $d, array $teams)
    {
        $period = trim($d['period'] ?? '');
        if ($period === '不限 至 不限') $period = '';
        $pdf = PdfHelper::create(self::title($d, $teams), $period, true);
        if (!empty($d['report_team_scope'])) {
            self::section($pdf, count($teams) === 1 ? '本次导出班组' : '本次导出班组名单');
            self::paragraphs($pdf, array_map(function($t){return $t['team_name'];}, $teams), '未选择班组');
            PdfHelper::writeParagraph($pdf, '所属企业：'.$d['org']['name']);
        }
        PdfHelper::writeParagraph($pdf, '企业概览按当前筛选范围统计；班组诊断包含本次导出的 '.count($teams).' 个班组。');
        $cards = [['综合得分', $d['summary']['score_text'].' 分'], ['成熟度', $d['summary']['level_name']], ['测评完成率', $d['completion_rate'].'%']];
        self::room($pdf, 28); $y = $pdf->GetY() + 3;
        foreach ($cards as $i => $card) {
            $x = 12 + $i * 63;
            $pdf->SetFillColor(8, 45, 73); $pdf->Rect($x, $y, 60, 23, 'F');
            $pdf->SetDrawColor(37, 211, 245); $pdf->Line($x, $y, $x + 60, $y);
            $pdf->SetXY($x + 3, $y + 2); PdfHelper::applyFont($pdf, 9); $pdf->Cell(54, 6, $card[0]);
            $pdf->SetXY($x + 3, $y + 10); PdfHelper::applyFont($pdf, 14); $pdf->Cell(54, 9, $card[1]);
        }
        $pdf->SetXY(12, $y + 27); PdfHelper::applyFont($pdf, 9);
        PdfHelper::writeParagraph($pdf, '班组总数 '.$d['team_total'].' · 期内已测评 '.$d['completed_count']);
        PdfHelper::writeParagraph($pdf, $d['feature_desc']);
        self::section($pdf, '班组成熟度分布');
        $colors = ['passive'=>[255,112,107], 'dependent'=>[255,192,77], 'independent'=>[82,190,255], 'essential'=>[96,245,173]];
        $levels = [];
        foreach ($d['levels'] as $l) $levels[] = [$l['level_name'], $l['count'], isset($colors[$l['level_code']]) ? $colors[$l['level_code']] : [82,190,255]];
        self::bars($pdf, $levels, max(1, array_sum(array_column($levels, 1))), ' 个');
        self::section($pdf, '一级指标能力分析 · 得分率');
        $r = $d['l1_radar_company']; $rates = [];
        foreach ($r['indicators'] as $i => $name) $rates[] = [$name, $r['values'][$i]];
        self::bars($pdf, $rates);
        $pdf->AddPage();
        self::section($pdf, '企业二级指标短板 TOP5 · 失分率');
        PdfHelper::writeParagraph($pdf, '失分率越高，表示该指标越需要优先改进。');
        $weak = []; foreach ($d['weak_top5'] as $w) $weak[] = [$w['indicator_name'], $w['loss_rate']];
        self::bars($pdf, $weak, 100, '%', [255, 154, 75]);
        self::section($pdf, '高频原始问题');
        self::paragraphs($pdf, array_map(function($q){return $q['title'].'（'.$q['count'].' 次，'.$q['team_count'].' 个班组）';}, $d['questions']), '当前范围暂无高频问题记录');
        foreach ($teams as $t) {
            // Finish the previous page before changing its header context.
            $pdf->endPage();
            $pdf->tmHeaderLine = $t['team_name'].' · 班组诊断 / '.$d['org']['name'];
            $pdf->AddPage(); self::section($pdf, $t['team_name'].' · 班组诊断');
            PdfHelper::writeParagraph($pdf, '综合得分 '.PdfHelper::fmtScore($t['team_score']).' 分 · '.$t['level_name']);
            self::section($pdf, '一级指标得分率');
            self::bars($pdf, array_map(function($r){return [$r['name'], $r['score']];}, $t['indicator_rates']));
            self::room($pdf, 76);
            self::section($pdf, '历史测评趋势'); self::trend($pdf, $t['history']);
            self::section($pdf, '暴露问题'); self::paragraphs($pdf, $t['problems'], '暂无问题记录');
            self::section($pdf, '改进建议');
            self::paragraphs($pdf, array_map(function($m){return $m['title'].'：'.$m['content'];}, $t['measures']), '尚未配置对应改进建议');
            self::section($pdf, '历史测评明细');
            self::paragraphs($pdf, array_map(function($h){return $h['batch_name'].' / '.$h['scored_at'].' / '.PdfHelper::fmtScore($h['team_score']).' 分';}, $t['history']), '暂无历史测评记录');
        }
        return $pdf;
    }
}

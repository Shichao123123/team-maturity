<?php
require __DIR__.'/test-company-detail.php';
use app\index\service\CompanyReportService;

$empty = $detail;
$empty['levels'] = $empty['weak_top5'] = $empty['questions'] = [];
$empty['l1_radar_company'] = ['indicators'=>[], 'values'=>[]];
$report = CompanyReportService::build($empty, []);
check($report->tmDark === true, 'company report must use dark theme');
check(substr($report->Output('empty.pdf', 'S'), 0, 5) === '%PDF-', 'empty report failed');

$team = $detail['teams'][0];
$team['history'] = [];
for ($i=1; $i<=15; $i++) $team['history'][] = ['batch_name'=>'图表回归批次 '.$i, 'scored_at'=>sprintf('2026-08-%02d 12:00:00', $i), 'team_score'=>$i===4?null:50+$i*2];
$team['indicator_rates'][] = ['name'=>str_repeat('长指标名称', 10), 'score'=>null];
$detail['report_team_scope'] = true;
check(strpos(CompanyReportService::title($detail, [$team]), $team['team_name']) !== false, 'single team missing from report title');
$report = CompanyReportService::build($detail, [$team]);
check(strpos($report->tmHeaderLine, $team['team_name']) !== false, 'team missing from continued page header');
check($report->getNumPages() >= 3, 'expected paginated diagnosis');
check(substr($report->Output('history.pdf', 'S'), 0, 5) === '%PDF-', 'history report failed');
echo 'PASS dark chart reports: empty data, null score, long label, 15 history records'.PHP_EOL;

<?php
/**
 * 生成组织/班组/成员样例 Excel，供导入使用
 * 用法：php scripts/make-sample-import.php
 */
$root = dirname(__DIR__);
require_once $root . '/backend/vendor/phpoffice/phpexcel/Classes/PHPExcel.php';

$outDir = $root . '/scripts/sample-import';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

function saveSheet($path, $headers, $rows)
{
    $excel = new PHPExcel();
    $sheet = $excel->getActiveSheet();
    foreach ($headers as $c => $h) {
        $sheet->setCellValueByColumnAndRow($c, 1, $h);
        $sheet->getStyleByColumnAndRow($c, 1)->getFont()->setBold(true);
    }
    foreach ($rows as $r => $row) {
        foreach ($row as $c => $val) {
            $sheet->setCellValueByColumnAndRow($c, $r + 2, $val);
        }
    }
    $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
    $writer->save($path);
    echo "OK $path\n";
}

// 组织：公司 → 分厂 → 工段
saveSheet($outDir . '/01-org.xls', [
    '组织编码', '上级组织编码', '组织名称', '组织类型', '排序', '状态',
], [
    ['HQ001', '', '华安能源集团', '公司/集团', 1, '启用'],
    ['FAC01', 'HQ001', '第一炼化分厂', '厂/分厂', 1, '启用'],
    ['FAC02', 'HQ001', '第二炼化分厂', '厂/分厂', 2, '启用'],
    ['SEC11', 'FAC01', '常减压工段', '工段/部门', 1, '启用'],
    ['SEC12', 'FAC01', '催化裂化工段', '工段/部门', 2, '启用'],
    ['SEC21', 'FAC02', '储运工段', '工段/部门', 1, '启用'],
]);

// 班组
saveSheet($outDir . '/02-team.xls', [
    '班组名称', '所属组织', '状态',
], [
    ['常减压甲班', '常减压工段', '启用'],
    ['常减压乙班', '常减压工段', '启用'],
    ['催化甲班', '催化裂化工段', '启用'],
    ['储运一班', '储运工段', '启用'],
]);

// 成员（参评角色为主）
saveSheet($outDir . '/03-member.xls', [
    '姓名', '手机号', '所属班组', '岗位', '角色', '状态',
], [
    ['赵明', '13810000001', '常减压甲班', '班组长', '班组长', '启用'],
    ['钱伟', '13810000002', '常减压甲班', '组长', '组长', '启用'],
    ['孙丽', '13810000003', '常减压甲班', '安全员', '安全员', '启用'],
    ['周杰', '13810000004', '常减压甲班', '兼职安全员', '兼职安全员', '启用'],
    ['吴刚', '13810000005', '常减压乙班', '班组长', '班组长', '启用'],
    ['郑华', '13810000006', '常减压乙班', '安全员', '安全员', '启用'],
    ['冯强', '13810000007', '催化甲班', '班组长', '班组长', '启用'],
    ['陈静', '13810000008', '催化甲班', '组长', '组长', '启用'],
    ['褚涛', '13810000009', '储运一班', '班组长', '班组长', '启用'],
    ['卫芳', '13810000010', '储运一班', '安全员', '安全员', '启用'],
]);

echo "DONE\n";

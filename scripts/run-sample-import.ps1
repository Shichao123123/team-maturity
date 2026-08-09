# 生成样例 Excel 并导入本地库
$php = "D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe"
$script = Join-Path $PSScriptRoot "seed-import.php"
& $php $script

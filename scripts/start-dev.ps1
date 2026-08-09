# 启动本地 PC 后台（PHP 内置服务器）
# 用法：powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1
$php = "D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe"
$root = Split-Path -Parent $PSScriptRoot
$public = Join-Path $root "backend\public"
$router = Join-Path $public "router.php"

if (-not (Test-Path $php)) {
    Write-Error "未找到 PHP: $php"
    exit 1
}

Write-Host "PC 后台: http://127.0.0.1:8088/"
Write-Host "账号: admin / 123456"
Write-Host "根目录: $public"
& $php -S 127.0.0.1:8088 -t $public $router

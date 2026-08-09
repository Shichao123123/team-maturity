# 启动小程序联调后端（监听所有网卡，模拟器/真机都可用）
$php = 'D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe'
$root = Split-Path -Parent $PSScriptRoot
$public = Join-Path $root 'backend\public'
$router = Join-Path $public 'router.php'

Write-Host "Stopping old listeners on :8088 ..."
$conns = Get-NetTCPConnection -LocalPort 8088 -State Listen -ErrorAction SilentlyContinue
if ($conns) {
    $conns | Select-Object -ExpandProperty OwningProcess -Unique | ForEach-Object {
        Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Seconds 1
}

Write-Host "Starting http://0.0.0.0:8088 ..."
Write-Host "Local:  http://127.0.0.1:8088"
Write-Host "LAN:    http://$((Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '192.168.*' } | Select-Object -First 1 -ExpandProperty IPAddress)):8088"
Write-Host "Press Ctrl+C to stop."
& $php -S 0.0.0.0:8088 -t $public $router

param(
    [string]$Php = 'php.exe'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

foreach ($file in (& rg --files $root -g '*.php')) {
    $output = & $Php -l $file
    if ($LASTEXITCODE -ne 0) { throw ($output -join "`n") }
}

& $Php (Join-Path $PSScriptRoot 'standalone.php')
if ($LASTEXITCODE -ne 0) { throw 'standalone web security tests failed' }
& $Php (Join-Path $PSScriptRoot 'controller.php')
if ($LASTEXITCODE -ne 0) { throw 'standalone controller integration tests failed' }

$curlAvailable = (& $Php -d extension=curl -r "echo extension_loaded('curl') ? 'yes' : 'no';") -eq 'yes'
if (-not $curlAvailable) { throw 'PHP cURL extension is required for the concurrent HTTP client test' }

$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = ([System.Net.IPEndPoint] $listener.LocalEndpoint).Port
$listener.Stop()

$stdout = Join-Path $env:TEMP "icinga-kubernetes-web-$port.out"
$stderr = Join-Path $env:TEMP "icinga-kubernetes-web-$port.err"
$server = Start-Process -FilePath $Php `
    -ArgumentList @('-S', "127.0.0.1:$port", '-t', '.', 'tests/mock-api-router.php') `
    -WorkingDirectory $root `
    -RedirectStandardOutput $stdout `
    -RedirectStandardError $stderr `
    -WindowStyle Hidden `
    -PassThru

try {
    Start-Sleep -Milliseconds 500
    if ($server.HasExited) { throw "temporary PHP mock API exited with $($server.ExitCode)" }
    & $Php -d extension=curl (Join-Path $PSScriptRoot 'client-http.php') $port
    if ($LASTEXITCODE -ne 0) { throw 'concurrent HTTP client test failed' }
} finally {
    if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    $server.WaitForExit()
    Remove-Item -LiteralPath $stdout, $stderr -ErrorAction SilentlyContinue
}

& (Join-Path $PSScriptRoot 'run-restart-backlog.ps1') -Php $Php
if ($LASTEXITCODE -ne 0) { throw 'restart/backlog integration test failed' }

Write-Output 'All Icinga Kubernetes Web standalone tests passed.'

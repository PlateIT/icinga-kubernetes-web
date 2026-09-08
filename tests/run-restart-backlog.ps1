param([string]$Php = 'php.exe')

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http
$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$testRoot = Join-Path $tempRoot ('icinga-kubernetes-web-restart-' + [guid]::NewGuid().ToString('N'))
$root = Split-Path -Parent $PSScriptRoot
$server = $null
$allClients = @()
$httpHandler = [Net.Http.HttpClientHandler]::new()
$httpHandler.UseProxy = $false
$httpClient = [Net.Http.HttpClient]::new($httpHandler)
$httpClient.Timeout = [TimeSpan]::FromSeconds(2)

$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = ([Net.IPEndPoint] $listener.LocalEndpoint).Port
$listener.Stop()
[IO.Directory]::CreateDirectory($testRoot) | Out-Null

try {
    $server = Start-Process -FilePath $Php `
        -ArgumentList @('-S', "127.0.0.1:$port", '-t', '.', 'tests/mock-api-router.php') `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $testRoot 'api.out') `
        -RedirectStandardError (Join-Path $testRoot 'api.err')
    $deadline = (Get-Date).AddSeconds(10)
    do {
        Start-Sleep -Milliseconds 100
        try {
            $response = $httpClient.GetAsync("http://127.0.0.1:$port/api/v1/resources").Result
            $ready = [int] $response.StatusCode -eq 200
            $response.Dispose()
        } catch {
            $ready = $false
        }
    } until ($ready -or $server.HasExited -or (Get-Date) -gt $deadline)
    if (-not $ready) { throw 'temporary Kubernetes API did not start' }

    $stopwatch = [Diagnostics.Stopwatch]::StartNew()
    for ($wave = 1; $wave -le 3; $wave++) {
        $clients = @()
        for ($replica = 1; $replica -le 10; $replica++) {
            $stdout = Join-Path $testRoot "client-$wave-$replica.out"
            $stderr = Join-Path $testRoot "client-$wave-$replica.err"
            $process = Start-Process -FilePath $Php -ArgumentList @(
                '-d', 'extension=curl', (Join-Path $PSScriptRoot 'restart-backlog-client.php'), $port
            ) -WorkingDirectory $root -WindowStyle Hidden -PassThru `
                -RedirectStandardOutput $stdout -RedirectStandardError $stderr
            $clients += [pscustomobject]@{ Process = $process; Stdout = $stdout; Stderr = $stderr }
            $allClients += $process
        }
        foreach ($client in $clients) {
            if (-not $client.Process.WaitForExit(15000)) {
                Stop-Process -Id $client.Process.Id -Force
                throw "cold-start client wave $wave exceeded 15 seconds"
            }
            $client.Process.WaitForExit()
            $errorOutput = [IO.File]::ReadAllText($client.Stderr)
            $standardOutput = [IO.File]::ReadAllText($client.Stdout).Trim()
            if ($standardOutput -ne 'ok' -or $errorOutput -ne '') {
                throw "cold-start client failed: $errorOutput $standardOutput"
            }
        }
    }
    $stopwatch.Stop()
    if ($stopwatch.Elapsed -ge [TimeSpan]::FromSeconds(30)) {
        throw "three cold-start waves required $($stopwatch.Elapsed.TotalSeconds) seconds"
    }
    Write-Output "Kubernetes Web cold-start/backlog test passed in $([math]::Round($stopwatch.Elapsed.TotalSeconds, 2)) seconds."
} finally {
    foreach ($client in $allClients) {
        if (-not $client.HasExited) { Stop-Process -Id $client.Id -Force }
        $client.WaitForExit()
        $client.Dispose()
    }
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    if ($server) { $server.WaitForExit(); $server.Dispose() }
    $httpClient.Dispose()
    $httpHandler.Dispose()
    $resolved = [IO.Path]::GetFullPath($testRoot)
    if (-not $resolved.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase)) { throw "unsafe test directory $resolved" }
    if ([IO.Directory]::Exists($resolved)) { [IO.Directory]::Delete($resolved, $true) }
}

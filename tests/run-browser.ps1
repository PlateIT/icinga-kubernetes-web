param(
    [string]$Edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $Edge)) {
    throw "Microsoft Edge was not found at $Edge"
}

$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$profile = Join-Path $tempRoot ('icinga-kubernetes-web-browser-' + [guid]::NewGuid().ToString('N'))
$stdout = Join-Path $profile 'stdout.html'
$stderr = Join-Path $profile 'stderr.log'
$page = 'file:///' + ((Resolve-Path (Join-Path $PSScriptRoot 'browser-smoke.html')).Path -replace '\\', '/')
$process = $null

New-Item -ItemType Directory -Path $profile | Out-Null
try {
    $arguments = @(
        '--headless', '--disable-gpu', '--no-first-run', '--disable-extensions',
        "--user-data-dir=$profile", '--virtual-time-budget=2500', '--dump-dom', $page
    )
    $process = Start-Process -FilePath $Edge -ArgumentList $arguments -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput $stdout -RedirectStandardError $stderr
    $deadline = (Get-Date).AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 250
        $dom = Get-Content -LiteralPath $stdout -Raw -ErrorAction SilentlyContinue
    } until (
        ($dom -match '<output id="result">(.*?)</output>' -and $matches[1] -ne 'pending') `
        -or (Get-Date) -gt $deadline `
        -or $process.HasExited
    )
    if (-not $process.HasExited) {
        Stop-Process -Id $process.Id -Force
    }
    $dom = Get-Content -LiteralPath $stdout -Raw -ErrorAction SilentlyContinue
    if ($dom -notmatch '<output id="result">ok</output>') {
        $browserError = Get-Content -LiteralPath $stderr -Raw -ErrorAction SilentlyContinue
        throw "browser integration result was not ok: $browserError"
    }
    Write-Output 'Browser SSE, partial refresh and live metrics integration test passed.'
} finally {
    Get-CimInstance Win32_Process -Filter "Name = 'msedge.exe'" `
        | Where-Object { $_.CommandLine -like "*$profile*" } `
        | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    $resolvedProfile = [IO.Path]::GetFullPath($profile)
    if (-not $resolvedProfile.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw "refusing to remove browser profile outside the temporary directory: $resolvedProfile"
    }
    if (Test-Path -LiteralPath $resolvedProfile) {
        Remove-Item -LiteralPath $resolvedProfile -Recurse -Force
    }
}

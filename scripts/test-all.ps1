$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot
$phpExecutable = Join-Path $projectRoot '.runtime\php\php.exe'
$priorDatabase = $env:DB_DATABASE
$priorEnvironment = $env:APP_ENV
$server = $null
function Invoke-Checked([string]$Command,[string[]]$Arguments) {
    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Check failed: $Command $($Arguments -join ' ')" }
}
try {
    $env:DB_DATABASE = 'carcare_test'
    $env:APP_ENV = 'local'
    Invoke-Checked $phpExecutable @('tests/reset.php')
    Invoke-Checked $phpExecutable @('scripts/seed.php')
    Invoke-Checked $phpExecutable @('tests/run.php')
    $server = Start-Process -FilePath $phpExecutable -ArgumentList @('-S','127.0.0.1:8081','-t','public','router.php') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $projectRoot '.runtime\test-web-out.log') -RedirectStandardError (Join-Path $projectRoot '.runtime\test-web-error.log')
    Start-Sleep -Milliseconds 700
    if ($server.HasExited) { throw 'Port 8081 is occupied or the test server failed to start.' }
    Invoke-Checked 'python' @('tests/admin-api.py')
    Invoke-Checked 'node' @('tests/browser.cjs')
    Invoke-Checked 'node' @('tests/home-browser.cjs')
    Invoke-Checked 'node' @('tests/admin-browser.cjs')
    Invoke-Checked $phpExecutable @('scripts/export-schema.php')
    Invoke-Checked 'python' @('tests/static-checks.py')
    Invoke-Checked 'powershell' @('-NoProfile','-ExecutionPolicy','Bypass','-File','scripts/package.ps1')
    Invoke-Checked 'python' @('tests/deploy-check.py')
    Write-Output 'All local domain, API, browser, source, backup/restore and packaged-deployment checks passed.'
} finally {
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id }
    $env:DB_DATABASE = $priorDatabase
    $env:APP_ENV = $priorEnvironment
}

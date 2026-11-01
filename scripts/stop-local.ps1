$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$workerPidFile = Join-Path $projectRoot '.runtime\worker-process.id'
if (Test-Path -LiteralPath $workerPidFile) {
    $workerProcess = Get-Process -Id ([int](Get-Content -LiteralPath $workerPidFile)) -ErrorAction SilentlyContinue
    if ($workerProcess -and $workerProcess.Path -eq (Join-Path $projectRoot '.runtime\php\php.exe')) { Stop-Process -Id $workerProcess.Id }
    Remove-Item -LiteralPath $workerPidFile
}
$pidFile = Join-Path $projectRoot '.runtime\php-process.id'
if (Test-Path -LiteralPath $pidFile) {
    $serverProcessId = [int](Get-Content -LiteralPath $pidFile)
    $process = Get-Process -Id $serverProcessId -ErrorAction SilentlyContinue
    $expectedExecutable = Join-Path $projectRoot '.runtime\php\php.exe'
    if ($process -and $process.Path -eq $expectedExecutable) { Stop-Process -Id $serverProcessId }
    Remove-Item -LiteralPath $pidFile
}
$phpExecutable = Join-Path $projectRoot '.runtime\php\php.exe'
$databasePidFile = Join-Path $projectRoot '.runtime\mysql-process.id'
if (Test-Path -LiteralPath $databasePidFile) {
    $databaseProcess = Get-Process -Id ([int](Get-Content -LiteralPath $databasePidFile)) -ErrorAction SilentlyContinue
    $expectedMysql = Join-Path $projectRoot '.runtime\mysql\bin\mysqld.exe'
    if ($databaseProcess -and $databaseProcess.Path -eq $expectedMysql) {
        & $phpExecutable (Join-Path $PSScriptRoot 'local-database.php') --stop
        if ($LASTEXITCODE -ne 0) { throw 'The project database did not shut down cleanly.' }
        if (-not $databaseProcess.WaitForExit(15000)) { throw 'Database shutdown is still in progress. Wait before restarting.' }
    }
}
Write-Output 'Project-local services stopped. Database files have been preserved.'

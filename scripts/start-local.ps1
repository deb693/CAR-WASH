param([int]$Port = 8080)
$ErrorActionPreference = 'Stop'
function Test-LocalPort([int]$TargetPort) {
    $client = New-Object System.Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1', $TargetPort); return $true }
    catch { return $false }
    finally { $client.Dispose() }
}
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpExecutable = Join-Path $projectRoot '.runtime\php\php.exe'
$mysqlExecutable = Join-Path $projectRoot '.runtime\mysql\bin\mysqld.exe'
if (-not (Test-Path -LiteralPath $phpExecutable) -or -not (Test-Path -LiteralPath $mysqlExecutable)) {
    throw 'Run python scripts/download-runtimes.py first, or use the manual PHP/MySQL setup in README.md.'
}
$runtimeRoot = Join-Path $projectRoot '.runtime'
$mysqlRoot = Join-Path $runtimeRoot 'mysql'
$dataDirectory = Join-Path $runtimeRoot 'mysql-data'
if (-not (Test-Path -LiteralPath (Join-Path $dataDirectory 'mysql'))) {
    & $mysqlExecutable --no-defaults "--basedir=$mysqlRoot" "--datadir=$dataDirectory" --initialize-insecure --console
    if ($LASTEXITCODE -ne 0) { throw 'Database initialization failed.' }
}
$dbRunning = Test-LocalPort 3307
if ($dbRunning) {
    $ownedPidFile = Join-Path $runtimeRoot 'mysql-process.id'
    if (-not (Test-Path -LiteralPath $ownedPidFile)) { throw 'Port 3307 is owned by another process. No database changes were made.' }
    $ownedProcess = Get-Process -Id ([int](Get-Content -LiteralPath $ownedPidFile)) -ErrorAction SilentlyContinue
    if (-not $ownedProcess -or $ownedProcess.Path -ne $mysqlExecutable) { throw 'Cannot verify ownership of the database on port 3307.' }
}
if (-not $dbRunning) {
    $arguments = @('--no-defaults', ('--basedir="' + $mysqlRoot + '"'), ('--datadir="' + $dataDirectory + '"'), '--port=3307', '--bind-address=127.0.0.1', '--mysqlx=0', '--console')
    $dbProcess = Start-Process -FilePath $mysqlExecutable -ArgumentList $arguments -WorkingDirectory $mysqlRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeRoot 'mysql-out.log') -RedirectStandardError (Join-Path $runtimeRoot 'mysql-error.log')
    $dbProcess.Id | Set-Content -LiteralPath (Join-Path $runtimeRoot 'mysql-process.id')
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Milliseconds 500
        if (Test-LocalPort 3307) { $ready = $true; break }
    }
    if (-not $ready) { throw 'MySQL did not start. Check .runtime/mysql-error.log.' }
}
& $phpExecutable (Join-Path $PSScriptRoot 'local-database.php')
if ($LASTEXITCODE -ne 0) { throw 'Could not configure the isolated database. Check port 3307 is not used by another instance.' }
& $phpExecutable (Join-Path $PSScriptRoot 'migrate.php')
if ($LASTEXITCODE -ne 0) { throw 'Schema import failed.' }
& $phpExecutable (Join-Path $PSScriptRoot 'seed.php')
if ($LASTEXITCODE -ne 0) { throw 'Demo seed failed.' }
& $phpExecutable (Join-Path $PSScriptRoot 'local-admin.php')
if ($LASTEXITCODE -ne 0) { throw 'Local admin setup failed.' }
$existing = Test-LocalPort $Port
if ($existing) { throw "Port $Port is already in use. Use -Port with a different port or stop the prior local site." }
$phpArguments = @('-S', "127.0.0.1:$Port", '-t', 'public', 'router.php')
$webProcess = Start-Process -FilePath $phpExecutable -ArgumentList $phpArguments -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeRoot 'php-out.log') -RedirectStandardError (Join-Path $runtimeRoot 'php-error.log')
$webProcess.Id | Set-Content -LiteralPath (Join-Path $runtimeRoot 'php-process.id')
Start-Sleep -Milliseconds 700
$response = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$Port/api/v1/health"
if ($response.StatusCode -ne 200) { throw 'Local API did not become healthy.' }
Write-Output "SteamRex is running at http://127.0.0.1:$Port"
Write-Output 'Sample postcodes: 1000, 2000, 3000. Prices are illustrative DKK amounts.'
Write-Output 'Stop with .\scripts\stop-local.ps1. Nothing has been deployed.'
$workerPidFile = Join-Path $runtimeRoot 'worker-process.id'
$worker = $null
if (Test-Path -LiteralPath $workerPidFile) { $worker = Get-Process -Id ([int](Get-Content -LiteralPath $workerPidFile)) -ErrorAction SilentlyContinue }
if (-not $worker -or $worker.Path -ne $phpExecutable) {
    $worker = Start-Process -FilePath $phpExecutable -ArgumentList @('scripts/worker.php','--watch') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeRoot 'worker-out.log') -RedirectStandardError (Join-Path $runtimeRoot 'worker-error.log')
    $worker.Id | Set-Content -LiteralPath $workerPidFile
}
Write-Output 'Local notification worker started. Capture-mode emails appear in admin > Email delivery.'


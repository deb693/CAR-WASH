$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$packageRoot = Join-Path $projectRoot ('deploy\steamrex-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $packageRoot | Out-Null
Copy-Item -LiteralPath (Join-Path $projectRoot 'public') -Destination (Join-Path $packageRoot 'public_html') -Recurse
Copy-Item -LiteralPath (Join-Path $projectRoot 'app') -Destination $packageRoot -Recurse
Copy-Item -LiteralPath (Join-Path $projectRoot 'database') -Destination $packageRoot -Recurse
Copy-Item -LiteralPath (Join-Path $projectRoot 'docs') -Destination $packageRoot -Recurse
Copy-Item -LiteralPath (Join-Path $projectRoot 'README.md') -Destination $packageRoot
New-Item -ItemType Directory -Path (Join-Path $packageRoot 'scripts') | Out-Null
foreach ($scriptName in @('migrate.php','create-admin.php','worker.php','backup.php','restore.php','maintenance.php','export-schema.php','prepare-seo.php','check-release.php')) {
    Copy-Item -LiteralPath (Join-Path $projectRoot ('scripts\' + $scriptName)) -Destination (Join-Path $packageRoot 'scripts')
}
Copy-Item -LiteralPath (Join-Path $projectRoot '.env.example') -Destination $packageRoot
$productionEnvironment = Get-Content -LiteralPath (Join-Path $packageRoot '.env.example') -Raw
$productionEnvironment = $productionEnvironment.Replace('APP_ENV=local','APP_ENV=production').Replace('DEMO_MODE=true','DEMO_MODE=false').Replace('DB_PORT=3307','DB_PORT=3306').Replace('SITE_URL=http://127.0.0.1:8080','SITE_URL=https://replace-with-your-domain.example')
$utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Join-Path $packageRoot '.env.example'),$productionEnvironment,$utf8WithoutBom)
Copy-Item -LiteralPath (Join-Path $projectRoot 'docs\DEPLOYMENT.md') -Destination $packageRoot
$deploymentGuide = [System.IO.File]::ReadAllText((Join-Path $packageRoot 'DEPLOYMENT.md')).Replace('(ADMIN_GUIDE.md)','(docs/ADMIN_GUIDE.md)')
[System.IO.File]::WriteAllText((Join-Path $packageRoot 'DEPLOYMENT.md'),$deploymentGuide,$utf8WithoutBom)
$zipPath = $packageRoot + '.zip'
Compress-Archive -Path (Join-Path $packageRoot '*') -DestinationPath $zipPath
Write-Output "Review package: $zipPath"
Write-Output 'No secrets or local runtimes are included. This does not upload or deploy the site.'


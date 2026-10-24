$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$localRoot = Join-Path $projectRoot '.local'
$phpBinary = Join-Path $localRoot 'php/php.exe'
$env:COMPOSER_HOME = Join-Path $localRoot 'composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $localRoot 'composer-cache'
& $phpBinary (Join-Path $PSScriptRoot 'check.php')
if ($LASTEXITCODE -ne 0) { throw 'PHP environment check failed.' }
$credentials = Get-Content (Join-Path $localRoot 'mysql-credentials.json') -Raw | ConvertFrom-Json
$names = @('ASGARD_DB_HOST','ASGARD_DB_PORT','ASGARD_DB_DATABASE','ASGARD_DB_USERNAME','ASGARD_DB_PASSWORD')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
try {
    $env:ASGARD_DB_HOST = '127.0.0.1'
    $env:ASGARD_DB_PORT = '33084'
    $env:ASGARD_DB_DATABASE = 'asgard_environment'
    $env:ASGARD_DB_USERNAME = 'asgard_environment'
    $env:ASGARD_DB_PASSWORD = $credentials.app
    & $phpBinary (Join-Path $PSScriptRoot 'check-mysql.php')
    if ($LASTEXITCODE -ne 0) { throw 'MySQL environment check failed.' }
} finally {
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
}
& $phpBinary (Join-Path $localRoot 'composer/composer.phar') diagnose --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Composer diagnostics failed.' }
node --check (Join-Path $projectRoot 'landing/dist/script.js')
if ($LASTEXITCODE -ne 0) { throw 'Landing syntax check failed.' }

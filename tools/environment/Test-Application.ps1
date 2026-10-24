$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$credentials = Get-Content (Join-Path $projectRoot '.local/mysql-credentials.json') -Raw | ConvertFrom-Json
$names = @('DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','APP_ENV','OPENSSL_CONF')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
$applicationRoot = Join-Path $projectRoot 'application'
if (Test-Path (Join-Path $projectRoot '.local/mysql-drive.txt')) { $applicationRoot = 'S:/application' }
Push-Location $applicationRoot
try {
    $env:DB_HOST='127.0.0.1'; $env:DB_PORT='33084'; $env:DB_DATABASE='asgard_test'
    $env:DB_USERNAME='asgard_test'; $env:DB_PASSWORD=$credentials.testing; $env:APP_ENV='testing'
    $env:OPENSSL_CONF='S:/.local/php/extras/ssl/openssl.cnf'
    & (Join-Path $projectRoot '.local/php/php.exe') vendor/phpunit/phpunit/phpunit --configuration phpunit.xml
    if ($LASTEXITCODE -ne 0) { throw 'Application tests failed.' }
} finally {
    Pop-Location
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
}

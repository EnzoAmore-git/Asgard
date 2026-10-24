$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$applicationRoot = Join-Path $projectRoot 'application'
$php = Join-Path $projectRoot '.local/php/php.exe'
$credentials = Get-Content (Join-Path $projectRoot '.local/mysql-credentials.json') -Raw | ConvertFrom-Json
$environmentFile = Join-Path $applicationRoot '.env'
if (-not $credentials.application) { throw 'Restart the dedicated MySQL with the current Start-LocalMySQL script first.' }
if (-not (Test-Path -LiteralPath $environmentFile)) {
    $content = [IO.File]::ReadAllText((Join-Path $applicationRoot '.env.example'))
    $content = $content -replace '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$($credentials.application)"
    [IO.File]::WriteAllText($environmentFile, $content)
}
Push-Location $applicationRoot
$environmentNames = @('COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'PATH')
$previousEnvironment = @{}
foreach ($name in $environmentNames) { $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
try {
    $env:COMPOSER_HOME = Join-Path $projectRoot '.local/composer-home'
    $env:COMPOSER_CACHE_DIR = Join-Path $projectRoot '.local/composer-cache'
    $env:PATH = (Join-Path $projectRoot '.local/php') + ';' + $env:PATH
    & $php (Join-Path $projectRoot '.local/composer/composer.phar') install --no-interaction --prefer-dist --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Composer installation failed.' }
    if ([IO.File]::ReadAllText($environmentFile) -match '(?m)^APP_KEY=\s*$') {
        & $php artisan key:generate --quiet
        if ($LASTEXITCODE -ne 0) { throw 'Application key generation failed.' }
    }
    & $php artisan migrate --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Development migrations failed.' }
} finally {
    Pop-Location
    foreach ($name in $environmentNames) { [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process') }
}

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$localRoot = Join-Path $projectRoot '.local'
$env:COMPOSER_HOME = Join-Path $localRoot 'composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $localRoot 'composer-cache'
$downloadRoot = Join-Path $localRoot 'downloads'
New-Item -ItemType Directory -Force -Path $downloadRoot | Out-Null

function Get-VerifiedFile($Url, $Destination, $Hash, $Algorithm = 'SHA256') {
    if (-not (Test-Path -LiteralPath $Destination)) {
        Invoke-WebRequest -Uri $Url -OutFile $Destination
    }
    if ((Get-FileHash -LiteralPath $Destination -Algorithm $Algorithm).Hash -ne $Hash) {
        throw "Checksum mismatch: $Destination. Download not executed."
    }
}

$releases = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
$phpRelease = $releases.'8.4'
if ($phpRelease.version -ne '8.4.27') { throw 'PHP metadata changed. Review and update the pinned version before installation.' }
$phpZip = $phpRelease.'nts-vs17-x64'.zip
$phpArchive = Join-Path $downloadRoot $phpZip.path
Get-VerifiedFile "https://windows.php.net/downloads/releases/$($phpZip.path)" $phpArchive $phpZip.sha256
$phpRoot = Join-Path $localRoot 'php'
if (-not (Test-Path (Join-Path $phpRoot 'php.exe'))) {
    Expand-Archive -LiteralPath $phpArchive -DestinationPath $phpRoot
}
$phpIni = @'
[PHP]
extension_dir = "ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=intl
extension=zip
extension=sodium
date.timezone=UTC
memory_limit=256M
display_errors=stderr
log_errors=On
expose_php=Off
'@
[IO.File]::WriteAllText((Join-Path $phpRoot 'php.ini'), $phpIni)

$composerRoot = Join-Path $localRoot 'composer'
New-Item -ItemType Directory -Force -Path $composerRoot | Out-Null
Get-VerifiedFile 'https://getcomposer.org/download/2.10.3/composer.phar' (Join-Path $composerRoot 'composer.phar') '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'
$installer = Join-Path $downloadRoot 'composer-setup.php'
$hashResponse = (Invoke-WebRequest 'https://composer.github.io/installer.sig').Content
$installerHash = if ($hashResponse -is [byte[]]) { [Text.Encoding]::UTF8.GetString($hashResponse).Trim() } else { ([string]$hashResponse).Trim() }
Get-VerifiedFile 'https://getcomposer.org/installer' $installer $installerHash 'SHA384'
& (Join-Path $phpRoot 'php.exe') $installer --version=2.10.3 "--install-dir=$composerRoot" --filename=composer.phar --quiet
if ($LASTEXITCODE -ne 0) { throw 'Composer verified installer failed.' }
& (Join-Path $phpRoot 'php.exe') -v
if ($LASTEXITCODE -ne 0) { throw 'PHP did not start.' }
& (Join-Path $phpRoot 'php.exe') (Join-Path $composerRoot 'composer.phar') --version
if ($LASTEXITCODE -ne 0) { throw 'Composer did not start.' }
Write-Output 'PHP and Composer installed locally. System PATH unchanged.'

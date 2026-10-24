$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$localRoot = Join-Path $projectRoot '.local'
$archive = Join-Path $localRoot 'downloads/mysql-8.4.13-winx64.zip'
if (-not (Test-Path -LiteralPath $archive)) {
    Invoke-WebRequest 'https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.13-winx64.zip' -OutFile $archive
}
# Checksum published on the official MySQL download page, fetched 2026-10-24.
if ((Get-FileHash -LiteralPath $archive -Algorithm MD5).Hash -ne '7b94489d5524dea9b27097068bea25a8') {
    throw 'MySQL archive checksum mismatch. Archive not executed.'
}
$destination = Join-Path $localRoot 'mysql'
if (-not (Test-Path (Join-Path $destination 'mysql-8.4.13-winx64/bin/mysqld.exe'))) {
    Expand-Archive -LiteralPath $archive -DestinationPath $destination
}
& (Join-Path $destination 'mysql-8.4.13-winx64/bin/mysqld.exe') --version
if ($LASTEXITCODE -ne 0) { throw 'MySQL binary did not start.' }
Write-Output 'MySQL installed locally. No Windows service installed.'

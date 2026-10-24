$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$localRoot = Join-Path $projectRoot '.local'
$adminBinary = Join-Path $localRoot 'mysql/mysql-8.4.13-winx64/bin/mysqladmin.exe'
$configPath = Join-Path $localRoot 'mysql-admin.ini'
& $adminBinary "--defaults-extra-file=$configPath" shutdown
if ($LASTEXITCODE -ne 0) { throw 'Dedicated MySQL did not shut down cleanly.' }
$driveFile = Join-Path $localRoot 'mysql-drive.txt'
if (Test-Path -LiteralPath $driveFile) {
    $drive = [IO.File]::ReadAllText($driveFile).Trim()
    if ($drive -ne 'S:') { throw 'Unexpected mapping. No mapping was removed.' }
    & subst.exe $drive /D
    if ($LASTEXITCODE -ne 0) { throw 'Could not release the temporary drive mapping.' }
    Remove-Item -LiteralPath $driveFile
}
Write-Output 'Dedicated local MySQL stopped cleanly.'

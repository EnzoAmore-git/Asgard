$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$localRoot = Join-Path $projectRoot '.local'
$serverRoot = Join-Path $localRoot 'mysql/mysql-8.4.13-winx64'
$mysqlBinary = Join-Path $serverRoot 'bin/mysqld.exe'
$dataRoot = Join-Path $localRoot 'mysql-data'
$configPath = Join-Path $localRoot 'mysql.ini'
$credentialsPath = Join-Path $localRoot 'mysql-credentials.json'
$initPath = Join-Path $localRoot 'mysql-init.sql'
$adminPath = Join-Path $localRoot 'mysql-admin.ini'
$port = 33084

if (-not (Test-Path -LiteralPath $mysqlBinary)) { throw 'Run Install-LocalMySQL.ps1 first.' }
if (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue) {
    throw "Port $port is already in use. No existing process was stopped."
}
# MySQL's Windows runtime cannot reliably use Cyrillic paths. Map an unused
# drive for this process session; all actual files remain in F:/Проекты/Асгард.
$driveFile = Join-Path $localRoot 'mysql-drive.txt'
if (Test-Path -LiteralPath $driveFile) {
    throw 'An earlier MySQL drive mapping exists. Stop it or review before starting again.'
}
$drive = 'S:'
if (Test-Path "$drive\") { throw 'S: is in use. No existing drive mapping was changed.' }
& subst.exe $drive $projectRoot
if ($LASTEXITCODE -ne 0) { throw 'Could not create the temporary ASCII drive mapping.' }
[IO.File]::WriteAllText($driveFile, $drive)
$localRoot = "$drive/.local"
$serverRoot = Join-Path $localRoot 'mysql/mysql-8.4.13-winx64'
$mysqlBinary = Join-Path $serverRoot 'bin/mysqld.exe'
$dataRoot = Join-Path $localRoot 'mysql-data'
$configPath = Join-Path $localRoot 'mysql.ini'
$initPath = Join-Path $localRoot 'mysql-init.sql'
$adminPath = Join-Path $localRoot 'mysql-admin.ini'
if (-not (Test-Path -LiteralPath $credentialsPath)) {
    if (Test-Path -LiteralPath $dataRoot) { throw 'Existing MySQL data without local credentials. Manual review required.' }
    $credentials = @{
        root = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
        app = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
    }
    [IO.File]::WriteAllText($credentialsPath, ($credentials | ConvertTo-Json))
}
$credentials = Get-Content -LiteralPath $credentialsPath -Raw | ConvertFrom-Json
if (-not $credentials.PSObject.Properties['admin']) {
    $credentials | Add-Member -NotePropertyName admin -NotePropertyValue ([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)))
    [IO.File]::WriteAllText($credentialsPath, ($credentials | ConvertTo-Json))
}
foreach ($credentialName in @('application', 'testing')) {
    if (-not $credentials.PSObject.Properties[$credentialName]) {
        $credentials | Add-Member -NotePropertyName $credentialName -NotePropertyValue ([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)))
    }
}
[IO.File]::WriteAllText($credentialsPath, ($credentials | ConvertTo-Json))
$serverUnix = $serverRoot.Replace('\','/')
$dataUnix = $dataRoot.Replace('\','/')
$configuration = @"
[mysqld]
basedir=$serverUnix
datadir=$dataUnix
bind-address=127.0.0.1
port=$port
mysqlx=OFF
skip-name-resolve
local-infile=OFF
character-set-server=utf8mb4
collation-server=utf8mb4_0900_ai_ci
log-error=$($localRoot.Replace('\','/'))/mysql-error.log
"@
[IO.File]::WriteAllText($configPath, $configuration)
if (-not (Test-Path -LiteralPath $dataRoot)) {
    & $mysqlBinary "--defaults-file=$configPath" --initialize-insecure
    if ($LASTEXITCODE -ne 0) { throw 'MySQL initialization failed. Review the local error log.' }
}
# init-file runs before network connections are accepted. No empty-password
# server is exposed, and access is restricted to the local machine.
$sql = @"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$($credentials.root)';
CREATE DATABASE IF NOT EXISTS asgard_environment CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'asgard_environment'@'127.0.0.1' IDENTIFIED BY '$($credentials.app)';
GRANT ALL PRIVILEGES ON asgard_environment.* TO 'asgard_environment'@'127.0.0.1';
CREATE USER IF NOT EXISTS 'asgard_admin'@'127.0.0.1' IDENTIFIED BY '$($credentials.admin)';
GRANT SHUTDOWN ON *.* TO 'asgard_admin'@'127.0.0.1';
CREATE DATABASE IF NOT EXISTS asgard_development CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'asgard_development'@'127.0.0.1' IDENTIFIED BY '$($credentials.application)';
GRANT ALL PRIVILEGES ON asgard_development.* TO 'asgard_development'@'127.0.0.1';
CREATE DATABASE IF NOT EXISTS asgard_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'asgard_test'@'127.0.0.1' IDENTIFIED BY '$($credentials.testing)';
GRANT ALL PRIVILEGES ON asgard_test.* TO 'asgard_test'@'127.0.0.1';
"@
[IO.File]::WriteAllText($initPath, $sql)
[IO.File]::WriteAllText($adminPath, "[client]`nuser=asgard_admin`npassword=$($credentials.admin)`nhost=127.0.0.1`nport=$port`n")
$process = Start-Process -FilePath $mysqlBinary -ArgumentList @("--defaults-file=$configPath", "--init-file=$initPath", '--no-monitor') -WorkingDirectory "$drive/" -WindowStyle Hidden -PassThru
$adminBinary = Join-Path $serverRoot 'bin/mysqladmin.exe'
$ready = $false
for ($attempt=0; $attempt -lt 40; $attempt++) {
    if ($process.HasExited) { throw 'MySQL exited before becoming ready. Review the local error log.' }
    & $adminBinary "--defaults-extra-file=$adminPath" status 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Milliseconds 250
}
if (-not $ready) { throw 'MySQL startup timed out. No process was killed automatically.' }
Remove-Item -LiteralPath $initPath
[IO.File]::WriteAllText((Join-Path $localRoot 'mysql.pid'), [string]$process.Id)
Write-Output "Dedicated MySQL ready at 127.0.0.1:$port. Credentials stay in ignored .local/."

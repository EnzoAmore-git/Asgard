$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
if (-not (Test-Path (Join-Path $projectRoot '.local/mysql-drive.txt'))) { throw 'Start the dedicated local MySQL first.' }
if (-not (Test-Path (Join-Path $projectRoot 'application/public/build/manifest.json'))) { throw 'Build application assets first.' }
if (Get-NetTCPConnection -State Listen -LocalPort 8000 -ErrorAction SilentlyContinue) { throw 'Port 8000 is in use. No existing process changed.' }
$process = Start-Process -FilePath 'S:/.local/php/php.exe' -ArgumentList @('-d','upload_max_filesize=10M','-d','post_max_size=12M','-S','127.0.0.1:8000','-t','.','S:/application/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php') -WorkingDirectory 'S:/application/public' -WindowStyle Hidden -RedirectStandardOutput (Join-Path $projectRoot '.local/application-stdout.log') -RedirectStandardError (Join-Path $projectRoot '.local/application-stderr.log') -PassThru
[IO.File]::WriteAllText((Join-Path $projectRoot '.local/application.pid'),[string]$process.Id)
Write-Output 'Local application started: http://127.0.0.1:8000. Bound to this computer only.'

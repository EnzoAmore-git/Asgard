$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$pidFile = Join-Path $projectRoot '.local/application.pid'
$ownedPid = [int]([IO.File]::ReadAllText($pidFile))
$process = Get-CimInstance Win32_Process -Filter "ProcessId=$ownedPid"
if (-not $process -or $process.ExecutablePath -ne 'S:\.local\php\php.exe' -or $process.CommandLine -notlike '*127.0.0.1:8000*') { throw 'Unexpected process. Nothing stopped.' }
Stop-Process -Id $ownedPid
Remove-Item -LiteralPath $pidFile
Write-Output 'Dedicated local application stopped.'

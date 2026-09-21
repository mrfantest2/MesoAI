[CmdletBinding()]
param(
  [string]$RepoRoot=(Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [string]$Target='C:\laragon\www\meso',
  [string]$PhpCli='C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe',
  [string]$PrivateRoot='C:\MesoAI\private',
  [string]$RuntimeRoot='C:\MesoAI\runtime'
)
$ErrorActionPreference='Stop'

$web=Join-Path $RepoRoot 'web'
$sttHelper=Join-Path $RepoRoot 'tools\transcribe_chat_audio.py'
$ttsHelper=Join-Path $RepoRoot 'tools\meso_xtts_client.py'
$xttsServer=Join-Path $RepoRoot 'tools\meso_xtts_native_server.py'
$sttPython=Join-Path $RuntimeRoot 'stt-venv\Scripts\python.exe'
$ttsPython=Join-Path $RuntimeRoot 'xtts-venv\Scripts\python.exe'
$sttRuntime=Join-Path $RuntimeRoot 'chat-stt'
$ttsRuntime=Join-Path $RuntimeRoot 'xtts-bridge'
$memoryDb=Join-Path $PrivateRoot 'memory-v1\meso-memory.sqlite'
$personaProfile=Join-Path $PrivateRoot 'persona-v2\profile.json'

foreach($p in @($web,$sttHelper,$ttsHelper,$xttsServer,$sttPython,$ttsPython,$PhpCli,$memoryDb,$personaProfile)){
  if(!(Test-Path -LiteralPath $p)){throw "Required MesoAI recovery input missing: $p"}
}

$mods=& $PhpCli -m
if($LASTEXITCODE -ne 0 -or -not (@($mods) -match '^pdo_sqlite$') -or -not (@($mods) -match '^curl$')){
  throw 'Laragon PHP requires pdo_sqlite and curl for MesoAI chat'
}

New-Item -ItemType Directory -Force -Path $Target,$sttRuntime,$ttsRuntime | Out-Null
Copy-Item -LiteralPath $sttHelper -Destination (Join-Path $sttRuntime 'transcribe_chat_audio.py') -Force
Copy-Item -LiteralPath $ttsHelper -Destination (Join-Path $ttsRuntime 'meso_xtts_client.py') -Force
& $sttPython -m py_compile (Join-Path $sttRuntime 'transcribe_chat_audio.py')
if($LASTEXITCODE -ne 0){throw 'STT helper compile failed'}
& $ttsPython -m py_compile (Join-Path $ttsRuntime 'meso_xtts_client.py')
if($LASTEXITCODE -ne 0){throw 'XTTS helper compile failed'}
& $ttsPython -m py_compile $xttsServer
if($LASTEXITCODE -ne 0){throw 'Native XTTS server compile failed'}

$backup=Join-Path $PrivateRoot ('web-backups\laragon-'+(Get-Date -Format 'yyyyMMdd-HHmmss'))
if(Test-Path -LiteralPath $Target){
  New-Item -ItemType Directory -Force -Path $backup | Out-Null
  Get-ChildItem -LiteralPath $Target -Force -ErrorAction SilentlyContinue | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination $backup -Recurse -Force
  }
}

Get-ChildItem -LiteralPath $Target -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force
Copy-Item -Path (Join-Path $web '*') -Destination $Target -Recurse -Force

& $PhpCli -l (Join-Path $Target 'api\transcribe.php') | Out-Host
if($LASTEXITCODE -ne 0){throw 'transcribe.php syntax validation failed'}
& $PhpCli -l (Join-Path $Target 'api\tts.php') | Out-Host
if($LASTEXITCODE -ne 0){throw 'tts.php syntax validation failed'}

try {
  $response=Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1/meso/' -TimeoutSec 8
  if([int]$response.StatusCode -ne 200){throw "HTTP $([int]$response.StatusCode)"}
} catch {
  throw "Laragon MesoAI HTTP validation failed: $($_.Exception.Message)"
}

Write-Host 'MESO_LARAGON_DEPLOY=PASS'
Write-Host "Live: $Target"
Write-Host "Private backup: $backup"

[CmdletBinding()]
param(
  [string]$RepoRoot=(Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [int]$Port=8020
)
$ErrorActionPreference='Stop'

if($env:COQUI_TOS_AGREED -ne '1'){
  throw 'XTTS not started: explicit Coqui license acceptance is required. Set COQUI_TOS_AGREED=1 only after the applicable license has been reviewed and accepted.'
}

$python='C:\MesoAI\runtime\xtts-venv\Scripts\python.exe'
$appDir=Join-Path $RepoRoot 'tools'
$server=Join-Path $appDir 'meso_xtts_native_server.py'
if(!(Test-Path $python)){throw "Missing XTTS Python: $python"}
if(!(Test-Path $server)){throw "Missing XTTS server: $server"}

$existing=Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if($existing){
  Write-Host "XTTS already listening on 127.0.0.1:$Port"
  exit 0
}

& $python -m uvicorn meso_xtts_native_server:app --app-dir $appDir --host 127.0.0.1 --port $Port

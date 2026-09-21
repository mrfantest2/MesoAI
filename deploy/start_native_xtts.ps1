[CmdletBinding()]
param(
  [string]$RepoRoot=(Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [int]$Port=8020
)
$ErrorActionPreference='Stop'

$licenseMarker='C:\MesoAI\private\licenses\coqui-cpml.accepted.txt'
if(!(Test-Path -LiteralPath $licenseMarker)){
  throw 'XTTS not started: Coqui CPML acceptance marker is missing. Run install_native_xtts.ps1 -AcceptCPML after explicit acceptance.'
}

$python='C:\MesoAI\runtime\xtts-venv\Scripts\python.exe'
$appDir=Join-Path $RepoRoot 'tools'
$server=Join-Path $appDir 'meso_xtts_native_server.py'
if(!(Test-Path $python)){throw "Missing XTTS Python: $python"}
if(!(Test-Path $server)){throw "Missing XTTS server: $server"}

$existing=Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if($existing){
  try {
    $health=Invoke-RestMethod "http://127.0.0.1:$Port/health" -TimeoutSec 5
    if($health.ok -eq $true){Write-Host "XTTS already healthy on 127.0.0.1:$Port"; exit 0}
  } catch {}
}

$env:COQUI_TOS_AGREED='1'
& $python -m uvicorn meso_xtts_native_server:app --app-dir $appDir --host 127.0.0.1 --port $Port

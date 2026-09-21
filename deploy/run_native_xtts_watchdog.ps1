[CmdletBinding()]
param(
  [string]$RepoRoot=(Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [int]$Port=8020
)
$ErrorActionPreference='Stop'

$licenseMarker='C:\MesoAI\private\licenses\coqui-cpml.accepted.txt'
if(!(Test-Path -LiteralPath $licenseMarker)){throw 'Coqui CPML acceptance marker missing'}

$python='C:\MesoAI\runtime\xtts-venv\Scripts\python.exe'
$appDir=Join-Path $RepoRoot 'tools'
if(!(Test-Path -LiteralPath $python)){throw 'XTTS runtime missing'}

$listener=Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if($listener){
  try {
    $health=Invoke-RestMethod "http://127.0.0.1:$Port/health" -TimeoutSec 5
    if($health.ok -eq $true){Write-Host 'MESO_XTTS_ALREADY_HEALTHY=true'; exit 0}
  } catch {}
  foreach($item in @($listener)){try{Stop-Process -Id $item.OwningProcess -Force -ErrorAction Stop}catch{}}
}

$env:COQUI_TOS_AGREED='1'
& $python -m uvicorn meso_xtts_native_server:app --app-dir $appDir --host 127.0.0.1 --port $Port

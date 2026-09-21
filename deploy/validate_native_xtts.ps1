[CmdletBinding()]
param(
  [string]$RuntimeRoot='C:\MesoAI\runtime',
  [string]$PrivateRoot='C:\MesoAI\private',
  [int]$Port=8020
)
$ErrorActionPreference='Stop'

$py=Join-Path $RuntimeRoot 'xtts-venv\Scripts\python.exe'
$client=Join-Path $RuntimeRoot 'xtts-bridge\meso_xtts_client.py'
$en=Join-Path $RuntimeRoot 'xtts-validation-en.mp3'
$ar=Join-Path $RuntimeRoot 'xtts-validation-ar.mp3'

$health=Invoke-RestMethod "http://127.0.0.1:$Port/health" -TimeoutSec 10
if($health.ok -ne $true -or $health.engine -ne 'xtts-v2'){throw 'XTTS health contract failed'}

'{"text":"Hello. MesoAI XTTS clean recovery validation.","language":"en"}' | & $py $client --output $en
if($LASTEXITCODE -ne 0 -or !(Test-Path $en)){throw 'English XTTS validation failed'}

'{"text":"مرحبا. هذا اختبار استعادة صوت ميسو بالـ XTTS.","language":"ar"}' | & $py $client --output $ar
if($LASTEXITCODE -ne 0 -or !(Test-Path $ar)){throw 'Arabic XTTS validation failed'}

foreach($file in @($en,$ar)){
  $info=Get-Item $file
  if($info.Length -lt 1024){throw "Invalid XTTS output: $($info.FullName)"}
  Write-Host ("XTTS_VALIDATION_AUDIO={0} BYTES={1}" -f $info.FullName,$info.Length)
}

Write-Host ("MESO_XTTS_VALIDATION=PASS DEVICE={0} GPU={1}" -f $health.device,$health.gpu)

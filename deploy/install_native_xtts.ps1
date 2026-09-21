[CmdletBinding()]
param(
  [switch]$AcceptCPML,
  [string]$RuntimeRoot='C:\MesoAI\runtime',
  [string]$PrivateRoot='C:\MesoAI\private',
  [string]$Python='C:\Program Files\Python311\python.exe'
)
$ErrorActionPreference='Stop'

if(!$AcceptCPML){
  throw 'Explicit CPML acceptance required. Re-run with -AcceptCPML after reviewing the applicable Coqui license.'
}

$licenseRoot=Join-Path $PrivateRoot 'licenses'
$licenseMarker=Join-Path $licenseRoot 'coqui-cpml.accepted.txt'
$venv=Join-Path $RuntimeRoot 'xtts-venv'
$bridge=Join-Path $RuntimeRoot 'xtts-bridge'
$repoRoot=Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$client=Join-Path $repoRoot 'tools\meso_xtts_client.py'
$server=Join-Path $repoRoot 'tools\meso_xtts_native_server.py'

foreach($p in @($Python,$client,$server)){
  if(!(Test-Path -LiteralPath $p)){throw "Required XTTS recovery input missing: $p"}
}

New-Item -ItemType Directory -Force -Path $RuntimeRoot,$PrivateRoot,$licenseRoot | Out-Null
@(
  'license=Coqui CPML',
  'accepted=true',
  ('accepted_at='+[DateTimeOffset]::Now.ToString('o')),
  'scope=MesoAI XTTS local runtime',
  'source=user explicit acceptance in ChatGPT recovery workflow'
) | Set-Content -LiteralPath $licenseMarker -Encoding UTF8

$existing=Get-NetTCPConnection -LocalPort 8020 -State Listen -ErrorAction SilentlyContinue
foreach($listener in @($existing)){
  try { Stop-Process -Id $listener.OwningProcess -Force -ErrorAction Stop } catch {}
}

if(Test-Path -LiteralPath $venv){
  Remove-Item -LiteralPath $venv -Recurse -Force
}
if(Test-Path -LiteralPath $bridge){
  Remove-Item -LiteralPath $bridge -Recurse -Force
}

& $Python -m venv $venv
$py=Join-Path $venv 'Scripts\python.exe'
& $py -m pip install --upgrade pip setuptools wheel
& $py -m pip install 'torch==2.8.0' 'torchaudio==2.8.0' --index-url https://download.pytorch.org/whl/cu126
& $py -m pip install 'coqui-tts==0.27.5' 'transformers==4.57.6' fastapi 'uvicorn[standard]'

New-Item -ItemType Directory -Force -Path $bridge | Out-Null
Copy-Item -LiteralPath $client -Destination (Join-Path $bridge 'meso_xtts_client.py') -Force
& $py -m py_compile (Join-Path $bridge 'meso_xtts_client.py')
& $py -m py_compile $server

& $py -c "import torch,torchaudio,transformers; from TTS.api import TTS; ref=r'C:\\MesoAI\\private\\profile-v1\\source\\normalized\\meso_ref_01.wav'; wav,sr=torchaudio.load(ref); print('TORCH',torch.__version__,'TORCHAUDIO',torchaudio.__version__,'CUDA',torch.cuda.is_available(),'GPU',torch.cuda.get_device_name(0) if torch.cuda.is_available() else 'CPU','TRANSFORMERS',transformers.__version__,'AUDIO',tuple(wav.shape),sr)"

Write-Host 'MESO_XTTS_CLEAN_INSTALL=PASS'
Write-Host "License marker: $licenseMarker"

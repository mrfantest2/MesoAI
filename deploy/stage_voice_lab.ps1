param(
  [string]$RepoRoot=(Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [string]$Runtime='C:\ProgramData\KhalilDigitalTwin\meso\xtts-bridge',
  [string]$Python='C:\ProgramData\KhalilDigitalTwin\meso\xtts-venv\Scripts\python.exe'
)
$ErrorActionPreference='Stop'
$source=Join-Path $RepoRoot 'tools\meso_xtts_lab_client.py'
$base=Join-Path $RepoRoot 'tools\meso_xtts_client.py'
foreach($p in @($source,$base,$Python)){if(!(Test-Path -LiteralPath $p -PathType Leaf)){throw "Voice lab stage input missing: $p"}}
New-Item -ItemType Directory -Force -Path $Runtime|Out-Null
Copy-Item -LiteralPath $base -Destination (Join-Path $Runtime 'meso_xtts_client.py') -Force
Copy-Item -LiteralPath $source -Destination (Join-Path $Runtime 'meso_xtts_lab_client.py') -Force
& $Python -m py_compile (Join-Path $Runtime 'meso_xtts_client.py') (Join-Path $Runtime 'meso_xtts_lab_client.py')
if($LASTEXITCODE -ne 0){throw 'Meso Voice Lab helper compile failed'}
Write-Host 'MESO_VOICE_LAB_RUNTIME_STAGED=true'

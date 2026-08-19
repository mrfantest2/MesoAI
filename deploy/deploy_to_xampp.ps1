param(
  [string]$RepoRoot = (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [string]$Target = 'C:\xampp\htdocs\meso',
  [string]$BackupRoot = 'C:\MesoAI\private\web-backups',
  [string]$ChatSttRuntime = 'C:\ProgramData\KhalilDigitalTwin\meso\chat-stt',
  [string]$ChatSttPython = 'C:\ProgramData\KhalilDigitalTwin\meso\fish-whisper-venv\Scripts\python.exe',
  [string]$ChatTtsRuntime = 'C:\ProgramData\KhalilDigitalTwin\meso\xtts-bridge',
  [string]$ChatTtsPython = 'C:\ProgramData\KhalilDigitalTwin\meso\xtts-venv\Scripts\python.exe'
)
$ErrorActionPreference = 'Stop'
$web = Join-Path $RepoRoot 'web'
$chatSttHelper = Join-Path $RepoRoot 'tools\transcribe_chat_audio.py'
$chatTtsHelper = Join-Path $RepoRoot 'tools\meso_xtts_client.py'
$pwaIconGenerator = Join-Path $RepoRoot 'deploy\generate_pwa_icons.ps1'
$MesoVoiceSource = 'C:\MesoAI\private\profile-v1\source\normalized\meso_ref_01.wav'
$MesoVoiceVolume = 'khalil-digital-twin_khalil-data'
$MesoVoiceAllowedRoot = '/data/voice/profiles/khalil'
$MesoVoiceContainerDir = '/data/voice/profiles/khalil/meso/refs'
$MesoVoiceContainerPath = '/data/voice/profiles/khalil/meso/refs/meso_ref_01.wav'

if (!(Test-Path -LiteralPath $web -PathType Container)) { throw "Web source not found: $web" }
if (!(Test-Path -LiteralPath $chatSttHelper -PathType Leaf)) { throw "Meso chat STT helper not found: $chatSttHelper" }
if (!(Test-Path -LiteralPath $chatTtsHelper -PathType Leaf)) { throw "Meso chat XTTS helper not found: $chatTtsHelper" }
if (!(Test-Path -LiteralPath $pwaIconGenerator -PathType Leaf)) { throw "Meso PWA icon generator not found: $pwaIconGenerator" }
if (!(Test-Path -LiteralPath $ChatSttPython -PathType Leaf)) { throw "Meso local faster-whisper runtime not found: $ChatSttPython" }
if (!(Test-Path -LiteralPath $ChatTtsPython -PathType Leaf)) { throw "Meso local XTTS runtime not found: $ChatTtsPython" }
if (!(Test-Path -LiteralPath $MesoVoiceSource -PathType Leaf)) { throw 'Reviewed Meso A reference is missing from the private MASTER-PC source.' }

New-Item -ItemType Directory -Force -Path $Target | Out-Null
New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null
New-Item -ItemType Directory -Force -Path $ChatSttRuntime | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $ChatSttRuntime 'tmp') | Out-Null
New-Item -ItemType Directory -Force -Path $ChatTtsRuntime | Out-Null

# Provision only the reviewed Meso A speaker reference into an isolated child
# of the XTTS service's existing approved read-only voice root. The running
# khalil-xtts container remains read-only; a short-lived sidecar mounts the
# same named volume read/write, performs an atomic replacement, then exits.
$xttsInspect = & docker inspect khalil-xtts | ConvertFrom-Json
if ($LASTEXITCODE -ne 0 -or !$xttsInspect) { throw 'Could not inspect the local XTTS container.' }
$dataMount = @($xttsInspect[0].Mounts | Where-Object { $_.Destination -eq '/data' })
if ($dataMount.Count -ne 1 -or [string]$dataMount[0].Type -ne 'volume') { throw 'Expected one named XTTS /data volume.' }
if ([string]$dataMount[0].Name -ne $MesoVoiceVolume) { throw "Unexpected XTTS data volume: $([string]$dataMount[0].Name)" }
$xttsImage = [string]$xttsInspect[0].Config.Image
if ([string]::IsNullOrWhiteSpace($xttsImage)) { throw 'Could not resolve the local XTTS image.' }

$voiceStager = 'meso-voice-stage-' + $PID + '-' + (Get-Random -Minimum 100000 -Maximum 999999)
$voiceTempPath = $MesoVoiceContainerDir + '/.meso_ref_01.' + $PID + '.tmp'
try {
  $containerId = (& docker run -d --name $voiceStager --user 0 -v "${MesoVoiceVolume}:/data" --entrypoint python $xttsImage -c 'import time; time.sleep(600)').Trim()
  if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($containerId)) { throw 'Could not start Meso voice volume staging sidecar.' }

  & docker exec -u 0 $voiceStager python -c "import os; os.makedirs('$MesoVoiceContainerDir', exist_ok=True); os.chmod('$MesoVoiceContainerDir', 0o755)"
  if ($LASTEXITCODE -ne 0) { throw 'Could not prepare Meso voice directory in the XTTS data volume.' }

  & docker cp $MesoVoiceSource "${voiceStager}:$voiceTempPath"
  if ($LASTEXITCODE -ne 0) { throw 'Could not copy the reviewed Meso reference into the XTTS data volume.' }

  $hostHash = (Get-FileHash -LiteralPath $MesoVoiceSource -Algorithm SHA256).Hash.ToLowerInvariant()
  $volumeHash = (& docker exec -u 0 $voiceStager python -c "import hashlib; print(hashlib.sha256(open('$voiceTempPath','rb').read()).hexdigest())").Trim().ToLowerInvariant()
  if ($LASTEXITCODE -ne 0 -or $hostHash -ne $volumeHash) { throw 'Meso voice reference integrity verification failed.' }

  & docker exec -u 0 $voiceStager python -c "import os; os.chmod('$voiceTempPath',0o444); os.replace('$voiceTempPath','$MesoVoiceContainerPath'); os.chmod('$MesoVoiceContainerPath',0o444)"
  if ($LASTEXITCODE -ne 0) { throw 'Could not atomically publish the reviewed Meso reference.' }

  & docker exec khalil-xtts python -c "from pathlib import Path; p=Path('$MesoVoiceContainerPath').resolve(); root=Path('$MesoVoiceAllowedRoot').resolve(); p.relative_to(root); assert p.is_file() and p.stat().st_size > 0"
  if ($LASTEXITCODE -ne 0) { throw 'Running XTTS service cannot read the reviewed Meso reference.' }
  Write-Host 'MESO_VOICE_REFERENCE_STAGED=true'
} finally {
  try { & docker exec -u 0 $voiceStager python -c "import os; p='$voiceTempPath'; os.path.exists(p) and os.remove(p)" | Out-Null } catch {}
  & docker rm -f $voiceStager 2>$null | Out-Null
}

# Stage executable speech helpers outside Apache's document root. Private audio
# remains below C:\MesoAI\private and is never copied into /meso.
Copy-Item -LiteralPath $chatSttHelper -Destination (Join-Path $ChatSttRuntime 'transcribe_chat_audio.py') -Force
& $ChatSttPython -m py_compile (Join-Path $ChatSttRuntime 'transcribe_chat_audio.py')
if ($LASTEXITCODE -ne 0) { throw 'Meso chat STT helper failed Python compile validation.' }
Write-Host 'MESO_CHAT_STT_RUNTIME_STAGED=true'

Copy-Item -LiteralPath $chatTtsHelper -Destination (Join-Path $ChatTtsRuntime 'meso_xtts_client.py') -Force
& $ChatTtsPython -m py_compile (Join-Path $ChatTtsRuntime 'meso_xtts_client.py')
if ($LASTEXITCODE -ne 0) { throw 'Meso chat XTTS helper failed Python compile validation.' }
Write-Host 'MESO_CHAT_XTTS_RUNTIME_STAGED=true'

# Older deploys stored _previous_* inside the public web root. Move those backups
# out of Apache's document root before installing the new version.
$legacy = Get-ChildItem -LiteralPath $Target -Force -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -like '_previous_*' }
foreach ($item in $legacy) {
  $name = 'legacy-' + $item.Name + '-' + (Get-Date -Format 'yyyyMMdd-HHmmssfff')
  Move-Item -LiteralPath $item.FullName -Destination (Join-Path $BackupRoot $name) -Force
  Write-Host "Moved legacy public backup out of web root: $($item.Name)"
}

# Preserve the currently deployed web copy privately, never below /meso.
$existing = Get-ChildItem -LiteralPath $Target -Force -ErrorAction SilentlyContinue
if ($existing) {
  $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
  $backup = Join-Path $BackupRoot "web-$stamp"
  New-Item -ItemType Directory -Force -Path $backup | Out-Null
  foreach ($item in $existing) { Move-Item -LiteralPath $item.FullName -Destination $backup -Force }
  Write-Host "Existing /meso web preserved privately in $backup"
}

Copy-Item -Path (Join-Path $web '*') -Destination $Target -Recurse -Force

# PNG app icons are generated on the Windows deployment target from versioned
# source code, avoiding binary icon blobs while still satisfying mobile PWA
# installability requirements.
& $pwaIconGenerator -TargetWebRoot $Target

$pwaRequired = @(
  'app.webmanifest',
  'sw.js',
  'offline.html',
  'pwa\install.js',
  'icons\meso-192.png',
  'icons\meso-512.png',
  'icons\meso-maskable-512.png',
  'icons\apple-touch-icon.png'
)
foreach ($relative in $pwaRequired) {
  $path = Join-Path $Target $relative
  if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required Meso PWA asset missing after deployment: $relative" }
}
Write-Host 'MESO_PWA_DEPLOYED=true'

# Defense in depth: no legacy backup directory may remain under the public root.
$publicBackups = Get-ChildItem -LiteralPath $Target -Force -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -like '_previous_*' }
if ($publicBackups) { throw 'Public _previous_* backup remained after deployment.' }

Write-Host "MesoAI web deployed to $Target"
Write-Host "Private web backups: $BackupRoot"
Write-Host "Local:  http://127.0.0.1/meso/"
Write-Host "Public: https://fantest.win/meso/"

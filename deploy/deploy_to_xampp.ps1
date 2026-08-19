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
if (!(Test-Path -LiteralPath $web -PathType Container)) { throw "Web source not found: $web" }
if (!(Test-Path -LiteralPath $chatSttHelper -PathType Leaf)) { throw "Meso chat STT helper not found: $chatSttHelper" }
if (!(Test-Path -LiteralPath $chatTtsHelper -PathType Leaf)) { throw "Meso chat XTTS helper not found: $chatTtsHelper" }
if (!(Test-Path -LiteralPath $pwaIconGenerator -PathType Leaf)) { throw "Meso PWA icon generator not found: $pwaIconGenerator" }
if (!(Test-Path -LiteralPath $ChatSttPython -PathType Leaf)) { throw "Meso local faster-whisper runtime not found: $ChatSttPython" }
if (!(Test-Path -LiteralPath $ChatTtsPython -PathType Leaf)) { throw "Meso local XTTS runtime not found: $ChatTtsPython" }
New-Item -ItemType Directory -Force -Path $Target | Out-Null
New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null
New-Item -ItemType Directory -Force -Path $ChatSttRuntime | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $ChatSttRuntime 'tmp') | Out-Null
New-Item -ItemType Directory -Force -Path $ChatTtsRuntime | Out-Null

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

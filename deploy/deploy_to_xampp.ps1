param(
  [string]$RepoRoot=(Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [string]$Target='C:\xampp\htdocs\meso',
  [string]$BackupRoot='C:\MesoAI\private\web-backups',
  [string]$ChatSttRuntime='C:\ProgramData\KhalilDigitalTwin\meso\chat-stt',
  [string]$ChatSttPython='C:\ProgramData\KhalilDigitalTwin\meso\fish-whisper-venv\Scripts\python.exe',
  [string]$ChatTtsRuntime='C:\ProgramData\KhalilDigitalTwin\meso\xtts-bridge',
  [string]$ChatTtsPython='C:\ProgramData\KhalilDigitalTwin\meso\xtts-venv\Scripts\python.exe',
  [string]$PhpCli='C:\xampp\php\php.exe'
)
$ErrorActionPreference='Stop'
$web=Join-Path $RepoRoot 'web'
$chatSttHelper=Join-Path $RepoRoot 'tools\transcribe_chat_audio.py'
$chatTtsHelper=Join-Path $RepoRoot 'tools\meso_xtts_client.py'
$memoryBootstrap=Join-Path $RepoRoot 'tools\memory_v1_bootstrap.php'
$pwaIconGenerator=Join-Path $RepoRoot 'deploy\generate_pwa_icons.ps1'
$personaSeed=Join-Path $RepoRoot 'deploy\persona-v1.seed.json'
$personaV2Seed=Join-Path $RepoRoot 'deploy\persona-v2.seed.json'
$memoryRoot='C:\MesoAI\private\memory-v1'
$personaDir='C:\MesoAI\private\persona-v1'
$personaProfile='C:\MesoAI\private\persona-v1\profile.json'
$personaV2Dir='C:\MesoAI\private\persona-v2'
$personaV2Profile='C:\MesoAI\private\persona-v2\profile.json'
$personaV2Corpus='C:\MesoAI\private\persona-v2\corpus.jsonl'
$MesoVoiceSource='C:\MesoAI\private\profile-v1\source\normalized\meso_ref_01.wav'
$MesoVoiceVolume='khalil-digital-twin_khalil-data'
$MesoVoiceAllowedRoot='/data/voice/profiles/khalil'
$MesoVoiceContainerDir='/data/voice/profiles/khalil/meso/refs'
$MesoVoiceContainerPath='/data/voice/profiles/khalil/meso/refs/meso_ref_01.wav'

foreach($p in @($web,$chatSttHelper,$chatTtsHelper,$memoryBootstrap,$pwaIconGenerator,$personaSeed,$personaV2Seed,$ChatSttPython,$ChatTtsPython,$PhpCli,$MesoVoiceSource)){if(!(Test-Path -LiteralPath $p)){throw "Required deploy input missing: $p"}}
New-Item -ItemType Directory -Force -Path $Target,$BackupRoot,$ChatSttRuntime,(Join-Path $ChatSttRuntime 'tmp'),$ChatTtsRuntime,$memoryRoot,$personaDir,$personaV2Dir|Out-Null

# Memory v1 is a private SQLite store outside htdocs. Bootstrap initializes only
# schema 0 -> 1, preserves existing data, and rejects any newer schema.
$mods=& $PhpCli -m
if($LASTEXITCODE -ne 0 -or -not (@($mods) -match '^pdo_sqlite$')){throw 'Meso Memory v1 requires XAMPP PHP pdo_sqlite'}
$raw=& $PhpCli $memoryBootstrap
if($LASTEXITCODE -ne 0){throw 'Meso Memory v1 bootstrap failed'}
$memoryStatus=($raw -join "`n")|ConvertFrom-Json
if($memoryStatus.ok -ne $true -or [int]$memoryStatus.schema -ne 1 -or [string]$memoryStatus.memory -ne 'meso-memory-v1'){throw 'Meso Memory v1 bootstrap contract failed'}
Write-Host 'MESO_MEMORY_V1_READY=true SCHEMA=1'

# Persona v1 remains a safe fallback. Application deploys never overwrite a
# previously installed private profile.
if(!(Test-Path -LiteralPath $personaProfile -PathType Leaf)){
  Copy-Item -LiteralPath $personaSeed -Destination $personaProfile -Force
  $p=Get-Content -LiteralPath $personaProfile -Raw|ConvertFrom-Json
  if($p.version -ne 'meso-v1' -or -not $p.enabled){throw 'Persona v1 seed validation failed'}
}
Write-Host 'MESO_PERSONA_PROFILE_STAGED=true'

# Persona v2 is installed out-of-band as a private corpus. Detect it, verify its
# integrity, and leave it untouched. Nothing under persona-v2 is copied to htdocs.
$v2ProfileExists=Test-Path -LiteralPath $personaV2Profile -PathType Leaf
$v2CorpusExists=Test-Path -LiteralPath $personaV2Corpus -PathType Leaf
if($v2ProfileExists -xor $v2CorpusExists){throw 'Persona v2 private profile/corpus pair is incomplete'}
if($v2ProfileExists -and $v2CorpusExists){
  $v2=Get-Content -LiteralPath $personaV2Profile -Raw|ConvertFrom-Json
  if($v2.version -ne 'meso-v2' -or -not $v2.enabled -or $v2.grounding -ne 'evidence-retrieval'){throw 'Persona v2 private profile contract failed'}
  $expected=[string]$v2.corpus_sha256
  $actual=(Get-FileHash -LiteralPath $personaV2Corpus -Algorithm SHA256).Hash.ToLowerInvariant()
  if([string]::IsNullOrWhiteSpace($expected) -or $expected.ToLowerInvariant() -ne $actual){throw 'Persona v2 corpus integrity verification failed'}
  if([int]$v2.record_count -lt 1){throw 'Persona v2 corpus record_count is empty'}
  Write-Host "MESO_PERSONA_V2_DETECTED=true RECORDS=$([int]$v2.record_count) SOURCES=$([int]$v2.source_count)"
}else{
  Write-Host 'MESO_PERSONA_V2_DETECTED=false'
}

# Preserve the reviewed Meso A fallback in the protected XTTS data volume.
$xttsInspect=& docker inspect khalil-xtts|ConvertFrom-Json
if($LASTEXITCODE -ne 0 -or !$xttsInspect){throw 'Could not inspect local XTTS container'}
$dataMount=@($xttsInspect[0].Mounts|Where-Object{$_.Destination -eq '/data'})
if($dataMount.Count -ne 1 -or [string]$dataMount[0].Type -ne 'volume'){throw 'Expected one named XTTS /data volume'}
if([string]$dataMount[0].Name -ne $MesoVoiceVolume){throw "Unexpected XTTS data volume: $([string]$dataMount[0].Name)"}
$xttsImage=[string]$xttsInspect[0].Config.Image;if([string]::IsNullOrWhiteSpace($xttsImage)){throw 'Could not resolve XTTS image'}
$voiceStager='meso-voice-stage-'+$PID+'-'+(Get-Random -Minimum 100000 -Maximum 999999)
$voiceTempPath=$MesoVoiceContainerDir+'/.meso_ref_01.'+$PID+'.tmp'
try{
  $cid=(& docker run -d --name $voiceStager --user 0 -v "${MesoVoiceVolume}:/data" --entrypoint python $xttsImage -c 'import time; time.sleep(600)').Trim()
  if($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($cid)){throw 'Could not start Meso voice staging sidecar'}
  & docker exec -u 0 $voiceStager python -c "import os; os.makedirs('$MesoVoiceContainerDir',exist_ok=True); os.chmod('$MesoVoiceContainerDir',0o755)";if($LASTEXITCODE -ne 0){throw 'Could not prepare Meso voice directory'}
  & docker cp $MesoVoiceSource "${voiceStager}:$voiceTempPath";if($LASTEXITCODE -ne 0){throw 'Could not copy reviewed Meso reference'}
  $hostHash=(Get-FileHash -LiteralPath $MesoVoiceSource -Algorithm SHA256).Hash.ToLowerInvariant()
  $volumeHash=(& docker exec -u 0 $voiceStager python -c "import hashlib; print(hashlib.sha256(open('$voiceTempPath','rb').read()).hexdigest())").Trim().ToLowerInvariant()
  if($hostHash -ne $volumeHash){throw 'Meso voice reference integrity verification failed'}
  & docker exec -u 0 $voiceStager python -c "import os; os.chmod('$voiceTempPath',0o444); os.replace('$voiceTempPath','$MesoVoiceContainerPath'); os.chmod('$MesoVoiceContainerPath',0o444)";if($LASTEXITCODE -ne 0){throw 'Could not publish reviewed Meso reference'}
  & docker exec khalil-xtts python -c "from pathlib import Path; p=Path('$MesoVoiceContainerPath').resolve(); root=Path('$MesoVoiceAllowedRoot').resolve(); p.relative_to(root); assert p.is_file() and p.stat().st_size>0";if($LASTEXITCODE -ne 0){throw 'Running XTTS cannot read reviewed Meso reference'}
  Write-Host 'MESO_VOICE_REFERENCE_STAGED=true'
}finally{try{& docker exec -u 0 $voiceStager python -c "import os; p='$voiceTempPath'; os.path.exists(p) and os.remove(p)"|Out-Null}catch{};& docker rm -f $voiceStager 2>$null|Out-Null}

Copy-Item -LiteralPath $chatSttHelper -Destination (Join-Path $ChatSttRuntime 'transcribe_chat_audio.py') -Force
& $ChatSttPython -m py_compile (Join-Path $ChatSttRuntime 'transcribe_chat_audio.py');if($LASTEXITCODE -ne 0){throw 'Meso chat STT helper compile failed'}
Write-Host 'MESO_CHAT_STT_RUNTIME_STAGED=true'
Copy-Item -LiteralPath $chatTtsHelper -Destination (Join-Path $ChatTtsRuntime 'meso_xtts_client.py') -Force
& $ChatTtsPython -m py_compile (Join-Path $ChatTtsRuntime 'meso_xtts_client.py');if($LASTEXITCODE -ne 0){throw 'Meso chat XTTS helper compile failed'}
Write-Host 'MESO_CHAT_XTTS_RUNTIME_STAGED=true'

$legacy=Get-ChildItem -LiteralPath $Target -Force -Directory -ErrorAction SilentlyContinue|Where-Object{$_.Name -like '_previous_*'}
foreach($item in $legacy){$name='legacy-'+$item.Name+'-'+(Get-Date -Format 'yyyyMMdd-HHmmssfff');Move-Item -LiteralPath $item.FullName -Destination (Join-Path $BackupRoot $name) -Force}
$existing=Get-ChildItem -LiteralPath $Target -Force -ErrorAction SilentlyContinue
if($existing){$backup=Join-Path $BackupRoot ('web-'+(Get-Date -Format 'yyyyMMdd-HHmmss'));New-Item -ItemType Directory -Force -Path $backup|Out-Null;foreach($item in $existing){Move-Item -LiteralPath $item.FullName -Destination $backup -Force};Write-Host "Existing /meso web preserved privately in $backup"}
Copy-Item -Path (Join-Path $web '*') -Destination $Target -Recurse -Force
& $pwaIconGenerator -TargetWebRoot $Target
$pwaRequired=@('app.webmanifest','sw.js','offline.html','pwa\install.js','icons\meso-192.png','icons\meso-512.png','icons\meso-maskable-512.png','icons\apple-touch-icon.png')
foreach($relative in $pwaRequired){$path=Join-Path $Target $relative;if(!(Test-Path -LiteralPath $path -PathType Leaf)){throw "Required Meso PWA asset missing: $relative"}}
Write-Host 'MESO_PWA_DEPLOYED=true'
if(Get-ChildItem -LiteralPath $Target -Force -Directory -ErrorAction SilentlyContinue|Where-Object{$_.Name -like '_previous_*'}){throw 'Public backup remained after deployment'}
Write-Host "MesoAI web deployed to $Target"
Write-Host "Private web backups: $BackupRoot"
Write-Host 'Local:  http://127.0.0.1/meso/'
Write-Host 'Public: https://fantest.win/meso/'

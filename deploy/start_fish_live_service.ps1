[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$PodId,
    [Parameter(Mandatory=$true)][string]$RemoteHost,
    [Parameter(Mandatory=$true)][int]$RemotePort,
    [Parameter(Mandatory=$true)][string]$ExpectedHostKeySha256,
    [Parameter(Mandatory=$true)][string]$SshKeyPath,
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$ReferenceAudio = 'C:\MesoAI\private\profile-v1\source\normalized\meso_ref_01.wav',
    [string]$ReferenceTranscript = 'C:\MesoAI\private\fish-s2\meso\reference.txt',
    [string]$LicenseAcceptanceJson = 'C:\MesoAI\private\fish-license-acceptance.json',
    [string]$PrepJson = 'C:\MesoAI\private\fish-s2\meso\prep.json',
    [string]$ExpectedReferenceSha256 = 'e7170ed139962f3945d990f3b9a793e85c8c9e7af7c1f59c18dbef8df08c95b8',
    [int]$LiveMinutes = 30
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($PodId -notmatch '^[a-z0-9]+$') { throw 'Invalid RunPod ID.' }
if ($RemoteHost -notmatch '^[0-9A-Fa-f:.]+$') { throw 'Invalid RunPod host.' }
if ($RemotePort -lt 1 -or $RemotePort -gt 65535) { throw 'Invalid SSH port.' }
if ($ExpectedHostKeySha256 -notmatch '^SHA256:[A-Za-z0-9+/=]+$') { throw 'Invalid expected SSH fingerprint.' }
if ($LiveMinutes -lt 5 -or $LiveMinutes -gt 60) { throw 'Live preflight duration must be 5-60 minutes.' }

foreach ($path in @($SshKeyPath,$ReferenceAudio,$ReferenceTranscript,$LicenseAcceptanceJson,$PrepJson)) {
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required private Meso Fish input missing: $path" }
}
$launcher = Join-Path $RepoRoot 'tools\start_fish_s2_live_api.sh'
$stageClient = Join-Path $RepoRoot 'deploy\stage_fish_live_client.ps1'
$deployWeb = Join-Path $RepoRoot 'deploy\deploy_to_xampp.ps1'
foreach ($path in @($launcher,$stageClient,$deployWeb)) {
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required Meso live tooling missing: $path" }
}

$license = Get-Content -LiteralPath $LicenseAcceptanceJson -Raw | ConvertFrom-Json
if ($license.accepted -ne $true -or [string]$license.scope -notmatch '(?i)non-commercial') {
    throw 'Fish Audio Research License acceptance record is not valid for Meso private evaluation.'
}
$prep = Get-Content -LiteralPath $PrepJson -Raw | ConvertFrom-Json
if ($prep.ready -ne $true -or [string]$prep.project -ne 'meso') { throw 'Private Meso Fish prep manifest is not ready.' }
$refHash = (Get-FileHash -LiteralPath $ReferenceAudio -Algorithm SHA256).Hash.ToLowerInvariant()
if ($refHash -ne $ExpectedReferenceSha256.ToLowerInvariant()) { throw "Meso Fish reference SHA256 mismatch: $refHash" }
$transcriptText = (Get-Content -LiteralPath $ReferenceTranscript -Raw).Trim()
if ([string]::IsNullOrWhiteSpace($transcriptText) -or $transcriptText.Length -gt 4000) { throw 'Meso Fish reference transcript is invalid.' }
$transcriptHash = (Get-FileHash -LiteralPath $ReferenceTranscript -Algorithm SHA256).Hash.ToLowerInvariant()
Write-Host 'MESO_FISH_LIVE_LICENSE_VALID=true'
Write-Host "MESO_FISH_LIVE_REFERENCE_SHA256=$refHash"
Write-Host "MESO_FISH_LIVE_TRANSCRIPT_SHA256=$transcriptHash"

$known = Join-Path $env:TEMP ('meso-fish-live-known-' + [guid]::NewGuid().ToString('N') + '.txt')
$staging = Join-Path $env:TEMP ('meso-fish-live-private-' + [guid]::NewGuid().ToString('N'))
$configPath = 'C:\MesoAI\private\fish-live\config.json'
$statePath = 'C:\MesoAI\private\fish-live\state.json'
$success = $false
$apiKey = $null
try {
    New-Item -ItemType Directory -Force -Path $staging | Out-Null
    $oldPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $scan = & ssh-keyscan -p $RemotePort $RemoteHost 2>$null
    $scanRc = $LASTEXITCODE
    $ErrorActionPreference = $oldPreference
    if ($scanRc -ne 0 -or [string]::IsNullOrWhiteSpace([string]($scan -join "`n"))) { throw 'Unable to capture RunPod SSH host key.' }
    [System.IO.File]::WriteAllLines($known, [string[]]$scan, (New-Object System.Text.ASCIIEncoding))
    $fingerprintLine = (& ssh-keygen -lf $known -E sha256 | Select-Object -First 1)
    if ($LASTEXITCODE -ne 0) { throw 'Unable to calculate RunPod SSH fingerprint.' }
    $match = [regex]::Match([string]$fingerprintLine,'SHA256:[A-Za-z0-9+/=]+')
    if (!$match.Success -or $match.Value -ne $ExpectedHostKeySha256) {
        throw "RunPod SSH fingerprint mismatch. expected=$ExpectedHostKeySha256 actual=$($match.Value)"
    }
    Write-Host 'MESO_FISH_LIVE_SSH_FINGERPRINT_VERIFIED=true'

    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $apiKey = [Convert]::ToBase64String($bytes)

    $normalizedTranscript = Join-Path $staging 'reference.txt'
    $normalizedLauncher = Join-Path $staging 'start_fish_s2_live_api.sh'
    $apiKeyFile = Join-Path $staging 'api-key.txt'
    [System.IO.File]::WriteAllText($normalizedTranscript, ($transcriptText + "`n"), (New-Object System.Text.UTF8Encoding($false)))
    $launcherText = (Get-Content -LiteralPath $launcher -Raw) -replace "`r`n", "`n" -replace "`r", "`n"
    [System.IO.File]::WriteAllText($normalizedLauncher, $launcherText, (New-Object System.Text.UTF8Encoding($false)))
    [System.IO.File]::WriteAllText($apiKeyFile, ($apiKey + "`n"), (New-Object System.Text.ASCIIEncoding))

    $sshArgs = @('-p',[string]$RemotePort,'-i',$SshKeyPath,'-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o',"UserKnownHostsFile=$known",'-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=20')
    $scpArgs = @('-P',[string]$RemotePort,'-i',$SshKeyPath,'-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o',"UserKnownHostsFile=$known",'-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=20')
    $remoteTarget = 'root@' + $RemoteHost

    $oldPreference = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & ssh @sshArgs $remoteTarget "rm -rf -- /workspace/meso-live-private; mkdir -p /workspace/meso-live-private; chmod 700 /workspace/meso-live-private"
    $rc = $LASTEXITCODE
    $ErrorActionPreference = $oldPreference
    if ($rc -ne 0) { throw 'Unable to create isolated Meso live private root.' }

    $transcriptDest = $remoteTarget + ':/workspace/meso-live-private/reference.txt'
    $keyDest = $remoteTarget + ':/workspace/meso-live-private/api-key.txt'
    $launcherDest = $remoteTarget + ':/workspace/meso-live-private/start_fish_s2_live_api.sh'
    $audioDest = $remoteTarget + ':/workspace/meso-live-private/reference.wav'
    foreach ($copy in @(
        @($ReferenceAudio,$audioDest),
        @($normalizedTranscript,$transcriptDest),
        @($apiKeyFile,$keyDest),
        @($normalizedLauncher,$launcherDest)
    )) {
        $oldPreference = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        & scp @scpArgs $copy[0] $copy[1]
        $rc = $LASTEXITCODE
        $ErrorActionPreference = $oldPreference
        if ($rc -ne 0) { throw 'Private Meso Fish live handoff failed.' }
    }

    $remoteVerify = "chmod 600 /workspace/meso-live-private/api-key.txt /workspace/meso-live-private/reference.wav /workspace/meso-live-private/reference.txt; chmod 700 /workspace/meso-live-private/start_fish_s2_live_api.sh; sha256sum /workspace/meso-live-private/reference.wav /workspace/meso-live-private/reference.txt"
    $oldPreference = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $verifyOut = & ssh @sshArgs $remoteTarget $remoteVerify 2>&1
    $rc = $LASTEXITCODE
    $ErrorActionPreference = $oldPreference
    if ($rc -ne 0) { throw 'Remote Meso private hash verification failed.' }
    $verifyText = [string]($verifyOut -join "`n")
    $normalizedTranscriptHash = (Get-FileHash -LiteralPath $normalizedTranscript -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($verifyText -notmatch [regex]::Escape($refHash) -or $verifyText -notmatch [regex]::Escape($normalizedTranscriptHash)) {
        throw 'Remote Meso private input hashes do not match local staged bytes.'
    }
    Write-Host 'MESO_FISH_LIVE_REMOTE_INPUT_HASHES_VERIFIED=true'

    $startCommand = 'MESO_FISH_LICENSE_ACCEPTED=1 PRIVATE_ROOT=/workspace/meso-live-private WORKDIR=/workspace/fish-s2-live REFERENCE_ID=meso bash /workspace/meso-live-private/start_fish_s2_live_api.sh'
    $oldPreference = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $startOut = & ssh @sshArgs $remoteTarget $startCommand 2>&1
    $rc = $LASTEXITCODE
    $ErrorActionPreference = $oldPreference
    if ($rc -ne 0) { throw 'Fish S2 live API launcher failed.' }
    $startText = [string]($startOut -join "`n")
    if ($startText -notmatch 'MESO_FISH_LIVE_HEALTH=true') { throw 'Fish S2 live API did not report healthy startup.' }
    Write-Host 'MESO_FISH_LIVE_REMOTE_API_STARTED=true'

    $endpointBase = "https://$PodId-8080.proxy.runpod.net"
    $headers = @{ Authorization = "Bearer $apiKey" }
    $proxyReady = $false
    $deadline = (Get-Date).AddMinutes(3)
    do {
        try {
            $health = Invoke-RestMethod -Method Get -Uri ($endpointBase + '/v1/health') -Headers $headers -TimeoutSec 15
            if ([string]$health.status -eq 'ok') { $proxyReady = $true; break }
        } catch {}
        Start-Sleep -Seconds 5
    } while ((Get-Date) -lt $deadline)
    if (!$proxyReady) { throw 'Authenticated Fish S2 RunPod proxy health check failed.' }
    Write-Host 'MESO_FISH_LIVE_PROXY_HEALTH=true'

    & $stageClient -RepoRoot $RepoRoot
    if ($LASTEXITCODE -ne 0) { throw 'MASTER-PC Fish live client staging failed.' }

    $liveRoot = Split-Path -Parent $configPath
    New-Item -ItemType Directory -Force -Path $liveRoot | Out-Null
    $expires = [DateTimeOffset]::UtcNow.AddMinutes($LiveMinutes).ToUnixTimeSeconds()
    $config = [ordered]@{
        endpoint_url = ($endpointBase + '/v1/tts')
        api_key = $apiKey
        reference_id = 'meso'
        pod_id = $PodId
        expires_at_epoch = $expires
        max_chars = 1200
    }
    [System.IO.File]::WriteAllText($configPath, (($config | ConvertTo-Json -Depth 5) + "`n"), (New-Object System.Text.UTF8Encoding($false)))

    $venvPython = 'C:\ProgramData\KhalilDigitalTwin\meso\fish-live-venv\Scripts\python.exe'
    $helper = 'C:\ProgramData\KhalilDigitalTwin\meso\fish-live-bridge\meso_fish_live_client.py'
    $oldPreference = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $clientHealth = & $venvPython $helper --config $configPath --health 2>&1
    $clientRc = $LASTEXITCODE
    $ErrorActionPreference = $oldPreference
    if ($clientRc -ne 0 -or [string]($clientHealth -join "`n") -notmatch 'MESO_FISH_LIVE_HEALTH=true') {
        throw 'MASTER-PC Fish live client could not authenticate to the private service.'
    }
    Write-Host 'MESO_FISH_LIVE_MASTER_CLIENT_HEALTH=true'

    & $deployWeb -RepoRoot $RepoRoot -Target 'C:\xampp\htdocs\meso'
    if ($LASTEXITCODE -ne 0) { throw 'Meso web deployment failed.' }

    $chat = Invoke-WebRequest -UseBasicParsing -Uri 'https://fantest.win/meso/chat/' -SessionVariable mesoSession -TimeoutSec 20
    if ([int]$chat.StatusCode -ne 200) { throw "Public Meso chat did not open directly: $($chat.StatusCode)" }
    $smoke = Join-Path $staging 'live-smoke.wav'
    $probe = @{ text = 'مرحبا، هذا اختبار قصير للصوت.' } | ConvertTo-Json -Compress
    $probeBytes = [Text.Encoding]::UTF8.GetBytes($probe)
    $ttsResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri 'https://fantest.win/meso/api/tts.php' -WebSession $mesoSession -ContentType 'application/json; charset=utf-8' -Headers @{ Accept='audio/wav' } -Body $probeBytes -OutFile $smoke -TimeoutSec 105
    if ([int]$ttsResponse.StatusCode -ne 200) { throw "Public Meso TTS smoke returned HTTP $($ttsResponse.StatusCode)" }
    if ([string]$ttsResponse.Headers['X-Meso-Voice'] -ne 'fish-s2') { throw 'Public Meso TTS response was not identified as Fish S2.' }
    if (!(Test-Path -LiteralPath $smoke -PathType Leaf) -or (Get-Item -LiteralPath $smoke).Length -lt 44) { throw 'Public Meso TTS WAV missing.' }
    $head = [System.IO.File]::ReadAllBytes($smoke)
    if ($head.Length -lt 12 -or [Text.Encoding]::ASCII.GetString($head,0,4) -ne 'RIFF' -or [Text.Encoding]::ASCII.GetString($head,8,4) -ne 'WAVE') {
        throw 'Public Meso TTS smoke did not return a WAV.'
    }
    Write-Host "MESO_FISH_LIVE_PUBLIC_TTS_BYTES=$($head.Length)"
    Write-Host 'MESO_FISH_LIVE_PUBLIC_TTS_VERIFIED=true'

    $state = [ordered]@{
        live = $true
        engine = 'Fish Audio S2 Pro'
        pod_id = $PodId
        reference_id = 'meso'
        reference_sha256 = $refHash
        source_sha = (git -C $RepoRoot rev-parse HEAD).Trim()
        endpoint_host = ([Uri]$endpointBase).Host
        expires_at_epoch = $expires
        private_reference_in_browser = $false
        browser_fallback_enabled = $true
        verified_at = (Get-Date).ToUniversalTime().ToString('o')
    }
    [System.IO.File]::WriteAllText($statePath, (($state | ConvertTo-Json -Depth 5) + "`n"), (New-Object System.Text.UTF8Encoding($false)))
    $success = $true
    Write-Host 'MESO_FISH_LIVE_SERVICE_READY=true'
    Write-Host "MESO_FISH_LIVE_POD_ID=$PodId"
    Write-Host "MESO_FISH_LIVE_EXPIRES_EPOCH=$expires"
    Write-Host 'MESO_FISH_LIVE_BROWSER_FALLBACK=true'
} finally {
    Remove-Item -LiteralPath $known -Force -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue }
    if (!$success) {
        Remove-Item -LiteralPath $configPath -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $statePath -Force -ErrorAction SilentlyContinue
    }
    $apiKey = $null
}

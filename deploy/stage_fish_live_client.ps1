[CmdletBinding()]
param(
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot)
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$base = 'C:\ProgramData\KhalilDigitalTwin\meso'
$bridge = Join-Path $base 'fish-live-bridge'
$venv = Join-Path $base 'fish-live-venv'
$sourceHelper = Join-Path $RepoRoot 'tools\meso_fish_live_client.py'
$destHelper = Join-Path $bridge 'meso_fish_live_client.py'
$ttsPhp = Join-Path $RepoRoot 'web\api\tts.php'

foreach ($path in @($sourceHelper, $ttsPhp)) {
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required Meso Fish live source missing: $path" }
}

New-Item -ItemType Directory -Force -Path $bridge | Out-Null
Copy-Item -LiteralPath $sourceHelper -Destination $destHelper -Force

$python = 'C:\ProgramData\KhalilDigitalTwin\Python311\python.exe'
if (!(Test-Path -LiteralPath $python -PathType Leaf)) {
    $python = (Get-Command python.exe -ErrorAction Stop).Source
}
$venvPython = Join-Path $venv 'Scripts\python.exe'
if (!(Test-Path -LiteralPath $venvPython -PathType Leaf)) {
    & $python -m venv $venv
    if ($LASTEXITCODE -ne 0) { throw 'Unable to create Fish live client venv.' }
}

& $venvPython -m pip install --disable-pip-version-check --quiet 'msgpack==1.1.1'
if ($LASTEXITCODE -ne 0) { throw 'Unable to install pinned msgpack dependency.' }
& $venvPython -m py_compile $destHelper
if ($LASTEXITCODE -ne 0) { throw 'Staged Fish live helper did not compile.' }

$cfg = Join-Path $env:TEMP ('meso-fish-expired-' + [guid]::NewGuid().ToString('N') + '.json')
try {
    $test = [ordered]@{
        endpoint_url = 'https://preflight-8080.proxy.runpod.net/v1/tts'
        api_key = ('x' * 32)
        reference_id = 'meso'
        expires_at_epoch = 1
        max_chars = 1200
    }
    [System.IO.File]::WriteAllText($cfg, (($test | ConvertTo-Json -Compress) + "`n"), (New-Object System.Text.UTF8Encoding($false)))
    $oldPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $out = & $venvPython $destHelper --config $cfg --health 2>&1
    $rc = $LASTEXITCODE
    $ErrorActionPreference = $oldPreference
    $text = [string]($out -join "`n")
    if ($rc -ne 2 -or $text -notmatch 'live_service_expired') {
        throw "Fail-closed Fish client probe did not reject expired config. rc=$rc"
    }
} finally {
    Remove-Item -LiteralPath $cfg -Force -ErrorAction SilentlyContinue
}

$php = 'C:\xampp\php\php.exe'
if (!(Test-Path -LiteralPath $php -PathType Leaf)) { throw 'XAMPP PHP CLI missing.' }
& $php -l $ttsPhp
if ($LASTEXITCODE -ne 0) { throw 'Meso TTS PHP endpoint lint failed on MASTER-PC.' }

Write-Host "MESO_FISH_LIVE_CLIENT_PYTHON=$venvPython"
Write-Host 'MESO_FISH_LIVE_CLIENT_MSGPACK_PIN=1.1.1'
Write-Host 'MESO_FISH_LIVE_CLIENT_STAGED=true'
Write-Host 'MESO_FISH_LIVE_EXPIRED_CONFIG_FAIL_CLOSED=true'
Write-Host 'MESO_FISH_LIVE_NETWORK_USED=false'
Write-Host 'MESO_FISH_LIVE_TTS_PHP_MASTER_LINT=true'
Write-Host 'MESO_FISH_LIVE_LOCAL_STAGE_COMPLETE=true'
Write-Host 'RUNPOD_USED=false'

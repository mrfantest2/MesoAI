param(
  [Parameter(Mandatory=$true)][string]$RemoteHost,
  [string]$RemoteUser = 'root',
  [int]$RemotePort = 22,
  [Parameter(Mandatory=$true)][string]$SshKeyPath,
  [Parameter(Mandatory=$true)][string]$ExpectedHostKeySha256,
  [int]$MinVramMiB = 23000,
  [int]$MinFreeGiB = 30,
  [string]$RemoteWorkspace = '/workspace'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Require-File([string]$Path, [string]$Label) {
  if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label not found: $Path" }
}

function Invoke-NativeCapture([string]$Exe, [string[]]$Args) {
  $out = & $Exe @Args
  if ($LASTEXITCODE -ne 0) { throw "$Exe failed with exit code $LASTEXITCODE" }
  return @($out)
}

function Get-Fact([hashtable]$Facts, [string]$Name) {
  if ($Facts.ContainsKey($Name)) { return [string]$Facts[$Name] }
  return ''
}

Require-File $SshKeyPath 'SSH key'
foreach ($exe in @('ssh','ssh-keygen')) {
  if (!(Get-Command $exe -ErrorAction SilentlyContinue)) { throw "Required OpenSSH tool is missing: $exe" }
}

$expected = $ExpectedHostKeySha256.Trim()
if (!$expected.StartsWith('SHA256:')) { throw 'ExpectedHostKeySha256 must use SHA256:<fingerprint> format.' }
if ($RemoteWorkspace -notmatch '^/[A-Za-z0-9._/-]+$' -or $RemoteWorkspace.Contains('..')) {
  throw 'RemoteWorkspace must be an absolute shell-safe path.'
}

$knownHosts = Join-Path ([System.IO.Path]::GetTempPath()) ("meso-preflight-known-hosts-{0}.txt" -f [guid]::NewGuid().ToString('N'))
$target = "$RemoteUser@$RemoteHost"

try {
  # A zero-data authenticated connection captures the host key. No private project
  # material is transferred before the captured fingerprint is compared with the
  # provider fingerprint recorded during Pod provisioning.
  $acceptArgs = @(
    '-p', [string]$RemotePort,
    '-i', $SshKeyPath,
    '-o', 'BatchMode=yes',
    '-o', 'IdentitiesOnly=yes',
    '-o', "UserKnownHostsFile=$knownHosts",
    '-o', 'StrictHostKeyChecking=accept-new',
    '-o', 'ConnectTimeout=12'
  )
  $ready = & ssh @acceptArgs $target "printf 'ready'"
  if ($LASTEXITCODE -ne 0 -or [string]($ready -join '') -notmatch 'ready') {
    throw 'Initial zero-data SSH authentication failed.'
  }
  if (!(Test-Path -LiteralPath $knownHosts -PathType Leaf) -or (Get-Item -LiteralPath $knownHosts).Length -eq 0) {
    throw 'Remote SSH host key was not captured.'
  }

  $fingerprints = @(& ssh-keygen -lf $knownHosts -E sha256 2>$null)
  if ($LASTEXITCODE -ne 0 -or $fingerprints.Count -eq 0) { throw 'Unable to calculate SSH host-key fingerprint.' }
  if (-not ($fingerprints | Where-Object { [string]$_ -match [regex]::Escape($expected) })) {
    throw 'Remote SSH host-key fingerprint does not match the expected provider fingerprint.'
  }
  Write-Host 'MESO_FISH_PREFLIGHT_HOST_KEY_VERIFIED=true'

  $sshCommon = @(
    '-p', [string]$RemotePort,
    '-i', $SshKeyPath,
    '-o', 'BatchMode=yes',
    '-o', 'IdentitiesOnly=yes',
    '-o', "UserKnownHostsFile=$knownHosts",
    '-o', 'StrictHostKeyChecking=yes',
    '-o', 'ConnectTimeout=12'
  )

  # Deliberately read-only: no directories, installs, model downloads, or private files.
  $probe = @'
set -e
printf 'os='; uname -s
printf 'arch='; uname -m
printf 'gpu='; nvidia-smi --query-gpu=name --format=csv,noheader | head -n1
printf 'vram_mib='; nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d '[:space:]'; printf '\n'
printf 'driver='; nvidia-smi --query-gpu=driver_version --format=csv,noheader | head -n1 | tr -d '[:space:]'; printf '\n'
printf 'free_kib='; df -Pk __WORKSPACE__ | awk 'NR==2 {print $4}'
printf 'python3='; command -v python3 >/dev/null 2>&1 && python3 --version 2>&1 | head -n1 || echo missing
printf 'git='; command -v git >/dev/null 2>&1 && git --version | head -n1 || echo missing
printf 'ffmpeg='; command -v ffmpeg >/dev/null 2>&1 && ffmpeg -version 2>&1 | head -n1 || echo missing
printf 'uv='; command -v uv >/dev/null 2>&1 && uv --version 2>&1 | head -n1 || echo missing
'@
  $probe = $probe.Replace('__WORKSPACE__', $RemoteWorkspace)
  $lines = Invoke-NativeCapture 'ssh' ($sshCommon + @($target, $probe))

  $facts = @{}
  foreach ($line in $lines) {
    $s = [string]$line
    $idx = $s.IndexOf('=')
    if ($idx -gt 0) { $facts[$s.Substring(0,$idx)] = $s.Substring($idx+1).Trim() }
  }

  $os = Get-Fact $facts 'os'
  if ($os -ne 'Linux') { throw "Remote host is not Linux: $os" }
  $vram = 0
  $vramText = Get-Fact $facts 'vram_mib'
  if (-not [int]::TryParse($vramText, [ref]$vram)) { throw 'Unable to parse GPU VRAM.' }
  if ($vram -lt $MinVramMiB) { throw "Insufficient GPU VRAM: $vram MiB; require at least $MinVramMiB MiB." }
  $freeKiB = [int64]0
  $freeText = Get-Fact $facts 'free_kib'
  if (-not [int64]::TryParse($freeText, [ref]$freeKiB)) { throw 'Unable to parse remote free disk.' }
  $requiredKiB = [int64]$MinFreeGiB * 1024 * 1024
  if ($freeKiB -lt $requiredKiB) { throw "Insufficient free disk: $freeKiB KiB; require at least $requiredKiB KiB." }
  if ((Get-Fact $facts 'python3') -eq 'missing') { throw 'python3 is missing on remote host.' }
  if ((Get-Fact $facts 'git') -eq 'missing') { throw 'git is missing on remote host.' }

  $result = [ordered]@{
    ok = $true
    private_data_transferred = $false
    host_key_verified = $true
    os = $os
    architecture = Get-Fact $facts 'arch'
    gpu = Get-Fact $facts 'gpu'
    vram_mib = $vram
    driver = Get-Fact $facts 'driver'
    free_gib = [math]::Round($freeKiB / 1MB, 2)
    python3 = Get-Fact $facts 'python3'
    git = Get-Fact $facts 'git'
    ffmpeg = Get-Fact $facts 'ffmpeg'
    uv = Get-Fact $facts 'uv'
    ready_for_private_handoff = $true
  }
  $result | ConvertTo-Json -Depth 4
  Write-Host 'MESO_FISH_ZERO_DATA_REMOTE_PREFLIGHT=true'
} finally {
  Remove-Item -LiteralPath $knownHosts -Force -ErrorAction SilentlyContinue
}

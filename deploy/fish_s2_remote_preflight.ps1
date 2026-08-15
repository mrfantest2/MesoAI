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

Require-File $SshKeyPath 'SSH key'
foreach ($exe in @('ssh','ssh-keyscan','ssh-keygen')) {
  if (!(Get-Command $exe -ErrorAction SilentlyContinue)) { throw "Required OpenSSH tool is missing: $exe" }
}

$expected = $ExpectedHostKeySha256.Trim()
if (!$expected.StartsWith('SHA256:')) { throw 'ExpectedHostKeySha256 must use SHA256:<fingerprint> format.' }
if ($RemoteWorkspace -notmatch '^/[A-Za-z0-9._/-]+$' -or $RemoteWorkspace.Contains('..')) {
  throw 'RemoteWorkspace must be an absolute shell-safe path.'
}

$knownHosts = Join-Path ([System.IO.Path]::GetTempPath()) ("meso-preflight-known-hosts-{0}.txt" -f [guid]::NewGuid().ToString('N'))
$target = "$RemoteUser@$RemoteHost"
$sshCommon = @(
  '-p', [string]$RemotePort,
  '-i', $SshKeyPath,
  '-o', 'BatchMode=yes',
  '-o', 'IdentitiesOnly=yes',
  '-o', "UserKnownHostsFile=$knownHosts",
  '-o', 'StrictHostKeyChecking=yes',
  '-o', 'ConnectTimeout=12'
)

try {
  # Verify the provider-supplied SSH host identity before any authenticated session.
  $scan = & ssh-keyscan -p $RemotePort -- $RemoteHost 2>$null
  if ($LASTEXITCODE -ne 0 -or !$scan) { throw 'Unable to obtain remote SSH host key.' }
  Set-Content -LiteralPath $knownHosts -Value $scan -Encoding ascii
  $fingerprints = @(& ssh-keygen -lf $knownHosts -E sha256 2>$null)
  if ($LASTEXITCODE -ne 0 -or $fingerprints.Count -eq 0) { throw 'Unable to calculate SSH host-key fingerprint.' }
  if (-not ($fingerprints | Where-Object { [string]$_ -match [regex]::Escape($expected) })) {
    throw 'Remote SSH host-key fingerprint does not match the expected provider fingerprint.'
  }
  Write-Host 'MESO_FISH_PREFLIGHT_HOST_KEY_VERIFIED=true'

  # This command is deliberately read-only. It does not create directories, install
  # packages, download models, or transfer private files.
  $probe = @'
set -e
printf 'os='; uname -s
printf 'arch='; uname -m
printf 'gpu='; nvidia-smi --query-gpu=name --format=csv,noheader | head -n1
printf 'vram_mib='; nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d '[:space:]'
printf 'driver='; nvidia-smi --query-gpu=driver_version --format=csv,noheader | head -n1 | tr -d '[:space:]'
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
    if ($idx -gt 0) {
      $facts[$s.Substring(0,$idx)] = $s.Substring($idx+1).Trim()
    }
  }

  if (($facts['os'] ?? '') -ne 'Linux') { throw "Remote host is not Linux: $($facts['os'])" }
  $vram = 0
  if (-not [int]::TryParse([string]($facts['vram_mib'] ?? ''), [ref]$vram)) { throw 'Unable to parse GPU VRAM.' }
  if ($vram -lt $MinVramMiB) { throw "Insufficient GPU VRAM: $vram MiB; require at least $MinVramMiB MiB." }
  $freeKiB = [int64]0
  if (-not [int64]::TryParse([string]($facts['free_kib'] ?? ''), [ref]$freeKiB)) { throw 'Unable to parse remote free disk.' }
  $requiredKiB = [int64]$MinFreeGiB * 1024 * 1024
  if ($freeKiB -lt $requiredKiB) { throw "Insufficient free disk: $freeKiB KiB; require at least $requiredKiB KiB." }
  if ([string]($facts['python3'] ?? '') -eq 'missing') { throw 'python3 is missing on remote host.' }
  if ([string]($facts['git'] ?? '') -eq 'missing') { throw 'git is missing on remote host.' }

  $result = [ordered]@{
    ok = $true
    private_data_transferred = $false
    host_key_verified = $true
    os = [string]$facts['os']
    architecture = [string]$facts['arch']
    gpu = [string]$facts['gpu']
    vram_mib = $vram
    driver = [string]$facts['driver']
    free_gib = [math]::Round($freeKiB / 1MB, 2)
    python3 = [string]$facts['python3']
    git = [string]$facts['git']
    ffmpeg = [string]$facts['ffmpeg']
    uv = [string]$facts['uv']
    ready_for_private_handoff = $true
  }
  $result | ConvertTo-Json -Depth 4
  Write-Host 'MESO_FISH_ZERO_DATA_REMOTE_PREFLIGHT=true'
} finally {
  Remove-Item -LiteralPath $knownHosts -Force -ErrorAction SilentlyContinue
}

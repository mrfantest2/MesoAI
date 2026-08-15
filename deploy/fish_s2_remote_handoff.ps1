param(
  [Parameter(Mandatory=$true)][string]$RemoteHost,
  [string]$RemoteUser = 'root',
  [int]$RemotePort = 22,
  [Parameter(Mandatory=$true)][string]$SshKeyPath,
  [Parameter(Mandatory=$true)][string]$ExpectedHostKeySha256,
  [Parameter(Mandatory=$true)][string]$ReferenceAudio,
  [Parameter(Mandatory=$true)][string]$ReferenceTranscript,
  [Parameter(Mandatory=$true)][string]$TargetText,
  [Parameter(Mandatory=$true)][string]$LicenseAcceptanceJson,
  [Parameter(Mandatory=$true)][string]$LocalOutputDir,
  [string]$RemoteRoot = '/workspace/meso-private',
  [switch]$InstallSystemDeps
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Require-File([string]$Path, [string]$Label) {
  if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label not found: $Path" }
}

function Quote-Sh([string]$Value) {
  return "'" + $Value.Replace("'", "'\"'\"'") + "'"
}

function Invoke-Native([string]$Exe, [string[]]$Args) {
  & $Exe @Args
  if ($LASTEXITCODE -ne 0) { throw "$Exe failed with exit code $LASTEXITCODE" }
}

Require-File $SshKeyPath 'SSH key'
Require-File $ReferenceAudio 'Reference audio'
Require-File $ReferenceTranscript 'Reference transcript'
Require-File $TargetText 'Target text'
Require-File $LicenseAcceptanceJson 'Fish license acceptance record'

if ($RemoteRoot -notmatch '^/workspace/[A-Za-z0-9._/-]+$' -or $RemoteRoot.Length -lt 20) {
  throw 'RemoteRoot must be a dedicated path below /workspace/.'
}

$license = Get-Content -LiteralPath $LicenseAcceptanceJson -Raw | ConvertFrom-Json
if ($license.accepted -ne $true) { throw 'Fish Audio Research License is not explicitly accepted.' }
$licenseName = [string]$license.license
if ($licenseName -notmatch '(?i)Fish Audio Research License') { throw 'Unexpected Fish license acceptance record.' }
$scope = [string]$license.scope
if ($scope -notmatch '(?i)(non[- ]?commercial|personal|evaluation|research)') {
  throw 'Fish license acceptance scope must state an allowed non-commercial/research purpose.'
}

foreach ($exe in @('ssh','scp','ssh-keyscan','ssh-keygen')) {
  if (!(Get-Command $exe -ErrorAction SilentlyContinue)) { throw "Required OpenSSH tool is missing: $exe" }
}

$expected = $ExpectedHostKeySha256.Trim()
if (!$expected.StartsWith('SHA256:')) { throw 'ExpectedHostKeySha256 must use SHA256:<fingerprint> format.' }

$repoRoot = Split-Path -Parent $PSScriptRoot
$runner = Join-Path $repoRoot 'tools\run_fish_s2_ephemeral.sh'
Require-File $runner 'Fish S2 runner'

New-Item -ItemType Directory -Force -Path $LocalOutputDir | Out-Null
$knownHosts = Join-Path ([System.IO.Path]::GetTempPath()) ("meso-known-hosts-{0}.txt" -f [guid]::NewGuid().ToString('N'))
$target = "$RemoteUser@$RemoteHost"
$sshCommon = @('-p', [string]$RemotePort, '-i', $SshKeyPath, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', "UserKnownHostsFile=$knownHosts", '-o', 'StrictHostKeyChecking=yes')
$scpCommon = @('-P', [string]$RemotePort, '-i', $SshKeyPath, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', "UserKnownHostsFile=$knownHosts", '-o', 'StrictHostKeyChecking=yes')

try {
  # Pin the first SSH connection to the provider-supplied host-key fingerprint.
  $scan = & ssh-keyscan -p $RemotePort -- $RemoteHost 2>$null
  if ($LASTEXITCODE -ne 0 -or !$scan) { throw 'Unable to obtain remote SSH host key.' }
  Set-Content -LiteralPath $knownHosts -Value $scan -Encoding ascii
  $fingerprints = @(& ssh-keygen -lf $knownHosts -E sha256 2>$null)
  if ($LASTEXITCODE -ne 0 -or $fingerprints.Count -eq 0) { throw 'Unable to calculate SSH host-key fingerprint.' }
  $matched = $false
  foreach ($line in $fingerprints) {
    if ([string]$line -match [regex]::Escape($expected)) { $matched = $true; break }
  }
  if (!$matched) { throw 'Remote SSH host-key fingerprint does not match the expected provider fingerprint.' }
  Write-Host 'MESO_FISH_REMOTE_HOST_KEY_VERIFIED=true'

  $mkdir = "umask 077; mkdir -p $(Quote-Sh $RemoteRoot) $(Quote-Sh ($RemoteRoot + '/output')); chmod 700 $(Quote-Sh $RemoteRoot)"
  Invoke-Native 'ssh' ($sshCommon + @($target, $mkdir))

  $files = @(
    @{ Local=$ReferenceAudio; Remote='reference.wav' },
    @{ Local=$ReferenceTranscript; Remote='reference.txt' },
    @{ Local=$TargetText; Remote='target.txt' },
    @{ Local=$runner; Remote='run_fish_s2_ephemeral.sh' }
  )

  foreach ($item in $files) {
    Invoke-Native 'scp' ($scpCommon + @($item.Local, ($target + ':' + $RemoteRoot + '/' + $item.Remote)))
  }

  # End-to-end input integrity check before inference.
  foreach ($item in $files) {
    $localHash = (Get-FileHash -LiteralPath $item.Local -Algorithm SHA256).Hash.ToLowerInvariant()
    $remotePath = $RemoteRoot + '/' + $item.Remote
    $cmd = "sha256sum -- $(Quote-Sh $remotePath) | awk '{print `$1}'"
    $remoteHash = (& ssh @sshCommon $target $cmd).Trim().ToLowerInvariant()
    if ($LASTEXITCODE -ne 0 -or $remoteHash -ne $localHash) { throw "Remote hash mismatch for $($item.Remote)" }
  }
  Write-Host 'MESO_FISH_REMOTE_INPUT_HASHES_VERIFIED=true'

  $install = if ($InstallSystemDeps) { '1' } else { '0' }
  $run = @(
    "chmod 700 $(Quote-Sh ($RemoteRoot + '/run_fish_s2_ephemeral.sh'))",
    "MESO_FISH_LICENSE_ACCEPTED=1",
    "INSTALL_SYSTEM_DEPS=$install",
    "PRIVATE_ROOT=$(Quote-Sh $RemoteRoot)",
    "REFERENCE_AUDIO=$(Quote-Sh ($RemoteRoot + '/reference.wav'))",
    "REFERENCE_TEXT_FILE=$(Quote-Sh ($RemoteRoot + '/reference.txt'))",
    "TARGET_TEXT_FILE=$(Quote-Sh ($RemoteRoot + '/target.txt'))",
    "OUTPUT_DIR=$(Quote-Sh ($RemoteRoot + '/output'))",
    "WORKDIR=$(Quote-Sh ($RemoteRoot + '/work'))",
    "PURGE_PRIVATE_INPUTS=1",
    "KEEP_WORKDIR=0",
    "bash $(Quote-Sh ($RemoteRoot + '/run_fish_s2_ephemeral.sh'))"
  ) -join ' '
  Invoke-Native 'ssh' ($sshCommon + @($target, $run))

  foreach ($name in @('meso-fish-F1.wav','meso-fish-F2.wav','meso-fish-F3.wav','report.json')) {
    Invoke-Native 'scp' ($scpCommon + @(($target + ':' + $RemoteRoot + '/output/' + $name), $LocalOutputDir))
  }

  $reportPath = Join-Path $LocalOutputDir 'report.json'
  Require-File $reportPath 'Fish output report'
  $report = Get-Content -LiteralPath $reportPath -Raw | ConvertFrom-Json
  if (@($report.variants).Count -ne 3) { throw 'Fish report did not contain exactly three variants.' }
  foreach ($variant in @($report.variants)) {
    $fileName = [string]$variant.file
    if ($fileName -notmatch '^meso-fish-F[123]\.wav$') { throw "Unexpected Fish output name: $fileName" }
    $localPath = Join-Path $LocalOutputDir $fileName
    Require-File $localPath 'Fish output'
    $actual = (Get-FileHash -LiteralPath $localPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedHash = ([string]$variant.sha256).ToLowerInvariant()
    if ($actual -ne $expectedHash) { throw "Downloaded output hash mismatch: $fileName" }
  }
  Write-Host 'MESO_FISH_DOWNLOADED_OUTPUTS_VERIFIED=true'
} finally {
  # The GPU-side private working set is disposable. Cleanup is attempted even when
  # inference or download fails; pod destruction remains a separate provider action.
  try {
    if (Test-Path -LiteralPath $knownHosts) {
      $cleanup = "rm -rf -- $(Quote-Sh $RemoteRoot)"
      & ssh @sshCommon $target $cleanup 2>$null | Out-Null
    }
  } catch {}
  Remove-Item -LiteralPath $knownHosts -Force -ErrorAction SilentlyContinue
}

Write-Host "Fish S2 outputs verified locally: $LocalOutputDir"

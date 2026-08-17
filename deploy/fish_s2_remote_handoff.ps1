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
  [string]$ProjectSlug = 'meso',
  [string]$RemoteRoot = '/workspace/meso-private',
  [string]$SharedWorkRoot = '/workspace/fish-s2-shared',
  [switch]$InstallSystemDeps
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Require-File([string]$Path, [string]$Label) {
  if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label not found: $Path" }
}

function Invoke-Native([string]$Exe, [string[]]$ArgumentList) {
  $previousPreference = $ErrorActionPreference
  try {
    # Windows PowerShell converts native stderr into NativeCommandError records.
    # Consequential success/failure is determined from the native exit code, not
    # from whether the tool writes warnings or diagnostics to stderr.
    $ErrorActionPreference = 'Continue'
    & $Exe @ArgumentList
    $exitCode = $LASTEXITCODE
  } finally {
    $ErrorActionPreference = $previousPreference
  }
  if ($exitCode -ne 0) { throw "$Exe failed with exit code $exitCode" }
}

Require-File $SshKeyPath 'SSH key'
Require-File $ReferenceAudio 'Reference audio'
Require-File $ReferenceTranscript 'Reference transcript'
Require-File $TargetText 'Target text'
Require-File $LicenseAcceptanceJson 'Fish license acceptance record'

if ($ProjectSlug -notmatch '^[a-z0-9][a-z0-9_-]{1,31}$') { throw 'ProjectSlug must be a short shell-safe identifier.' }
if ($RemoteRoot -notmatch '^/workspace/[A-Za-z0-9._/-]+$' -or $RemoteRoot.Length -lt 20 -or $RemoteRoot.Contains('..')) {
  throw 'RemoteRoot must be a dedicated shell-safe path below /workspace/.'
}
if ($SharedWorkRoot -notmatch '^/workspace/[A-Za-z0-9._/-]+$' -or $SharedWorkRoot.Contains('..')) {
  throw 'SharedWorkRoot must be a shell-safe path below /workspace/.'
}
if ($RemoteRoot.StartsWith($SharedWorkRoot) -or $SharedWorkRoot.StartsWith($RemoteRoot)) {
  throw 'Private RemoteRoot and SharedWorkRoot must not overlap.'
}

$license = Get-Content -LiteralPath $LicenseAcceptanceJson -Raw | ConvertFrom-Json
if ($license.accepted -ne $true) { throw 'Fish Audio Research License is not explicitly accepted.' }
$licenseName = [string]$license.license
if ($licenseName -notmatch '(?i)Fish Audio Research License') { throw 'Unexpected Fish license acceptance record.' }
$scope = [string]$license.scope
if ($scope -notmatch '(?i)(non[- ]?commercial|personal|evaluation|research)') {
  throw 'Fish license acceptance scope must state an allowed non-commercial/research purpose.'
}

foreach ($exe in @('ssh','scp','ssh-keygen')) {
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

try {
  $acceptArgs = @('-p', [string]$RemotePort, '-i', $SshKeyPath, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', "UserKnownHostsFile=$knownHosts", '-o', 'StrictHostKeyChecking=accept-new', '-o', 'ConnectTimeout=15')
  $ready = & ssh @acceptArgs $target "printf 'ready'"
  if ($LASTEXITCODE -ne 0 -or [string]($ready -join '') -notmatch 'ready') { throw 'Initial zero-data SSH authentication failed.' }
  if (!(Test-Path -LiteralPath $knownHosts -PathType Leaf) -or (Get-Item -LiteralPath $knownHosts).Length -eq 0) { throw 'Remote SSH host key was not captured.' }

  $fingerprints = @(& ssh-keygen -lf $knownHosts -E sha256 2>$null)
  if ($LASTEXITCODE -ne 0 -or $fingerprints.Count -eq 0) { throw 'Unable to calculate SSH host-key fingerprint.' }
  $matched = $false
  foreach ($line in $fingerprints) {
    if ([string]$line -match [regex]::Escape($expected)) { $matched = $true; break }
  }
  if (!$matched) { throw 'Remote SSH host-key fingerprint does not match the expected provider fingerprint.' }
  Write-Host 'MESO_FISH_REMOTE_HOST_KEY_VERIFIED=true'

  $sshCommon = @('-p', [string]$RemotePort, '-i', $SshKeyPath, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', "UserKnownHostsFile=$knownHosts", '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15')
  $scpCommon = @('-P', [string]$RemotePort, '-i', $SshKeyPath, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', "UserKnownHostsFile=$knownHosts", '-o', 'StrictHostKeyChecking=yes')

  $mkdir = "umask 077; mkdir -p $RemoteRoot $RemoteRoot/output $SharedWorkRoot; chmod 700 $RemoteRoot"
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

  foreach ($item in $files) {
    $localHash = (Get-FileHash -LiteralPath $item.Local -Algorithm SHA256).Hash.ToLowerInvariant()
    $remotePath = $RemoteRoot + '/' + $item.Remote
    $cmd = "sha256sum -- $remotePath | awk '{print `$1}'"
    $remoteRaw = & ssh @sshCommon $target $cmd
    if ($LASTEXITCODE -ne 0) { throw "Unable to hash remote input: $($item.Remote)" }
    $remoteHash = ([string]($remoteRaw -join '')).Trim().ToLowerInvariant()
    if ($remoteHash -ne $localHash) { throw "Remote hash mismatch for $($item.Remote)" }
  }
  Write-Host 'MESO_FISH_REMOTE_INPUT_HASHES_VERIFIED=true'

  $install = if ($InstallSystemDeps) { '1' } else { '0' }
  $run = @(
    "chmod 700 $RemoteRoot/run_fish_s2_ephemeral.sh",
    "MESO_FISH_LICENSE_ACCEPTED=1",
    "PROJECT_SLUG=$ProjectSlug",
    "INSTALL_SYSTEM_DEPS=$install",
    "PRIVATE_ROOT=$RemoteRoot",
    "REFERENCE_AUDIO=$RemoteRoot/reference.wav",
    "REFERENCE_TEXT_FILE=$RemoteRoot/reference.txt",
    "TARGET_TEXT_FILE=$RemoteRoot/target.txt",
    "OUTPUT_DIR=$RemoteRoot/output",
    "WORKDIR=$SharedWorkRoot",
    "PURGE_PRIVATE_INPUTS=1",
    "KEEP_WORKDIR=1",
    "bash $RemoteRoot/run_fish_s2_ephemeral.sh"
  ) -join ' '
  Invoke-Native 'ssh' ($sshCommon + @($target, $run))

  $expectedNames = @("$ProjectSlug-fish-F1.wav","$ProjectSlug-fish-F2.wav","$ProjectSlug-fish-F3.wav",'report.json')
  foreach ($name in $expectedNames) {
    Invoke-Native 'scp' ($scpCommon + @(($target + ':' + $RemoteRoot + '/output/' + $name), $LocalOutputDir))
  }

  $reportPath = Join-Path $LocalOutputDir 'report.json'
  Require-File $reportPath 'Fish output report'
  $report = Get-Content -LiteralPath $reportPath -Raw | ConvertFrom-Json
  if ([string]$report.project_slug -ne $ProjectSlug) { throw 'Fish report project slug mismatch.' }
  if (@($report.variants).Count -ne 3) { throw 'Fish report did not contain exactly three variants.' }
  $namePattern = '^' + [regex]::Escape($ProjectSlug) + '-fish-F[123]\.wav$'
  foreach ($variant in @($report.variants)) {
    $fileName = [string]$variant.file
    if ($fileName -notmatch $namePattern) { throw "Unexpected Fish output name: $fileName" }
    $localPath = Join-Path $LocalOutputDir $fileName
    Require-File $localPath 'Fish output'
    $actual = (Get-FileHash -LiteralPath $localPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $expectedHash = ([string]$variant.sha256).ToLowerInvariant()
    if ($actual -ne $expectedHash) { throw "Downloaded output hash mismatch: $fileName" }
  }
  Write-Host 'MESO_FISH_DOWNLOADED_OUTPUTS_VERIFIED=true'
} finally {
  try {
    if (Test-Path -LiteralPath $knownHosts) {
      $sshCleanup = @('-p', [string]$RemotePort, '-i', $SshKeyPath, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', "UserKnownHostsFile=$knownHosts", '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10')
      $cleanup = "rm -rf -- $RemoteRoot"
      & ssh @sshCleanup $target $cleanup 2>$null | Out-Null
    }
  } catch {}
  Remove-Item -LiteralPath $knownHosts -Force -ErrorAction SilentlyContinue
}

Write-Host "Fish S2 outputs verified locally for ${ProjectSlug}: $LocalOutputDir"

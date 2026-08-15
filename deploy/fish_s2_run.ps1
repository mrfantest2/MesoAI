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
  [int]$MinVramMiB = 23000,
  [int]$MinFreeGiB = 30,
  [switch]$InstallSystemDeps
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Require-File([string]$Path, [string]$Label) {
  if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label not found: $Path" }
}

$repoRoot = Split-Path -Parent $PSScriptRoot
$preflight = Join-Path $PSScriptRoot 'fish_s2_remote_preflight.ps1'
$handoff = Join-Path $PSScriptRoot 'fish_s2_remote_handoff.ps1'
Require-File $preflight 'Fish zero-data preflight script'
Require-File $handoff 'Fish private handoff script'
Require-File $SshKeyPath 'SSH key'
Require-File $ReferenceAudio 'Reference audio'
Require-File $ReferenceTranscript 'Reference transcript'
Require-File $TargetText 'Target text'
Require-File $LicenseAcceptanceJson 'Fish license acceptance record'

# Fail before any network contact if the explicit local license record is absent or invalid.
$license = Get-Content -LiteralPath $LicenseAcceptanceJson -Raw | ConvertFrom-Json
if ($license.accepted -ne $true) { throw 'Fish Audio Research License is not explicitly accepted.' }
if ([string]$license.license -notmatch '(?i)Fish Audio Research License') {
  throw 'Unexpected Fish license acceptance record.'
}
if ([string]$license.scope -notmatch '(?i)(non[- ]?commercial|personal|evaluation|research)') {
  throw 'Fish license acceptance scope must state an allowed non-commercial/research purpose.'
}
Write-Host 'MESO_FISH_ORCHESTRATOR_LICENSE_GATE=true'

# Step 1: read-only remote validation. This script does not create directories,
# install packages, download models, or transfer any Maissoun/private input.
$preflightArgs = @{
  RemoteHost = $RemoteHost
  RemoteUser = $RemoteUser
  RemotePort = $RemotePort
  SshKeyPath = $SshKeyPath
  ExpectedHostKeySha256 = $ExpectedHostKeySha256
  MinVramMiB = $MinVramMiB
  MinFreeGiB = $MinFreeGiB
  RemoteWorkspace = '/workspace'
}
& $preflight @preflightArgs
Write-Host 'MESO_FISH_ORCHESTRATOR_ZERO_DATA_PREFLIGHT_COMPLETE=true'

# Step 2: only after the zero-data preflight succeeds may private files be copied.
$handoffArgs = @{
  RemoteHost = $RemoteHost
  RemoteUser = $RemoteUser
  RemotePort = $RemotePort
  SshKeyPath = $SshKeyPath
  ExpectedHostKeySha256 = $ExpectedHostKeySha256
  ReferenceAudio = $ReferenceAudio
  ReferenceTranscript = $ReferenceTranscript
  TargetText = $TargetText
  LicenseAcceptanceJson = $LicenseAcceptanceJson
  LocalOutputDir = $LocalOutputDir
  RemoteRoot = $RemoteRoot
}
if ($InstallSystemDeps) { $handoffArgs.InstallSystemDeps = $true }
& $handoff @handoffArgs
Write-Host 'MESO_FISH_ORCHESTRATOR_PRIVATE_HANDOFF_COMPLETE=true'

# Defense-in-depth: the handoff script validates all downloaded output hashes and
# deletes the remote private working directory in its finally block. This wrapper
# never provisions or destroys a cloud instance; those remain provider-side actions.
Write-Host "MesoAI Fish S2 orchestration complete: $LocalOutputDir"

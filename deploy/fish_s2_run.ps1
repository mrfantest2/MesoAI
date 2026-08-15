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
  [int]$MinVramMiB = 23000,
  [int]$MinFreeGiB = 30,
  [switch]$InstallSystemDeps
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Require-File([string]$Path, [string]$Label) {
  if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label not found: $Path" }
}

if ($ProjectSlug -notmatch '^[a-z0-9][a-z0-9_-]{1,31}$') {
  throw 'ProjectSlug must be a short shell-safe identifier.'
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

$license = Get-Content -LiteralPath $LicenseAcceptanceJson -Raw | ConvertFrom-Json
if ($license.accepted -ne $true) { throw 'Fish Audio Research License is not explicitly accepted.' }
if ([string]$license.license -notmatch '(?i)Fish Audio Research License') {
  throw 'Unexpected Fish license acceptance record.'
}
if ([string]$license.scope -notmatch '(?i)(non[- ]?commercial|personal|evaluation|research)') {
  throw 'Fish license acceptance scope must state an allowed non-commercial/research purpose.'
}
Write-Host 'MESO_FISH_ORCHESTRATOR_LICENSE_GATE=true'

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
  ProjectSlug = $ProjectSlug
  RemoteRoot = $RemoteRoot
  SharedWorkRoot = $SharedWorkRoot
}
if ($InstallSystemDeps) { $handoffArgs.InstallSystemDeps = $true }
& $handoff @handoffArgs
Write-Host 'MESO_FISH_ORCHESTRATOR_PRIVATE_HANDOFF_COMPLETE=true'

# RemoteRoot is deleted by the handoff after the project's outputs are verified.
# SharedWorkRoot contains only public Fish source/model/runtime cache and is kept so
# another authorized project (for example Khalil AI) can reuse the same paid Pod.
Write-Host "Fish S2 orchestration complete for ${ProjectSlug}: $LocalOutputDir"

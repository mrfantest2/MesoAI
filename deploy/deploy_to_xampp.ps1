param(
  [string]$RepoRoot = (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [string]$Target = 'C:\xampp\htdocs\meso',
  [string]$BackupRoot = 'C:\MesoAI\private\web-backups'
)
$ErrorActionPreference = 'Stop'
$web = Join-Path $RepoRoot 'web'
if (!(Test-Path -LiteralPath $web -PathType Container)) { throw "Web source not found: $web" }
New-Item -ItemType Directory -Force -Path $Target | Out-Null
New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null

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

# Defense in depth: no legacy backup directory may remain under the public root.
$publicBackups = Get-ChildItem -LiteralPath $Target -Force -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -like '_previous_*' }
if ($publicBackups) { throw 'Public _previous_* backup remained after deployment.' }

Write-Host "MesoAI web deployed to $Target"
Write-Host "Private web backups: $BackupRoot"
Write-Host "Local:  http://127.0.0.1/meso/"
Write-Host "Public: https://fantest.win/meso/"

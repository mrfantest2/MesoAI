param(
  [string]$RepoRoot = (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
  [string]$Target = 'C:\xampp\htdocs\meso'
)
$ErrorActionPreference = 'Stop'
$web = Join-Path $RepoRoot 'web'
if (!(Test-Path $web)) { throw "Web source not found: $web" }
New-Item -ItemType Directory -Force -Path $Target | Out-Null

# Preserve anything already in /meso by moving it into a dated folder first.
$existing = Get-ChildItem -LiteralPath $Target -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -notlike '_previous_*' }
if ($existing) {
  $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
  $backup = Join-Path $Target "_previous_$stamp"
  New-Item -ItemType Directory -Force -Path $backup | Out-Null
  foreach ($item in $existing) { Move-Item -LiteralPath $item.FullName -Destination $backup -Force }
  Write-Host "Existing /meso files preserved in $backup"
}
Copy-Item -Path (Join-Path $web '*') -Destination $Target -Recurse -Force
Write-Host "MesoAI web deployed to $Target"
Write-Host "Local:  http://127.0.0.1/meso/"
Write-Host "Public: https://fantest.win/meso/"

[CmdletBinding()]
param(
  [Parameter(Mandatory=$true)][string]$TargetWebRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$iconRoot = Join-Path $TargetWebRoot 'icons'
New-Item -ItemType Directory -Force -Path $iconRoot | Out-Null

function New-MesoPngIcon {
  param(
    [Parameter(Mandatory=$true)][int]$Size,
    [Parameter(Mandatory=$true)][string]$Path
  )

  $bitmap = New-Object System.Drawing.Bitmap($Size, $Size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
  $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
  $gradient = $null
  $font = $null
  $textBrush = $null
  $format = $null
  try {
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    $rect = New-Object System.Drawing.Rectangle(0, 0, $Size, $Size)
    $start = [System.Drawing.ColorTranslator]::FromHtml('#D8B4FE')
    $end = [System.Drawing.ColorTranslator]::FromHtml('#8B5CF6')
    $gradient = New-Object System.Drawing.Drawing2D.LinearGradientBrush($rect, $start, $end, 135.0)
    $graphics.FillRectangle($gradient, $rect)

    $fontSize = [single]($Size * 0.54)
    $font = New-Object System.Drawing.Font('Segoe UI', $fontSize, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
    $textBrush = New-Object System.Drawing.SolidBrush([System.Drawing.ColorTranslator]::FromHtml('#11131A'))
    $format = New-Object System.Drawing.StringFormat
    $format.Alignment = [System.Drawing.StringAlignment]::Center
    $format.LineAlignment = [System.Drawing.StringAlignment]::Center
    $graphics.DrawString('M', $font, $textBrush, (New-Object System.Drawing.RectangleF(0, 0, $Size, $Size)), $format)

    $bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
  } finally {
    if ($format) { $format.Dispose() }
    if ($textBrush) { $textBrush.Dispose() }
    if ($font) { $font.Dispose() }
    if ($gradient) { $gradient.Dispose() }
    $graphics.Dispose()
    $bitmap.Dispose()
  }

  $item = Get-Item -LiteralPath $Path
  if ($item.Length -lt 1000) { throw "Generated PWA icon is unexpectedly small: $Path" }
  Write-Host "MESO_PWA_ICON_GENERATED=$Path BYTES=$($item.Length)"
}

New-MesoPngIcon -Size 192 -Path (Join-Path $iconRoot 'meso-192.png')
New-MesoPngIcon -Size 512 -Path (Join-Path $iconRoot 'meso-512.png')
New-MesoPngIcon -Size 512 -Path (Join-Path $iconRoot 'meso-maskable-512.png')
New-MesoPngIcon -Size 180 -Path (Join-Path $iconRoot 'apple-touch-icon.png')

Write-Host 'MESO_PWA_ICONS_READY=true'

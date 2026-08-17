[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$RunPodApiKey,
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$SshKeyPath = 'C:\ProgramData\KhalilDigitalTwin\voice-cloud\runpod\id_ed25519',
    [decimal]$MaxHourlyUsd = 0.50,
    [int]$MinVramMiB = 23000,
    [int]$CapacityWaitMinutes = 15,
    [int]$LiveMinutes = 28
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($RunPodApiKey)) { throw 'RunPod API key is required.' }
if (!(Test-Path -LiteralPath $SshKeyPath -PathType Leaf)) { throw 'MASTER-PC RunPod SSH key is missing.' }
if ($MaxHourlyUsd -le 0 -or $MaxHourlyUsd -gt 0.50) { throw 'Maximum hourly cost must be >0 and <=0.50.' }
if ($MinVramMiB -lt 23000) { throw 'Minimum VRAM cannot be below 23000 MiB.' }
if ($CapacityWaitMinutes -lt 1 -or $CapacityWaitMinutes -gt 20) { throw 'Capacity wait must be 1-20 minutes.' }
if ($LiveMinutes -lt 5 -or $LiveMinutes -gt 60) { throw 'Live duration must be 5-60 minutes.' }

$headers = @{ Authorization = "Bearer $RunPodApiKey"; 'Content-Type' = 'application/json' }
$podId = ''
$success = $false

function Get-GpuPreference([string]$id) {
    switch -Regex ($id) {
        '^NVIDIA RTX A5000$' { return 0 }
        '^NVIDIA A40$' { return 1 }
        '^NVIDIA GeForce RTX 3090$' { return 2 }
        '^NVIDIA L4$' { return 3 }
        default { return 10 }
    }
}

function Has-Property($Object, [string]$Name) {
    return $null -ne $Object -and $null -ne $Object.PSObject.Properties[$Name]
}

try {
    $query = @'
query {
  gpuTypes {
    id
    displayName
    memoryInGb
    secureCloud
    lowestPrice(input: { gpuCount: 1, secureCloud: true }) {
      stockStatus
      uninterruptablePrice
      availableGpuCounts
    }
  }
}
'@
    $payload = @{ query = $query } | ConvertTo-Json -Depth 6
    $graphql = 'https://api.runpod.io/graphql?api_key=' + [uri]::EscapeDataString($RunPodApiKey)
    $deadline = (Get-Date).AddMinutes($CapacityWaitMinutes)
    $chosen = $null
    $attempt = 0

    do {
        $attempt++
        $response = Invoke-RestMethod -Method Post -Uri $graphql -ContentType 'application/json' -Body $payload -TimeoutSec 30
        if (Has-Property $response 'errors') {
            if ($null -ne $response.errors) { throw ('RunPod GraphQL error: ' + ($response.errors | ConvertTo-Json -Compress)) }
        }
        if (!(Has-Property $response 'data') -or !(Has-Property $response.data 'gpuTypes')) { throw 'RunPod GraphQL response is missing gpuTypes.' }

        $eligible = @()
        foreach ($g in @($response.data.gpuTypes)) {
            if (!(Has-Property $g 'secureCloud') -or $g.secureCloud -ne $true) { continue }
            if (!(Has-Property $g 'memoryInGb') -or [int]$g.memoryInGb -lt 24) { continue }
            if (!(Has-Property $g 'lowestPrice') -or $null -eq $g.lowestPrice) { continue }
            $lowest = $g.lowestPrice
            if (!(Has-Property $lowest 'uninterruptablePrice') -or $null -eq $lowest.uninterruptablePrice) { continue }
            $price = [decimal]$lowest.uninterruptablePrice
            if ($price -le 0 -or $price -gt $MaxHourlyUsd) { continue }
            $stock = if (Has-Property $lowest 'stockStatus') { [string]$lowest.stockStatus } else { 'None' }
            $counts = if (Has-Property $lowest 'availableGpuCounts') { @($lowest.availableGpuCounts | ForEach-Object { [int]$_ }) } else { @() }
            if ($stock -eq 'None' -or $counts -notcontains 1) { continue }
            if (!(Has-Property $g 'id')) { continue }
            $id = [string]$g.id
            if ($id -notmatch '^NVIDIA ') { continue }
            $eligible += [pscustomobject]@{
                id = $id
                memory = [int]$g.memoryInGb
                price = $price
                stock = $stock
                preference = (Get-GpuPreference $id)
            }
        }
        if ($eligible.Count -gt 0) {
            $chosen = @($eligible | Sort-Object @{Expression='preference';Ascending=$true}, @{Expression='price';Ascending=$true}, @{Expression='memory';Descending=$true})[0]
            break
        }
        Write-Host "MESO_FISH_LIVE_CAPACITY_WAIT_ATTEMPT=$attempt"
        Write-Host 'MESO_FISH_LIVE_NO_ELIGIBLE_SINGLE_GPU=true'
        Start-Sleep -Seconds 20
    } while ((Get-Date) -lt $deadline)

    if ($null -eq $chosen) { throw 'No Secure NVIDIA single GPU >=24 GB at <=$0.50/hour became available within the bounded wait.' }
    Write-Host ("MESO_FISH_LIVE_CAPACITY_FOUND={0} MEMORY_GB={1} PRICE={2} STOCK={3}" -f $chosen.id,$chosen.memory,$chosen.price,$chosen.stock)

    $body = @{
        name = 'meso-fish-s2-live-preflight'
        cloudType = 'SECURE'
        computeType = 'GPU'
        imageName = 'runpod/pytorch:1.0.2-cu1281-torch280-ubuntu2404'
        gpuTypeIds = @([string]$chosen.id)
        gpuTypePriority = 'availability'
        gpuCount = 1
        containerDiskInGb = 50
        volumeInGb = 0
        ports = @('22/tcp','8080/http')
        globalNetworking = $false
    } | ConvertTo-Json -Depth 6

    try {
        $pod = Invoke-RestMethod -Method Post -Uri 'https://rest.runpod.io/v1/pods' -Headers $headers -Body $body -TimeoutSec 45
    } catch {
        throw 'Eligible RunPod capacity disappeared before reservation completed.'
    }
    if ($null -eq $pod -or !(Has-Property $pod 'id') -or [string]::IsNullOrWhiteSpace([string]$pod.id)) { throw 'RunPod did not return a Pod ID.' }
    $podId = [string]$pod.id
    Write-Host "MESO_FISH_LIVE_POD_RESERVED=$podId"
    Write-Host 'MESO_FISH_LIVE_PRIVATE_TRANSFERRED=false'

    $readyDeadline = (Get-Date).AddMinutes(10)
    $ip = ''
    $sshPort = ''
    $cost = $null
    $desiredStatus = ''
    do {
        Start-Sleep -Seconds 5
        $state = Invoke-RestMethod -Method Get -Uri ("https://rest.runpod.io/v1/pods/{0}" -f $podId) -Headers @{Authorization="Bearer $RunPodApiKey"} -TimeoutSec 30
        if (Has-Property $state 'machine') {
            $machine = $state.machine
            if ($null -ne $machine -and (Has-Property $machine 'secureCloud') -and $machine.secureCloud -ne $true) { throw 'RunPod did not confirm Secure Cloud.' }
        }
        if ((Has-Property $state 'adjustedCostPerHr') -and $null -ne $state.adjustedCostPerHr -and [string]$state.adjustedCostPerHr -ne '') { $cost = [decimal]$state.adjustedCostPerHr }
        elseif ((Has-Property $state 'costPerHr') -and $null -ne $state.costPerHr -and [string]$state.costPerHr -ne '') { $cost = [decimal]$state.costPerHr }
        if ($null -ne $cost -and $cost -gt $MaxHourlyUsd) { throw "Actual RunPod price exceeds cap: $cost/hour" }
        $ip = if (Has-Property $state 'publicIp') { [string]$state.publicIp } else { '' }
        if ((Has-Property $state 'portMappings') -and $null -ne $state.portMappings -and (Has-Property $state.portMappings '22')) { $sshPort = [string]$state.portMappings.'22' }
        $desiredStatus = if (Has-Property $state 'desiredStatus') { [string]$state.desiredStatus } else { '' }
        Write-Host "MESO_FISH_LIVE_POD_STATUS=$desiredStatus COST=$cost SSH_READY=$([bool]($ip -and $sshPort))"
        if ($desiredStatus -eq 'RUNNING' -and $null -ne $cost -and $ip -and $sshPort) { break }
    } while ((Get-Date) -lt $readyDeadline)
    if ($desiredStatus -ne 'RUNNING' -or $null -eq $cost -or !$ip -or !$sshPort) { throw 'Reserved Pod did not become price-verified and SSH-ready.' }
    Write-Host "MESO_FISH_LIVE_COST_GATE_PASSED=true COST_PER_HR=$cost"

    $known = Join-Path $env:TEMP ('meso-live-reserve-known-' + [guid]::NewGuid().ToString('N') + '.txt')
    try {
        $accept = @('-p',$sshPort,'-i',$SshKeyPath,'-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o',"UserKnownHostsFile=$known",'-o','StrictHostKeyChecking=accept-new','-o','ConnectTimeout=20')
        $readyText = & ssh @accept ("root@"+$ip) "printf 'ready'"
        if ($LASTEXITCODE -ne 0 -or [string]($readyText -join '') -notmatch 'ready') { throw 'Zero-data SSH authentication failed.' }
        $fpLine = (& ssh-keygen -lf $known -E sha256 | Select-Object -First 1)
        if ($LASTEXITCODE -ne 0) { throw 'Unable to calculate SSH fingerprint.' }
        $match = [regex]::Match([string]$fpLine,'SHA256:[A-Za-z0-9+/=]+')
        if (!$match.Success) { throw 'Unable to parse SSH fingerprint.' }
        $fingerprint = $match.Value

        $strict = @('-p',$sshPort,'-i',$SshKeyPath,'-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o',"UserKnownHostsFile=$known",'-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=20')
        $probe = "printf 'gpu='; nvidia-smi --query-gpu=name --format=csv,noheader | head -n1; printf 'vram_mib='; nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d '[:space:]'; printf '\n'"
        $gpuOut = & ssh @strict ("root@"+$ip) $probe
        if ($LASTEXITCODE -ne 0) { throw 'Zero-data GPU probe failed.' }
        $gpuText = [string]($gpuOut -join "`n")
        $gpuName = ([regex]::Match($gpuText,'(?m)^gpu=(.+)$')).Groups[1].Value.Trim()
        $vram = ([regex]::Match($gpuText,'vram_mib=(\d+)')).Groups[1].Value
        if ([string]::IsNullOrWhiteSpace($gpuName) -or $gpuName -notmatch '(?i)NVIDIA') { throw "Unexpected GPU: $gpuName" }
        if (!$vram -or [int]$vram -lt $MinVramMiB) { throw "GPU VRAM below requirement: $vram MiB" }
        Write-Host "MESO_FISH_LIVE_GPU=$gpuName"
        Write-Host "MESO_FISH_LIVE_VRAM_MIB=$vram"
        Write-Host "MESO_FISH_LIVE_SSH_FINGERPRINT=$fingerprint"
        Write-Host 'MESO_FISH_LIVE_ZERO_DATA_PREFLIGHT=true'
        Write-Host 'MESO_FISH_LIVE_PRIVATE_TRANSFERRED=false'
    } finally {
        Remove-Item -LiteralPath $known -Force -ErrorAction SilentlyContinue
    }

    $activeRoot = 'C:\ProgramData\KhalilDigitalTwin\voice-cloud\runpod'
    New-Item -ItemType Directory -Force -Path $activeRoot | Out-Null
    $reservation = [ordered]@{
        pod_id=$podId;public_ip=$ip;ssh_port=[int]$sshPort;cost_per_hour=$cost;max_cost_per_hour=$MaxHourlyUsd
        cloud_type='SECURE';gpu=$gpuName;vram_mib=[int]$vram;persistent_volume_gb=0
        ssh_host_fingerprint=$fingerprint;private_data_transferred=$false
        checkpoint='secure_gpu_reserved_zero_data_preflight_passed';recorded_at=(Get-Date).ToUniversalTime().ToString('o')
    }
    [System.IO.File]::WriteAllText((Join-Path $activeRoot 'active-pod.json'),(($reservation|ConvertTo-Json -Depth 6)+"`n"),(New-Object System.Text.UTF8Encoding($false)))

    & (Join-Path $RepoRoot 'deploy\start_fish_live_service.ps1') `
        -PodId $podId `
        -RemoteHost $ip `
        -RemotePort ([int]$sshPort) `
        -ExpectedHostKeySha256 $fingerprint `
        -SshKeyPath $SshKeyPath `
        -RepoRoot $RepoRoot `
        -LiveMinutes $LiveMinutes
    if ($LASTEXITCODE -ne 0) { throw 'Meso Fish live service handoff failed.' }

    $success = $true
    Write-Host 'MESO_FISH_LIVE_DYNAMIC_PREFLIGHT_COMPLETE=true'
    Write-Host "MESO_FISH_LIVE_ACTIVE_POD_ID=$podId"
} finally {
    if (!$success -and -not [string]::IsNullOrWhiteSpace($podId)) {
        Remove-Item -LiteralPath 'C:\MesoAI\private\fish-live\config.json' -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath 'C:\MesoAI\private\fish-live\state.json' -Force -ErrorAction SilentlyContinue
        try {
            Invoke-RestMethod -Method Delete -Uri ("https://rest.runpod.io/v1/pods/{0}" -f $podId) -Headers @{Authorization="Bearer $RunPodApiKey"} -TimeoutSec 30 | Out-Null
            Write-Host "MESO_FISH_LIVE_FAILED_POD_DELETED=$podId"
        } catch {
            Write-Warning "Unable to confirm failed Pod deletion: $($_.Exception.Message)"
        }
    }
}

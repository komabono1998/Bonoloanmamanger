# One-step server set-up for the office computer (run after XAMPP is installed and the package is unzipped into htdocs).
# Creates the data folders, writes env.php with a fresh secret key, opens the private-network firewall and prints the address to share.
param([string]$Drive = '', [string]$DataRoot = '', [switch]$SkipFirewall)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Write-Host ''
Write-Host 'Bono Loan Manager - office server set-up' -ForegroundColor Cyan
Write-Host "Program folder: $root"

if (-not (Test-Path (Join-Path $root 'index.php'))) { throw 'index.php was not found: run this from the unzipped program folder (deploy\lan).' }

# 1. where the data lives (a second drive is safer than the system drive)
if ($DataRoot -eq '') {
    if ($Drive -eq '') {
        $default = if (Test-Path 'D:\') { 'D' } else { 'C' }
        $ans = Read-Host "Drive letter for the data (db, documents, backups) [$default]"
        $Drive = if ($ans.Trim() -eq '') { $default } else { $ans.Trim().Substring(0, 1).ToUpper() }
    }
    if (-not (Test-Path "${Drive}:\")) { throw "Drive ${Drive}: does not exist." }
    $DataRoot = "${Drive}:\BonoData"
}
foreach ($d in 'db', 'uploads', 'backups') { New-Item -ItemType Directory -Force -Path (Join-Path $DataRoot $d) | Out-Null }
Write-Host "Data folders ready in $DataRoot" -ForegroundColor Green

# 2. configuration with a new random secret key (never overwrites an existing env.php)
$envFile = Join-Path $root 'env.php'
if (Test-Path $envFile) {
    Write-Host 'env.php already exists: left unchanged.' -ForegroundColor Yellow
} else {
    $bytes = New-Object byte[] 24; [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $key = ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''
    $fw = $DataRoot -replace '\\', '/'
    $php = @"
<?php
return [
    'APP_ENV' => 'lan',
    'APP_KEY' => '$key',
    'DB_DRIVER' => 'sqlite',
    'SQLITE_PATH' => '$fw/db/bono.sqlite',
    'STORAGE_DRIVER' => 'local',
    'STORAGE_PATH' => '$fw/uploads',
    'BACKUP_DIR' => '$fw/backups',
    'SELF_REGISTRATION' => '0',
];
"@
    [System.IO.File]::WriteAllText($envFile, $php, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host 'env.php written (keep a copy of the APP_KEY in a safe place).' -ForegroundColor Green
}

# 3. let the private office network reach the web server
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if ($SkipFirewall) { Write-Host 'Firewall step skipped.' }
elseif ($isAdmin) {
    Get-NetFirewallRule -DisplayName 'Bono Loan Manager (office network)' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    New-NetFirewallRule -DisplayName 'Bono Loan Manager (office network)' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80 -Profile Private | Out-Null
    Write-Host 'Firewall: private networks may reach port 80.' -ForegroundColor Green
} else {
    Write-Host 'Firewall not changed (not running as Administrator). Right-click setup-server.bat > Run as administrator to do this step too.' -ForegroundColor Yellow
}

# 4. the address to share
$ips = Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.PrefixOrigin -ne 'WellKnown' } | Select-Object -ExpandProperty IPAddress
$folder = Split-Path $root -Leaf
Write-Host ''
Write-Host 'Open the system from other computers and phones at:' -ForegroundColor Cyan
foreach ($ip in $ips) { Write-Host "    http://$ip/$folder/" -ForegroundColor White }
Write-Host ''
Write-Host 'Checklist:' -ForegroundColor Cyan
Write-Host '  1. Reserve this computer''s address in the router (so it does not change).'
Write-Host '  2. Set the Wi-Fi/Ethernet to a PRIVATE network in Windows (Settings > Network).'
Write-Host '  3. In XAMPP Control Panel tick "Service" for Apache and start it.'
Write-Host '  4. Open the address above: the first page creates your organisation.'
Write-Host '  5. Schedule deploy\lan\backup-copy.bat daily (Task Scheduler).'

<#
  Equitable System: HTTPS for XAMPP Apache, so phones on the LAN can use the camera
  (Serial Inventory > Scan). Browsers only allow the camera on https:// pages.

  What it does:
    1. Makes a certificate for localhost, 127.0.0.1, this PC's name and its LAN IP
       address(es): with mkcert when it is installed (trusted, no browser warning once
       mkcert's root certificate is on the phone), otherwise a self-signed one
       (valid 825 days) with XAMPP's own openssl.exe.
    2. Saves it as conf\ssl.crt\equitable.crt and conf\ssl.key\equitable.key.
    3. Backs up conf\extra\httpd-ssl.conf and points it at the new certificate.
  Then restart Apache in the XAMPP Control Panel.

  Usage (from the project folder):  tools\https\setup-https.bat
    -XamppDir C:\xampp      XAMPP folder (default C:\xampp)
    -ExtraNames a,b         more host names / IPs to include
    -OutDir <folder>        only write the certificate there; don't touch Apache
    -UseOpenSSL             use openssl even when mkcert is installed
  See docs\HTTPS_SETUP.md for the whole procedure.
#>
param(
    [string]   $XamppDir   = 'C:\xampp',
    [string[]] $ExtraNames = @(),
    [string]   $OutDir     = '',
    [switch]   $UseOpenSSL
)
$ErrorActionPreference = 'Stop'

$openssl = Join-Path $XamppDir 'apache\bin\openssl.exe'
if (-not (Test-Path $openssl)) { throw "openssl.exe not found at $openssl. Pass -XamppDir if XAMPP is somewhere else." }

# Names the certificate must cover.
$ips = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
         Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.PrefixOrigin -ne 'WellKnown' } |
         Select-Object -ExpandProperty IPAddress)
$dns = @('localhost', $env:COMPUTERNAME) + ($ExtraNames | Where-Object { $_ -notmatch '^\d+\.\d+\.\d+\.\d+$' })
$ips = @('127.0.0.1') + $ips + ($ExtraNames | Where-Object { $_ -match '^\d+\.\d+\.\d+\.\d+$' })
$san = (($dns | Select-Object -Unique | ForEach-Object { "DNS:$_" }) + ($ips | Select-Object -Unique | ForEach-Object { "IP:$_" })) -join ','

if ($OutDir) {
    New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
    $crt = Join-Path $OutDir 'equitable.crt'
    $key = Join-Path $OutDir 'equitable.key'
} else {
    $crt = Join-Path $XamppDir 'apache\conf\ssl.crt\equitable.crt'
    $key = Join-Path $XamppDir 'apache\conf\ssl.key\equitable.key'
}

$mkcert = if ($UseOpenSSL) { $null } else { Get-Command mkcert -ErrorAction SilentlyContinue }
if ($mkcert) {
    # Trusted local certificate authority (asks once to install its root certificate on this PC).
    Write-Host "Using mkcert for: $san"
    $ErrorActionPreference = 'Continue'
    & $mkcert.Source -install 2>&1 | ForEach-Object { Write-Host "  $_" }
    $names = @($dns | Select-Object -Unique) + @($ips | Select-Object -Unique)
    & $mkcert.Source -cert-file $crt -key-file $key @names 2>&1 | ForEach-Object { Write-Host "  $_" }
    $exit = $LASTEXITCODE
    $caRoot = (& $mkcert.Source -CAROOT) 2>$null
    $ErrorActionPreference = 'Stop'
    if ($exit -ne 0 -or -not (Test-Path $crt)) { throw 'mkcert could not create the certificate.' }
    Write-Host "Phones: install $caRootootCA.pem (never share rootCA-key.pem)."
} else {
$cnf = Join-Path $env:TEMP 'equitable-openssl.cnf'
@"
[req]
distinguished_name = dn
x509_extensions    = ext
prompt             = no
[dn]
CN = Equitable System ($env:COMPUTERNAME)
O  = Equitable System
[ext]
subjectAltName   = $san
basicConstraints = critical, CA:FALSE
keyUsage         = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
"@ | Set-Content -Path $cnf -Encoding ascii

Write-Host "Making a certificate for: $san"
# openssl writes progress to stderr, which PowerShell 5.1 would treat as an error.
$ErrorActionPreference = 'Continue'
& $openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 825 -config $cnf -keyout $key -out $crt 2>&1 | Out-Null
$exit = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
Remove-Item $cnf -ErrorAction SilentlyContinue
if ($exit -ne 0 -or -not (Test-Path $crt)) { throw 'openssl could not create the certificate.' }
}
Write-Host "Certificate: $crt"
Write-Host "Key:         $key"

if ($OutDir) {
    Write-Host 'OutDir given: Apache was not changed.'
    exit 0
}

# Point Apache's SSL site at the new files (keeping a dated backup).
$conf = Join-Path $XamppDir 'apache\conf\extra\httpd-ssl.conf'
$backup = "$conf.bak-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
Copy-Item $conf $backup
$text = Get-Content $conf -Raw
$text = $text -replace '(?m)^\s*SSLCertificateFile\s+.*$',    'SSLCertificateFile "conf/ssl.crt/equitable.crt"'
$text = $text -replace '(?m)^\s*SSLCertificateKeyFile\s+.*$', 'SSLCertificateKeyFile "conf/ssl.key/equitable.key"'
Set-Content -Path $conf -Value $text -Encoding ascii -NoNewline
Write-Host "Updated $conf (backup: $backup)"

# Check the Apache configuration before the restart.
$httpd = Join-Path $XamppDir 'apache\bin\httpd.exe'
$ErrorActionPreference = 'Continue'
$check = & $httpd -t 2>&1 | ForEach-Object { "$_" }
$ErrorActionPreference = 'Stop'
Write-Host ($check -join "`n")

Write-Host ''
Write-Host 'Next:'
Write-Host '  1. Restart Apache in the XAMPP Control Panel (Stop, then Start).'
Write-Host '  2. Allow port 443 in Windows Firewall if phones cannot connect (see docs\HTTPS_SETUP.md).'
foreach ($ip in $ips | Where-Object { $_ -ne '127.0.0.1' }) {
    Write-Host ("  3. On the phone open https://$ip/equitable_system/serial_scan.php" + $(if ($mkcert) { ' (after installing rootCA.pem on it).' } else { ' and accept the certificate warning once.' }))
}

#Requires -Version 5.1
<#
.SYNOPSIS
  SmartTerminal backend launcher (LavaLust / PHP built-in server).

.DESCRIPTION
  FIX: A server started as plain "php -S localhost:8000" (no docroot/router)
  serves files from the current directory and 404s every /api/* request,
  so the Vue login always showed "Login failed. Check username and password."

  Correct command used here:
    php -S localhost:8000 -t .\public .\public\router.php

.NOTES
  Keep this window open. Press Ctrl+C to stop.
#>

$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

# Kill old (broken) listener on :8000, if any
$listener = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue |
  Select-Object -First 1
if ($listener) {
  Write-Host "Killing old process on port 8000: PID $($listener.OwningProcess)" -ForegroundColor Yellow
  Stop-Process -Id $listener.OwningProcess -Force -ErrorAction SilentlyContinue
}

$php = 'C:\wamp64\bin\php\php8.4.0\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }

Write-Host "`nStarting SmartTerminal backend on http://localhost:8000 ..." -ForegroundColor Green
Write-Host "(Keep this window open. Press Ctrl+C to stop.)`n" -ForegroundColor DarkGray

& $php -S localhost:8000 -t "$PSScriptRoot\public" "$PSScriptRoot\public\router.php"

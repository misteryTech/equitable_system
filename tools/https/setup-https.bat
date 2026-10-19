@echo off
rem Equitable System: set up HTTPS for XAMPP Apache (see README.md, "HTTPS for phone cameras").
rem Run from any folder; pass options through, e.g.  setup-https.bat -ExtraNames equitable.local
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-https.ps1" %*
pause

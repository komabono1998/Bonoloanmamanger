@echo off
rem Right-click > Run as administrator (so the firewall step can run too).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-server.ps1"
echo.
pause

@echo off
rem Run this file AS ADMINISTRATOR (right-click > Run as administrator) on the server computer.
rem It lets computers on your PRIVATE office network reach the web server (port 80). Public networks stay blocked.
net session >nul 2>&1
if %errorlevel% neq 0 (
  echo This must be run as Administrator. Right-click the file and choose "Run as administrator".
  pause
  exit /b 1
)
netsh advfirewall firewall delete rule name="Bono Loan Manager (office network)" >nul 2>&1
netsh advfirewall firewall add rule name="Bono Loan Manager (office network)" dir=in action=allow protocol=TCP localport=80 profile=private
echo.
echo Done. Other computers on the office network can now open  http://THIS-COMPUTER-ADDRESS/bono/
echo This computer's addresses:
ipconfig | findstr /i "IPv4"
echo.
echo Make sure Windows treats your network as "Private" (Settings ^> Network ^& Internet ^> Properties).
pause

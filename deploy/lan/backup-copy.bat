@echo off
rem Copies the system's backups and uploaded documents to a SECOND place (USB disk, network drive or cloud-synced folder).
rem 1. Change SRC and DEST below.   2. Test it by double-clicking.   3. Schedule it daily:
rem    Task Scheduler > Create Basic Task > Daily at 19:30 > Start a program > this file.
set SRC=D:\BonoData
set DEST=E:\BonoBackupCopy

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmm"') do set TS=%%i
if not exist "%DEST%" mkdir "%DEST%"
set TARGET=%DEST%\%TS%
mkdir "%TARGET%"
rem backups are consistent copies made by the system; documents are plain files
robocopy "%SRC%\backups" "%TARGET%\backups" /E /R:2 /W:2 /NFL /NDL /NP
robocopy "%SRC%\uploads" "%TARGET%\uploads" /E /R:2 /W:2 /NFL /NDL /NP
rem keep the last 30 daily copies
forfiles /p "%DEST%" /d -30 /c "cmd /c if @isdir==TRUE rmdir /s /q @path" >nul 2>&1
echo Copied to %TARGET%

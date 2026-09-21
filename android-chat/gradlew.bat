@echo off
setlocal
set "GRADLE_VERSION=8.13"
set "BOOTSTRAP_ROOT=%USERPROFILE%\.gradle\khalil-bootstrap"
set "DIST_DIR=%BOOTSTRAP_ROOT%\gradle-%GRADLE_VERSION%"
set "ZIP_FILE=%BOOTSTRAP_ROOT%\gradle-%GRADLE_VERSION%-bin.zip"
set "POWERSHELL=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"

if exist "%DIST_DIR%\bin\gradle.bat" goto run
if not exist "%POWERSHELL%" (
  echo Windows PowerShell not found at "%POWERSHELL%".
  exit /b 1
)
if not exist "%BOOTSTRAP_ROOT%" mkdir "%BOOTSTRAP_ROOT%"
echo Khalil Digital Twin: bootstrapping Gradle %GRADLE_VERSION%...
"%POWERSHELL%" -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $root='%BOOTSTRAP_ROOT%'; $zip='%ZIP_FILE%'; $dist='%DIST_DIR%'; if(-not (Test-Path $zip)){Invoke-WebRequest -UseBasicParsing -Uri 'https://services.gradle.org/distributions/gradle-8.13-bin.zip' -OutFile $zip}; if(-not (Test-Path ($dist + '\bin\gradle.bat'))){Expand-Archive -Path $zip -DestinationPath $root -Force}"
if errorlevel 1 exit /b %errorlevel%

:run
if not exist "%DIST_DIR%\bin\gradle.bat" (
  echo Gradle bootstrap failed: "%DIST_DIR%\bin\gradle.bat" not found.
  exit /b 1
)
call "%DIST_DIR%\bin\gradle.bat" %*
exit /b %errorlevel%

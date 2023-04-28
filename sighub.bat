@echo off

if "%1" == "install" (
    composer install & PHP artisan sighub install
) else (
    PHP %~dp0artisan sighub %1 %2 %3
)
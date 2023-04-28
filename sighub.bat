@echo off

if "%1" == "install" (
    composer install & PHP artisan sighub install
) else (
    PHP artisan sighub %1 %2 %3
)

#requires -Version 5.1
$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    # Construir antes de validar para usar el cliente actualizado.
    docker compose up -d --build --no-deps reportes-service
    if($LASTEXITCODE -ne 0){throw 'No se pudo preparar Reportes'}
    $weatherSecure=Read-Host 'API key de OpenWeatherMap (oculta)' -AsSecureString
    $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($weatherSecure)
    try {$weatherKey=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)}
    if($weatherKey -notmatch '^[A-Za-z0-9_-]{16,128}$'){throw 'Formato de clave inválido'}
    # Verifica la clave desde Reportes. No la muestra ni la envía como argumento.
    $probe=@'
<?php
require '/app/vendor/autoload.php';
require '/app/src/integrations/WeatherClient.php';
putenv('OPENWEATHER_API_KEY=__WEATHER_KEY__');
$weather=WeatherClient::current();
if(($weather['disponible'] ?? false)!==true){fwrite(STDERR,($weather['mensaje'] ?? 'No se pudo consultar el clima')."\n");exit(1);}
echo "Consulta real de OpenWeatherMap correcta.\n";
'@
    # La clave ya se validó como alfanumérica: no puede introducir código PHP.
    # Enviar el programa por stdin evita el escape de comillas de PowerShell 5.1.
    $probe.Replace('__WEATHER_KEY__',$weatherKey) | docker compose exec -T reportes-service php
    if($LASTEXITCODE -ne 0){throw 'No se modificó .env porque no se pudo consultar el clima'}
    $text=Get-Content -Raw -Encoding UTF8 -LiteralPath '.env'
    if($text -match '(?m)^OPENWEATHER_API_KEY=.*$'){$text=[regex]::Replace($text,'(?m)^OPENWEATHER_API_KEY=.*$',"OPENWEATHER_API_KEY=$weatherKey")}else{$text+="`nOPENWEATHER_API_KEY=$weatherKey`n"}
    [IO.File]::WriteAllText((Join-Path (Get-Location) '.env'),$text,[Text.UTF8Encoding]::new($false))
    docker compose up -d --build --no-deps reportes-service
    if($LASTEXITCODE -ne 0){throw 'No se pudo cargar la clave en Reportes'}
    docker compose exec -T gateway nginx -s reload
    if($LASTEXITCODE -ne 0){throw 'No se pudo recargar Nginx'}
    Write-Output 'Clima configurado. Abre Dashboard o Reportes para verlo.'
} finally {$probe=$null;$weatherKey=$null;$weatherSecure=$null;Pop-Location}

#requires -Version 5.1
$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    $weatherSecure=Read-Host 'API key de OpenWeatherMap (oculta)' -AsSecureString
    $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($weatherSecure)
    try {$weatherKey=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)}
    if($weatherKey -notmatch '^[A-Za-z0-9_-]{16,128}$'){throw 'Formato de clave inválido'}
    # Verifica la clave desde Reportes. No la muestra ni la envía como argumento.
    $probe=@'
require '/app/src/integrations/WeatherClient.php';
$data=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);putenv('OPENWEATHER_API_KEY='.$data['key']);
$weather=WeatherClient::current();
if(($weather['disponible'] ?? false)!==true){fwrite(STDERR,"No se pudo validar la clave de clima. Revisa su activación y conexión.\n");exit(1);}
echo "Consulta real de OpenWeatherMap correcta.\n";
'@
    @{key=$weatherKey} | ConvertTo-Json -Compress | docker compose exec -T reportes-service php -r $probe
    if($LASTEXITCODE -ne 0){throw 'No se modificó .env porque no se pudo consultar el clima'}
    $text=Get-Content -Raw -Encoding UTF8 -LiteralPath '.env'
    if($text -match '(?m)^OPENWEATHER_API_KEY=.*$'){$text=[regex]::Replace($text,'(?m)^OPENWEATHER_API_KEY=.*$',"OPENWEATHER_API_KEY=$weatherKey")}else{$text+="`nOPENWEATHER_API_KEY=$weatherKey`n"}
    [IO.File]::WriteAllText((Join-Path (Get-Location) '.env'),$text,[Text.UTF8Encoding]::new($false))
    docker compose up -d --no-deps reportes-service
    if($LASTEXITCODE -ne 0){throw 'No se pudo cargar la clave en Reportes'}
    docker compose exec -T gateway nginx -s reload
    if($LASTEXITCODE -ne 0){throw 'No se pudo recargar Nginx'}
    Write-Output 'Clima configurado. Abre Dashboard o Reportes para verlo.'
} finally {$weatherKey=$null;$weatherSecure=$null;Pop-Location}

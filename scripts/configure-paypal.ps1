#requires -Version 5.1
$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    # Introducir solo las credenciales de una aplicación Sandbox de PayPal Developer.
    $paypalClient=Read-Host 'Client ID de Sandbox'
    $paypalSecure=Read-Host 'Secret de Sandbox (oculto)' -AsSecureString
    $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($paypalSecure)
    try {$paypalSecret=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)}
    if($paypalClient -notmatch '^[A-Za-z0-9_-]{20,300}$' -or $paypalSecret -notmatch '^[A-Za-z0-9_-]{20,300}$'){throw 'Revisa el Client ID y Secret de tu app Sandbox'}
    # Se envían por stdin, nunca como argumentos ni salida de consola.
    $probe=@'
require '/app/src/TokenService.php'; require '/app/src/Checkout.php'; require '/app/src/integrations/PayPalClient.php';
$data=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
putenv('PAYPAL_CLIENT_ID='.$data['client']);putenv('PAYPAL_CLIENT_SECRET='.$data['secret']);
try {(new PayPalClient())->authenticate();echo "Credenciales Sandbox verificadas.\n";}
catch(Throwable $error){fwrite(STDERR,"No se verificaron las credenciales con PayPal Sandbox.\n");exit(1);}
'@
    @{client=$paypalClient;secret=$paypalSecret} | ConvertTo-Json -Compress | docker compose exec -T ventas-service php -r $probe
    if($LASTEXITCODE -ne 0){throw 'No se modificó .env porque PayPal no aceptó las credenciales'}
    $text=Get-Content -Raw -Encoding UTF8 -LiteralPath '.env'
    foreach($entry in @(@('PAYPAL_CLIENT_ID',$paypalClient),@('PAYPAL_CLIENT_SECRET',$paypalSecret))) {
        $name=$entry[0];$value=$entry[1]
        if($text -match "(?m)^${name}=.*$"){$text=[regex]::Replace($text,"(?m)^${name}=.*$","$name=$value")}else{$text+="`n$name=$value`n"}
    }
    [IO.File]::WriteAllText((Join-Path (Get-Location) '.env'),$text,[Text.UTF8Encoding]::new($false))
    docker compose up -d --no-deps ventas-service ventas-secondary
    if($LASTEXITCODE -ne 0){throw 'No se pudieron cargar las credenciales en los servicios'}
    docker compose exec -T gateway nginx -s reload
    if($LASTEXITCODE -ne 0){throw 'No se pudo recargar Nginx'}
    Write-Output 'Sandbox configurado. Vuelve a iniciar sesión para ver PayPal habilitado en Caja.'
} finally {$paypalSecret=$null;$paypalSecure=$null;Pop-Location}

$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    $instances=@()
    foreach($i in 1..12){$instances+=(Invoke-RestMethod 'http://localhost:8080/api/ventas/health').instancia}
    if(($instances | Select-Object -Unique).Count -ne 2){throw 'No se observaron las dos instancias'}
    Write-Output 'PASS: Nginx distribuye peticiones entre dos instancias de Ventas.'
    # Solo detiene la segunda instancia para comprobar la continuidad, y la restaura siempre.
    try {
        docker compose stop ventas-secondary
        if($LASTEXITCODE -ne 0){throw 'No se pudo detener la instancia secundaria'}
        foreach($i in 1..6){if((Invoke-RestMethod 'http://localhost:8080/api/ventas/health').status -ne 'ok'){throw 'Ventas no respondió'}}
        Write-Output 'PASS: Ventas responde con una instancia detenida.'
    } finally {
        docker compose start ventas-secondary
        if($LASTEXITCODE -ne 0){throw 'No se pudo restaurar Ventas secundaria'}
        docker compose exec -T gateway nginx -s reload
    }
} finally {Pop-Location}

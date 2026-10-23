<?php
declare(strict_types=1);
// Valida el contrato del proveedor sin inventar que hubo una consulta real.
require '/app/src/integrations/WeatherClient.php';
$old=getenv('OPENWEATHER_API_KEY'); putenv('OPENWEATHER_API_KEY=');
try {
    if(WeatherClient::current()['disponible']!==false) throw new RuntimeException('Aceptó una clave faltante');
    $data=WeatherClient::normalize(['name'=>'Guatemala','main'=>['temp'=>22.65,'humidity'=>70],'weather'=>[['description'=>'cielo claro']],'dt'=>1792771200]);
    if($data['temperatura']!==22.7 || $data['humedad']!==70 || $data['fuente']!=='OpenWeatherMap') throw new RuntimeException('Contrato inválido');
    $rejected=false; try {WeatherClient::normalize(['name'=>'Incompleto']);} catch(RuntimeException $error){$rejected=true;}
    if(!$rejected) throw new RuntimeException('Aceptó una respuesta incompleta');
    echo "PASS: Clave faltante, contrato y rechazo de datos incompletos (3 comprobaciones).\n";
} finally {if($old===false) putenv('OPENWEATHER_API_KEY'); else putenv('OPENWEATHER_API_KEY='.$old);}

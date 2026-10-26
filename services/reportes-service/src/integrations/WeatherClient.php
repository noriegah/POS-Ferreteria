<?php
declare(strict_types=1);

// La clave se usa solo en el servidor. La interfaz recibe únicamente datos del clima.
final class WeatherClient
{
    public static function normalize(array $data): array
    {
        if(!is_numeric($data['main']['temp'] ?? null) || !is_numeric($data['main']['humidity'] ?? null)
            || !is_string($data['name'] ?? null) || !is_string($data['weather'][0]['description'] ?? null) || !is_int($data['dt'] ?? null)) {
            throw new RuntimeException('Respuesta meteorológica inválida');
        }
        return ['disponible'=>true,'ciudad'=>mb_substr($data['name'],0,100),'temperatura'=>round((float)$data['main']['temp'],1),
            'codigo'=>(int)($data['weather'][0]['id'] ?? 0),
            'humedad'=>(int)$data['main']['humidity'],'descripcion'=>mb_substr($data['weather'][0]['description'],0,150),
            'observado_en'=>gmdate('c',$data['dt']),'fuente'=>'OpenWeatherMap'];
    }

    public static function current(): array
    {
        $key=getenv('OPENWEATHER_API_KEY');
        if(!$key) return ['disponible'=>false,'mensaje'=>'Clima pendiente de configurar OpenWeatherMap'];
        $lat=getenv('WEATHER_LAT') ?: '15.02681'; $lon=getenv('WEATHER_LON') ?: '-90.87815';
        if(!is_numeric($lat) || !is_numeric($lon) || abs((float)$lat)>90 || abs((float)$lon)>180) return ['disponible'=>false,'mensaje'=>'Revisa la ubicación del clima'];
        $cache=sys_get_temp_dir().'/ferresync-weather-'.hash('sha256','v2,'. $lat.','.$lon.','.$key).'.json';
        // Diez minutos de caché reducen llamadas al proveedor. No se inventan datos si falla.
        if(is_file($cache) && filemtime($cache)>time()-600) {
            $saved=json_decode((string)file_get_contents($cache),true);
            if(is_array($saved) && ($saved['disponible'] ?? false)===true) return $saved;
        }
        $url='https://api.openweathermap.org/data/2.5/weather?'.http_build_query(['lat'=>$lat,'lon'=>$lon,'appid'=>$key,'units'=>'metric','lang'=>'es']);
        $curl=curl_init($url); $raw='';
        curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$raw): int {
                if(strlen($raw)+strlen($chunk)>65536) return 0; $raw.=$chunk; return strlen($chunk);
            }]);
        $ok=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        try {
            if($ok===false) return ['disponible'=>false,'mensaje'=>'No se pudo conectar con OpenWeatherMap. Revisa Internet, DNS o certificados del contenedor.'];
            if($status===401) return ['disponible'=>false,'mensaje'=>'OpenWeatherMap rechazó la clave (HTTP 401). Comprueba la clave y su activación en tu cuenta.'];
            if($status===429) return ['disponible'=>false,'mensaje'=>'OpenWeatherMap alcanzó el límite de consultas (HTTP 429). Reintenta más tarde.'];
            if($status!==200) return ['disponible'=>false,'mensaje'=>'OpenWeatherMap respondió con HTTP '.(int)$status.'. No se pudo consultar el clima.'];
            $data=self::normalize(json_decode($raw,true,32,JSON_THROW_ON_ERROR));
            file_put_contents($cache,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX); return $data;
        } catch(Throwable $error) { return ['disponible'=>false,'mensaje'=>'No se pudo procesar la respuesta del clima. Revisa las dependencias PHP y el formato del proveedor']; }
    }
}

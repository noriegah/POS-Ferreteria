# Clima con OpenWeatherMap

El cliente está en services/reportes-service/src/integrations/WeatherClient.php. GET /api/reportes/clima requiere una sesión activa de administrador o cajero, confirmada por REST con Autenticación (`AUTH_URL`). La interfaz muestra un único cuadro compacto en Dashboard de ambos roles, con clima actual y afluencia orientativa.

Añadir una clave propia a OPENWEATHER_API_KEY en .env y ajustar WEATHER_LAT / WEATHER_LON a la ubicación deseada. Los valores predeterminados corresponden a Zacualpa, Quiché (15.02681, -90.87815). Recrear Reportes con docker compose up -d --no-deps reportes-service para cargar las nuevas variables.

La solicitud usa HTTPS, unidades métricas y español. La clave nunca se entrega al navegador. Se conserva una caché de diez minutos. Ante un fallo del proveedor se muestra que no se pudo consultar el clima, sin bloquear el resto del sistema. Sin clave aparece pendiente de configurar, sin datos ficticios.

Se comprobaron tres casos locales: falta de clave, normalización de la respuesta esperada y rechazo de una respuesta incompleta. La consulta real queda pendiente hasta disponer de la clave del usuario; esta integración todavía no cuenta como una segunda API validada.

Referencia oficial: https://openweathermap.org/current

## Afluencia orientativa

Cielo despejado o pocas nubes (800–801): alta afluencia presencial. Cielo nublado (802–804), precipitaciones (2xx–6xx) o condiciones adversas (7xx): baja afluencia presencial y mayor preferencia estimada por pedidos en línea. Se usan los códigos oficiales del proveedor, no el texto traducido. Un código desconocido o un fallo de consulta no genera estimación.

Estas reglas responden al supuesto del negocio solicitado: no usan ventas históricas, no calculan cantidades de personas ni confirman pedidos en línea. El dato meteorológico es actual, no un pronóstico futuro.

El acceso del cajero es únicamente al clima. Los reportes, gráficas y PDF siguen requiriendo el rol administrador.

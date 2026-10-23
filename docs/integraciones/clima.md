# Clima con OpenWeatherMap

El cliente está en services/reportes-service/src/integrations/WeatherClient.php. GET /api/reportes/clima requiere un JWT de administrador, confirmado por el flujo REST de Reportes a Ventas. La interfaz muestra el resultado en Dashboard y Reportes.

Añadir una clave propia a OPENWEATHER_API_KEY en .env y ajustar WEATHER_LAT / WEATHER_LON a la ubicación deseada. Los valores predeterminados corresponden a Ciudad de Guatemala. Recrear Reportes con docker compose up -d --no-deps reportes-service para cargar las nuevas variables.

La solicitud usa HTTPS, unidades métricas y español. La clave nunca se entrega al navegador. Se conserva una caché de diez minutos. Ante un fallo del proveedor se muestra que no se pudo consultar el clima, sin bloquear el resto del sistema. Sin clave aparece pendiente de configurar, sin datos ficticios.

Se comprobaron tres casos locales: falta de clave, normalización de la respuesta esperada y rechazo de una respuesta incompleta. La consulta real queda pendiente hasta disponer de la clave del usuario; esta integración todavía no cuenta como una segunda API validada.

Referencia oficial: https://openweathermap.org/current

# Reportes de ventas

El administrador abre **Reportes** en el menú. Al entrar se consulta el año actual; se pueden elegir otras fechas y pulsar **Aplicar filtros**. El cajero no ve el módulo y la API también le rechaza el acceso.

## Qué muestra

- Total vendido en Q, cantidad de ventas, promedio por venta y unidades vendidas.
- Gráfica Chart.js por día, semana o mes; incluye los períodos sin ventas en cero y una tabla con los valores exactos.
- Las 8 categorías más vendidas por unidades, con su importe.
- Los 8 productos más vendidos por unidades, con su importe.
- Listado de ventas confirmadas, de más reciente a más antigua, en páginas de 25.
- Exportación PDF con resumen, gráfica, rankings, serie y todas las ventas del período.

Todos los importes incluyen IVA; el total vendido no representa utilidad ni ganancia. Las cantidades son enteras. La fecha inicial y final se incluyen completas en hora de Guatemala. Si se elige parte de un mes, la barra muestra solamente los días seleccionados. Se permiten hasta 366 días por consulta. Las semanas comienzan el lunes y se recortan a los días del filtro, incluso al cambiar de año. En celular, Chart.js ajusta la gráfica y las tablas se desplazan dentro de su panel.

Las nuevas ventas conservan el nombre de la categoría al vender. Cambiar después la categoría del producto o su nombre no altera el registro histórico. Las ventas anteriores, y los descuentos antiguos recuperados sin ese dato, aparecen como **Sin categoría registrada**. Los nombres iguales se agrupan; los cambios de nombre se muestran por separado según lo guardado. Los productos se agrupan por id y muestran su último nombre y código vendidos dentro del período.

## Cómo se comunican los servicios

El navegador pide `GET /api/reportes/resumen?desde=2026-01-01&hasta=2026-12-31&pagina=1`, con JWT. Reportes usa cURL para pedir `GET /reportes/resumen` a Ventas, reenviando el token. Ventas comprueba la firma y consulta por REST a Auth para confirmar cuenta activa y rol actual. Solo entonces calcula las sumas en su propia base.

Reportes no lee ventas_db ni inventario_db y no necesita otro usuario SQL. Genera su PDF con Dompdf usando la misma consulta REST; no utiliza capturas del navegador ni envía datos a proveedores externos. No guarda copias de los reportes: cada consulta obtiene los datos confirmados. Las sumas y el listado se leen en una transacción para mantener una vista coherente. Las consultas no descuentan existencias, no confirman operaciones pendientes ni generan facturas nuevas.

Respuesta: `periodo`, `resumen`, `meses`, `categorias`, `productos`, `ventas` y `paginacion`. Los importes se entregan como decimales y la interfaz agrega Q. Los gráficos no reciben NIT ni dirección; el listado incluye únicamente el nombre del comprador.

### Rutas disponibles

Todas requieren JWT de un administrador activo y admiten `desde`, `hasta`, `pagina` y `agrupacion` (`dia`, `semana` o `mes`; valor inicial `mes`).

| Ruta GET | Resultado |
| --- | --- |
| `/api/reportes/resumen` | Tarjetas, series, rankings y listado |
| `/api/reportes/ventas` | Ventas paginadas, resumen y serie |
| `/api/reportes/top-productos` | Primeros 8 productos del período por unidades |
| `/api/reportes/pdf` | Archivo PDF descargable del período completo |

La exportación utiliza las fechas y la agrupación aplicadas. Si se editaron los filtros, primero hay que aplicarlos. El archivo no se limita a las 25 ventas visibles. Para evitar archivos excesivos, si el período tiene más de 5,000 ventas responde 422 y solicita reducir el rango; no omite ventas silenciosamente. Los rankings incluyen los primeros 8, como en pantalla.

Chart.js 4.6.1 y su licencia MIT están en `frontend/vendor/chartjs/`; las gráficas no dependen de un CDN en tiempo de ejecución. El PDF dibuja la misma serie con SVG y conserva los valores en tablas.

La integración de clima con OpenWeatherMap corresponde al siguiente bloque de APIs externas y todavía está pendiente.

Errores: 401 sin sesión válida, 403 para cajero, 405 para métodos distintos de GET, 422 para fechas o página inválidas y 503 si no se puede obtener el reporte. Un rango sin ventas es una respuesta 200 con resultados vacíos y totales cero.

## Archivos para explicar en la entrega

| Archivo | Responsabilidad |
| --- | --- |
| `services/reportes-service/public/index.php` | Ruta de Reportes y llamada REST a Ventas |
| `services/reportes-service/src/ReportClient.php` | Consulta autenticada a Ventas |
| `services/reportes-service/src/ReportPdf.php` | Generación del PDF y gráfica vectorial |
| `services/reportes-service/templates/report.php` | Diseño del documento |
| `services/ventas-service/src/SalesReports.php` | Fechas, sumas, rankings y paginación |
| `services/inventario-service/src/StockSales.php` | Entrega la categoría al confirmar el descuento |
| `services/ventas-service/src/Sales.php` | Guarda la categoría con el detalle |
| `db-master/migrations/005-categoria-venta.sql` | Campo histórico, sin borrar datos |
| `frontend/js/reports.js` | Filtros y presentación de datos |
| `frontend/index.html` y `frontend/styles.css` | Pantalla y estilos sobre AdminHMD |
| `tests/reportes-integration.php` | Verificación con ventas sintéticas temporales |
| `scripts/test-reportes.ps1` | Ejecuta las pruebas y retira sus cuentas |

Para esta actualización no hay cambios SQL. Ejecutar `docker compose up -d --build --no-deps ventas-service reportes-service`. En una instalación nueva, aplicar primero todas las migraciones con `./scripts/migrate.ps1`. Recargar Nginx con `docker compose exec -T gateway nginx -s reload` y la página con Ctrl + F5. El script de migraciones genera un respaldo antes de cambiar las tablas.

## Pruebas

Ejecutar `./scripts/test-reportes.ps1` y `./scripts/test-ventas.ps1` desde la raíz. Las pruebas cubren: permisos, datos vacíos, sumas, promedios, rankings, paginación, año bisiesto, límites de día/mes local, categorías históricas y facturas PDF. Las pruebas de Reportes requieren el año 2032 vacío y se detienen si ya hay ventas en esas fechas; no borran registros ajenos. Sus datos y cuentas se retiran al terminar.

También se comprueban agrupaciones diarias y semanales, semanas parciales y de cambio de año, las rutas solicitadas, descarga PDF y exportación de todas las páginas. Los PDF de prueba se guardan en `/tmp/reporte-*.pdf` dentro de Ventas para revisión visual. Los ejemplos temporales no quedan como ventas reales del sistema.


### Resultado de esta actualización

Pasaron 54 comprobaciones de Reportes y las 79 de Ventas. Se revisaron los PDF de las tres agrupaciones y de un período vacío. En navegador se probaron los selectores, la tabla de valores, el aviso de filtros sin aplicar y la descarga real del PDF; Chart.js se verificó también en pantalla de celular.

## Actualización de integridad y clima

Solo suman las ventas con estado confirmada. Una venta en anulación o anulada se conserva en el historial pero no en estadísticas ni exportaciones. El código del clima y su tarjeta están preparados; se requiere OPENWEATHER_API_KEY y validar la consulta real. Ver integraciones/clima.md.

# Caja e historial de ventas de FerreSync

## Uso

**Caja** se dedica a buscar productos y registrar una venta. Una vez registrada, aparece su factura. Al pulsar **Imprimir factura**, el sistema descarga el PDF y oculta automáticamente el comprobante y el aviso de la venta anterior. Si la caja está vacía, limpia la búsqueda y coloca el cursor para comenzar otra venta.

La aplicación confirma la descarga del archivo, no la impresión física: el usuario abre después el PDF si quiere imprimirlo. Si la descarga falla, el comprobante permanece para reintentar. El botón **Nueva venta** permite ocultarlo sin descargar. Nunca se borra un carrito nuevo ni una confirmación pendiente al cerrar una factura anterior.

**Historial de ventas** tiene filtros por fecha y vendedor, total del período, páginas de 25 ventas y un botón **Ver factura** por registro. También permite consultar por número de venta. La factura se puede volver a descargar tantas veces como sea necesario sin repetir la compra ni descontar stock.

- Administrador: puede consultar todos los vendedores, incluidos quienes ahora están inactivos.
- Cajero: solo ve sus propias ventas. El selector muestra **Mis ventas** y no permite elegir a otro vendedor.
- Las fechas se interpretan en Guatemala e incluyen el día inicial y final completos. El máximo por consulta es 366 días.
- Al entrar se muestra desde el primer día del mes hasta hoy.

## API

`GET /api/ventas/?desde=2026-10-01&hasta=2026-10-31&vendedor_id=9&pagina=1`

Requiere `Authorization: Bearer <token>`. Devuelve `periodo`, `vendedor_id`, `resumen`, `ventas` y `paginacion`. El resumen contiene cantidad de ventas e importe total del filtro. Se muestran únicamente ventas confirmadas y se ordenan de más reciente a más antigua.

`vendedor_id` es opcional para el administrador; omitirlo devuelve todos. Para un cajero, el servidor siempre limita por su identidad actual y rechaza con 403 un vendedor diferente. Las fechas y los identificadores inválidos devuelven 422. Sin sesión válida devuelve 401.

Ventas consulta únicamente ventas_db. Los nombres de vendedores se obtienen desde la API existente de Autenticación para el administrador; el cajero utiliza su propia identidad. No se agregan uniones entre bases ni migraciones SQL.

## Archivos

- `services/ventas-service/src/SalesHistory.php`: filtros, permisos, sumas y paginación.
- `services/ventas-service/public/index.php`: GET de historial y POST de venta.
- `frontend/js/sales-history.js`: pantalla de historial y selección de vendedores.
- `frontend/js/invoice-pdf.js`: descarga autenticada; descarta respuestas de una sesión o factura anterior.
- `frontend/app.js`: comparte la factura entre Caja e Historial y la cierra tras descargar.
- `tests/sales-history-integration.php` y `scripts/test-sales-history.ps1`: pruebas con datos temporales.

## Validación

Pasaron 29 comprobaciones del historial y las 79 de Ventas. Se revisaron permisos, filtros por vendedor y fechas, totales, paginación, datos vacíos y operaciones que no modifican ventas. En navegador se comprobó una venta de prueba, la descarga del PDF, la ocultación del comprobante y la recuperación de esa factura desde el historial.

Ejecutar `./scripts/test-sales-history.ps1` y `./scripts/test-ventas.ps1` desde la raíz. La primera prueba requiere que el año 2032 esté vacío y retira solamente las ventas y cuentas que crea.

Para actualizar: reconstruir Ventas con `docker compose up -d --build --no-deps ventas-service`, recargar Nginx con `docker compose exec -T gateway nginx -s reload` y recargar la página con Ctrl + F5.

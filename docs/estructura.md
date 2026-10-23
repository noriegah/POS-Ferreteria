# Cómo está organizado el proyecto

El código se agrupa por responsabilidad. Cada microservicio conserva su conexión y sus dependencias para poder construir su contenedor de forma independiente.

```text
POS Ferreteria/
├── docker-compose.yml          # Contenedores, redes, puertos y montajes
├── .env.example                # Nombres de configuración, sin valores reales
├── nginx/
│   └── default.conf            # Entrada única HTTP y rutas /api
├── frontend/
│   ├── index.html              # Pantallas y formularios
│   ├── styles.css              # Diseño propio sobre AdminHMD
│   ├── app.js                  # Sesión, módulos, inventario, caja y factura
│   ├── js/
│   │   ├── invoice-qr.js       # Carga del QR y reintentos
│   │   ├── invoice-pdf.js      # Descarga del PDF con JWT
│   │   ├── sales-history.js    # Consulta de ventas y filtros
│   │   └── reports.js          # Filtros, tarjetas y gráficos
│   ├── assets/logo.svg         # Identidad propia
│   └── vendor/
│       ├── adminhmd/           # Plantilla original y créditos
│       └── chartjs/            # Chart.js local y licencia MIT
├── services/
│   ├── auth-service/           # Usuarios, bcrypt y JWT
│   ├── inventario-service/     # Categorías, productos, fotos y stock
│   ├── ventas-service/         # Ventas, detalle, IVA, factura y QR
│   │   ├── public/index.php    # Rutas HTTP del servicio
│   │   ├── config/invoice.php # Datos del emisor
│   │   ├── templates/invoice.php # Diseño del PDF
│   │   └── src/
│   │       ├── Buyer.php       # Datos del comprador
│   │       ├── InvoicePdf.php  # Generación con Dompdf
│   │       ├── SalesHistory.php # Historial con permisos por vendedor
│   │       ├── SalesReports.php # Estadísticas de ventas
│   │       ├── Sales.php       # Datos de venta y desglose de factura
│   │       ├── InventoryClient.php # Comunicación con Inventario
│   │       └── integrations/
│   │           └── QrCodeClient.php # API externa de QR
│   └── reportes-service/       # Reportes por REST, solo administrador
│       ├── public/index.php    # Resumen, ventas, top-productos y PDF
│       ├── src/ReportClient.php # Consulta a Ventas con JWT
│       ├── src/ReportPdf.php   # Generación del reporte PDF
│       └── templates/report.php # Diseño del PDF
├── db-master/
│   ├── init.sql                # Crea las bases lógicas
│   └── migrations/             # Cambios SQL ordenados 001, 002, 003...
├── db-replica/                 # Configuración de la réplica de ventas
├── scripts/                    # start.ps1, migración, réplica y pruebas
├── tests/                      # Pruebas de los módulos
│   └── integrations/           # Pruebas de APIs externas
├── uploads/                    # Datos generados, no código fuente
│   ├── productos/              # Fotos de productos
│   └── facturas-qr/            # Caché privada de QR
└── docs/                       # Guías y explicación para la entrega
    └── integraciones/          # Guías de APIs externas
```

Los servicios de Auth, Inventario y Ventas tienen `public/` para recibir peticiones, `src/` para su lógica, Dockerfile y composer.json/lock para dependencias. Auth también incluye `bin/create-admin.php` como utilidad administrativa. Reportes recibe los filtros y solicita las estadísticas a Ventas por HTTP, sin consultar otras bases.

## Configuración y datos locales

`.env`, `secrets/`, `.backups/`, las dependencias PHP generadas y las imágenes de `uploads/` se excluyen de Git. `.env.example` y los `.gitkeep` permiten reconocer qué preparar al instalar. El volumen de MariaDB guarda las bases y no debe borrarse al actualizar código. Los datos locales y secretos necesitan respaldo para el VPS; el código por sí solo no los contiene.

## Orden sencillo para explicar la entrega

1. Mostrar Compose y Nginx: un punto de entrada para cuatro microservicios.
2. Auth: login, hash de contraseña, JWT y roles; cada servicio consulta su propia base.
3. Inventario: producto, categoría, código automático/foto y movimientos de stock.
4. Ventas: carrito, llamada REST a Inventario y registro sin repetir descuentos en un reintento.
5. Factura: precios históricos, IVA incluido del 12 %, cantidades enteras y quetzales.
6. QR: una integración externa aislada, su imagen en caché y el control de permisos.
7. Reportes: filtros por fechas, Chart.js por día/semana/mes, rankings, listado y PDF; consulta a Ventas por REST.
8. Abrir scripts y tests para mostrar cómo se valida cada bloque con datos temporales.

La réplica y el balanceo de Ventas están implementados. Clima tiene cliente e interfaz pendientes de clave y prueba real. Pagos Sandbox y despliegue en Hostinger continúan pendientes. La organización permite incorporarlos en su servicio correspondiente, sin mezclar todo en la raíz.

Los cambios administrativos viven en SaleChanges.php; Customers.php mantiene el cliente. StockReturns.php devuelve el descuento original en Inventario. Las migraciones 006–008 añaden estados, auditoría, clientes, pagos y cambios de acceso. Ver base-datos.md y roles-permisos.md para explicar las relaciones.

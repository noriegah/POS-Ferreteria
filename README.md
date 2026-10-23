# FerreSync

POS web distribuido de ferretería para el proyecto universitario de Desarrollo de Aplicaciones Web.

## Ejecutar por primera vez

Requisitos: Docker Desktop con Compose v2 y PowerShell 7.4 o posterior. Desde la raíz:

```powershell
pwsh -File scripts/start.ps1 -Tools
```

Este comando prepara las variables faltantes de .env con claves aleatorias, genera el par JWT si no existe, inicia MariaDB, aplica migraciones con respaldo, configura la réplica y construye los contenedores. No sobrescribe contraseñas ni claves ya configuradas. La preparación completa se probó en un proyecto Docker aislado con bases vacías el 23 de octubre de 2026.

En una instalación nueva, crear el primer administrador de forma interactiva:

```powershell
docker compose exec auth-service php /app/bin/create-admin.php
```

Abrir http://localhost:8080. En Usuarios, el administrador puede registrar el cajero de demostración. No se incluyen contraseñas públicas ni cuentas predeterminadas.

## Arranques posteriores

Después de preparar claves y bases:

```powershell
docker compose up --build -d
docker compose exec gateway nginx -s reload
```

Para detener conservando los datos: docker compose down. No usar down -v para actualizar el sistema.

## Arquitectura y datos

Nginx publica la interfaz y cuatro APIs. Autenticación, Inventario, Ventas y Reportes son procesos PHP separados. Nginx balancea la API de Ventas entre dos instancias del mismo servicio. La comunicación entre servicios utiliza REST y JWT.

Cada servicio conserva sus propios datos; no consulta tablas ajenas. Ventas escribe en la primaria y atiende las estadísticas desde su ventas_db en la réplica. Reportes solicita esos datos por REST. La réplica usa server_id=2 y la primaria server_id=1, con log_bin ROW y binlog_do_db=ventas_db. Sus configuraciones están en db-master/replication.cnf y db-replica/replication.cnf.

Clientes, ventas, detalles, pagos y eventos están relacionados en ventas_db. Los precios y datos del comprador se conservan como copia histórica de cada factura. Un comprobante anulado se mantiene, devuelve existencias una vez y no suma en reportes. Solo el administrador puede corregir comprador y anular. Las cantidades de caja son enteras y los importes se muestran con Q.

## Guías

- [Estructura de carpetas](docs/estructura.md)
- [Arquitectura](docs/arquitectura.md)
- [Base de datos](docs/base-datos.md)
- [Roles y permisos](docs/roles-permisos.md)
- [Replicación](docs/replicacion.md)
- [Balanceo](docs/balanceo.md)
- [Ver tablas con Adminer](docs/ver-base-datos.md)
- [Endpoints](docs/endpoints.md)
- [Documento del proyecto](docs/entrega/FerreSync-proyecto.pdf)
- [Guion de diez minutos](docs/entrega/guion-demo.md)
- [Estado de entrega y pendientes](docs/entrega/estado.md)

Adminer es opcional: docker compose --profile tools up -d adminer. Abre http://localhost:8085 y usa la cuenta DB_VIEWER_USER / DB_VIEWER_PASSWORD de .env. Solo permite consultar. El visor se publica únicamente en localhost.

## Validación

```powershell
./scripts/test-paypal.ps1
./scripts/test-sale-changes.ps1
./scripts/test-ventas.ps1
./scripts/test-reportes.ps1
./scripts/test-sales-history.ps1
./scripts/test-load-balancing.ps1
```

Se verificaron 42 comprobaciones del nuevo bloque administrativo, 79 de Ventas y 55 de Reportes. La prueba de balanceo comprueba las dos instancias y continuidad con una detenida. Las pruebas usan datos temporales y los retiran al finalizar. Hay pruebas adicionales de Inventario, JWT, QR y replicación en scripts/ y tests/.

## Límites y preparación externa

QR es la API externa ya integrada. El cliente de OpenWeatherMap y su interfaz están preparados, pero falta una clave propia y validar la consulta real. PayPal Sandbox está implementado con reserva, confirmación y reembolso; falta configurarlo y probarlo contra una cuenta propia. Ver docs/integraciones/pagos.md y scripts/configure-paypal.ps1. El documento no se considera entrega completa hasta cerrar estas funciones, publicar en GitHub y desplegar en el VPS Hostinger. El enunciado menciona hosting gratuito, por lo que se debe confirmar la aceptación del VPS.

.env, secrets, respaldos, datos subidos y dependencias PHP generadas se excluyen de Git. Bootstrap, AdminHMD y Chart.js se conservan en frontend/vendor con sus créditos para ejecutar la interfaz sin CDN. Antes del VPS se deben trasladar base e imágenes y configurar dominio, TLS y respaldo externo. No se publican los puertos SQL.

## Pago Sandbox preparado

Caja mantiene efectivo y añade PayPal Sandbox. Los importes de venta y factura siguen en Q; solo PayPal recibe USD de prueba con tasa académica visible y guardada en cada pago. Sin credenciales propias la opción aparece deshabilitada. El bloque tiene pruebas locales con respuestas controladas; la validación real está pendiente. Para clima se incluye scripts/configure-weather.ps1.

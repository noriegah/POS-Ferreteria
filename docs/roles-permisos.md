# Roles y privilegios

FerreSync tiene dos roles fijos: administrador y cajero. Los permisos de cada rol se definen en PHP; no existe un editor para inventar roles o cambiar permisos individuales.

| Operación | Administrador | Cajero |
| --- | --- | --- |
| Consultar productos, categorías y alertas | Sí | Sí |
| Crear, modificar o desactivar productos; crear categorías | Sí | No |
| Registrar ventas y consultar sus propias facturas | Sí | Sí |
| Consultar ventas y facturas de otros vendedores | Sí | No |
| Corregir comprador de una venta confirmada | Sí | No |
| Anular venta y comprobante | Sí | No |
| Consultar reportes y exportar PDF | Sí | No |
| Registrar usuarios y cambiar su rol o estado | Sí | No |

En Usuarios, el administrador elige el rol y estado de otras cuentas y pulsa Guardar acceso. No puede cambiarse a sí mismo: otro administrador debe hacerlo. Esto conserva al menos un administrador activo por este flujo. Los cambios se registran en auth_db.usuario_eventos. Autenticación vuelve a leer el estado y rol vigentes en cada verificación REST, por lo que un JWT antiguo no conserva permisos retirados. La pantalla del usuario afectado puede conservar botones hasta volver a iniciar sesión, pero el servidor rechaza las operaciones no permitidas.

## Corrección y anulación

Desde Historial de ventas, solo el administrador ve Editar comprador y Anular. La API exige el mismo permiso aunque alguien intente llamar directamente a la ruta.

- PATCH /api/ventas/{id}: corrige nombre, NIT y dirección; exige comprador y motivo. No acepta cambiar productos, importes, cantidades ni vendedor. Se sigue validando la identificación obligatoria desde Q 2,500.00.
- POST /api/ventas/{id}/anular: exige motivo. Conserva el número de comprobante, devuelve el stock mediante REST a Inventario y marca el cobro en efectivo como anulado.
- GET /api/ventas/{id}/eventos: devuelve el historial administrativo; solo administrador.
- PATCH /api/auth/users/{id}: recibe rol y activo (0 o 1); solo administrador.

Los estados son confirmada, anulando y anulada. La intención de anulación se guarda antes de llamar a Inventario. Si la comunicación falla, el historial ofrece reintentar; Inventario reconoce la referencia original y no repone unidades dos veces. Una venta anulada ya no se edita ni suma en reportes. Su PDF permanece disponible y muestra COMPROBANTE ANULADO. Es un comprobante académico sin certificación FEL.

Si los productos o las cantidades eran incorrectos, se anula la venta y se registra una nueva. venta_eventos guarda autor, motivo, fecha y datos anteriores/posteriores. No hay borrado de ventas desde la interfaz.

## Cuentas de MariaDB

Los usuarios de negocio no son cuentas SQL. auth_app, inventario_app y ventas_app tienen SELECT, INSERT, UPDATE y DELETE únicamente en su base. Los nombres reales se toman de .env. La cuenta de reportes tiene SELECT en ventas_db de la réplica y REPLICA MONITOR. ferresync_consulta permite explorar las bases con SELECT. Estas cuentas no se modifican desde Usuarios; se aprovisionan mediante los scripts administrativos.

Validación: scripts/test-sale-changes.ps1. Incluye 42 comprobaciones de permisos, clientes, cobro, auditoría, fallos REST, reintentos y cambios de acceso.

## Pagos externos

El cajero puede preparar y confirmar el pago de sus ventas; el administrador también puede consultar y completar pagos. Solo el administrador anula reservas o reembolsa pagos al anular. Una venta pendiente no se edita ni emite factura. La devolución de existencias ocurre después de confirmar el reembolso Sandbox, si hubo cobro.

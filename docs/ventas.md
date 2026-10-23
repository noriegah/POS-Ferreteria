# Caja y registro de ventas

Este bloque agrega la caja para administradores y cajeros. En **Caja / Ventas**, buscar por código o nombre, agregar al carrito, indicar unidades enteras y pulsar **Registrar venta**. Los precios y totales se muestran en quetzales, por ejemplo **Q 25.30**. Existencias y stock mínimo también usan unidades enteras. La API rechaza fracciones; no las redondea. Las columnas anteriores DECIMAL se conservan para no reconstruir tablas existentes.

## Preparación

Agregar a `.env` VENTAS_DB_USER (cuenta exclusiva), VENTAS_DB_PASSWORD y VENTAS_INTERNAL_KEY (dos valores aleatorios distintos, de al menos 32 caracteres). No subir `.env` a Git. Consultar `.env.example`.

1. Ejecutar `./scripts/migrate.ps1`: hace respaldo SQL y aplica `003-ventas.sql`, sin borrar datos.
2. Ejecutar `docker compose up -d --build --no-deps inventario-service ventas-service`.
3. Ejecutar `docker compose exec -T gateway nginx -t` y `docker compose exec -T gateway nginx -s reload`.
4. Recargar la página e iniciar sesión. `/api/ventas/ready` debe responder 200.

## Rutas

`POST /api/ventas/` requiere JWT y un objeto JSON:

```json
{
  "referencia": "c17d190a-b348-4d17-8e29-1c21231f422a",
  "productos": [{"producto_id": "1", "cantidad": 2}]
}
```

La referencia es un UUID v4 nuevo por compra. Se admiten hasta 50 productos distintos, con cantidades de 1 a 999999. La respuesta 201 contiene `venta` con id, referencia, usuario, fecha, moneda GTQ, total y productos con precio/subtotal. No se aceptan precios del navegador como fuente del cálculo. `GET /api/ventas/{id}` consulta una venta: cajero solo las propias; administrador cualquiera. No se implementan listado, anulación ni devolución en este bloque.

Ventas verifica firma JWT y estado actual del usuario mediante `/verify`. Guarda `ventas` y `detalle_ventas` en **ventas_db**. Por cURL pide a Inventario `POST /ventas/descontar`, enviando JWT y clave interna. Esa ruta no admite al navegador solo con JWT: necesita además la clave que queda dentro de los contenedores. Inventario trabaja exclusivamente con **inventario_db** y devuelve los precios vigentes.

Dentro de Inventario se bloquean las filas de productos y se descuentan todos en una sola transacción con sus movimientos de stock. Si cualquier producto está inactivo, falta o no tiene unidades suficientes, se revierte todo. El dinero se calcula en centavos enteros.

## Reintentos

La referencia y el hash del usuario/carrito impiden repetir el descuento. Inventario conserva la respuesta original en `descuentos_venta`; una repetición devuelve los mismos precios, incluso si cambiaron después. Ventas también tiene referencia única. Reutilizarla con otro usuario o cantidades devuelve 409.

Las bases tienen transacciones independientes. Si Inventario confirmó pero se perdió la respuesta o Ventas falló al guardar, **se debe reintentar la misma referencia** para terminar el registro. No hay una transacción distribuida ni recuperación automática en segundo plano. La interfaz conserva la operación pendiente en `sessionStorage` y bloquea cambios hasta confirmarla. Al volver a iniciar sesión con la misma cuenta, en la misma pestaña, se recupera el reintento. No guarda JWT ni contraseñas allí. Cerrar la pestaña/eliminar ese almacenamiento puede perder la referencia; los descuentos quedan auditados en Inventario y requieren revisión administrativa. Un error de validación confirmado permite corregir el carrito.

## Validación

`./scripts/test-ventas.ps1` crea cuentas, categoría y productos temporales; comprueba venta, precios reales, cantidades, permisos, falta de stock, reintentos, recuperación tras respuesta perdida, peticiones duplicadas y los cuatro `/health`. Limpia exclusivamente sus datos. `./scripts/test-inventario.ps1` verifica que CRUD, filtros, alertas e imágenes sigan funcionando, incluidas cantidades fraccionadas rechazadas.

Este registro descuenta existencias, pero todavía no representa un cobro con tarjeta. La factura con IVA incluido del 12 % ya se incorpora; ver [factura.md](factura.md). El QR de referencia también está implementado; ver [integraciones/qr.md](integraciones/qr.md). El pago sandbox queda para otro bloque. La infraestructura de Nginx, redes y volumen MariaDB se conserva; Ventas recibe una cuenta SQL propia y solo la clave pública JWT.

## Resultado de esta entrega

Pasaron las 35 comprobaciones de Ventas, la regresión de Inventario y la batería JWT. PHP, JavaScript, Nginx y Compose pasaron sus comprobaciones de sintaxis/configuración. Se confirmó que Ventas no puede consultar auth_db ni inventario_db. En navegador se registraron 2 unidades a Q 12.50 (total Q 25.00), con stock de 10 a 8; también se revisó la tabla en vista móvil. Los datos de prueba se eliminaron y el producto existente CO-001 conservó precio Q 429.00, 15 unidades y mínimo 3.

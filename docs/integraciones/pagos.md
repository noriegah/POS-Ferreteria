# Pagos con PayPal Sandbox

El bloque está implementado y tiene pruebas locales con respuestas controladas. Todavía no está validado contra una cuenta propia de PayPal. Se conservan administrador y cajero; no se agrega un editor de roles o permisos.

## Preparar las cuentas

1. Entrar a https://developer.paypal.com e iniciar sesión o crear la cuenta personalmente.
2. En el Dashboard, seleccionar Apps & Credentials y el entorno Sandbox. Crear una aplicación REST para FerreSync si todavía no existe.
3. Obtener el Client ID y Secret de esa aplicación Sandbox. No pegarlos en el chat ni subirlos a GitHub.
4. En Sandbox Accounts, identificar una cuenta Business (vendedor) y otra Personal (comprador). La compra se autoriza con la cuenta Personal de prueba; el vendedor debe poder recibir USD.
5. Desde Windows PowerShell 5.1 o PowerShell 7, en la raíz de FerreSync, ejecutar:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/configure-paypal.ps1
```

El script pide Client ID y Secret (oculto), verifica OAuth contra el servidor Sandbox y solo entonces escribe .env. Recrea las dos instancias de Ventas y recarga Nginx. No crea órdenes ni cobra al validar credenciales. Después hay que completar una compra real de Sandbox para cerrar la validación externa.

Guía oficial de cuentas: https://developer.paypal.com/sandbox-testing/accounts

## Moneda de demostración

PayPal no admite GTQ como moneda de esta API. Ventas, factura e IVA permanecen en Q. El proveedor recibe USD de prueba con PAYPAL_USD_RATE=7.80 por defecto: una tasa académica configurable, no una cotización bancaria. La tasa y el importe en USD quedan congelados en el registro del pago, aunque después cambie .env. El usuario ve los dos importes antes de autorizar.

Variables en .env.example: PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_USD_RATE y PAYPAL_RETURN_URL. En el VPS, la URL de retorno debe apuntar al dominio HTTPS y /pago-retorno.html. No se usan endpoints de producción y nunca se guardan números de tarjeta o CVV.

Monedas admitidas: https://developer.paypal.com/api/codes/currency

## Flujo y consistencia

1. Caja envía referencia única, productos, comprador y metodo_pago=tarjeta. El servidor comprueba la configuración antes de apartar existencias.
2. Inventario valida stock, comprador e importe mínimo dentro de su transacción y guarda el descuento una sola vez. Ventas conserva los precios confirmados y marca pendiente_pago. Todavía no hay factura ni suma en reportes.
3. Ventas crea una orden Sandbox con el importe almacenado. El navegador abre el enlace de PayPal en otra pestaña. Las credenciales permanecen en el servidor y no se envían datos del comprador a PayPal desde este cliente.
4. El comprador autoriza con su cuenta Personal Sandbox y vuelve a FerreSync. Verificar pago consulta la orden y captura el importe desde el servidor. Se comparan referencia, orden, moneda e importe; el retorno del navegador no prueba el cobro.
5. Al confirmarse COMPLETED se marca el pago aprobado y la venta confirmada. Entonces se habilitan factura y reportes.

Orden, captura y reembolso utilizan referencias de idempotencia. Si se pierde una respuesta, se consulta el estado remoto antes de volver a cobrar. Los bloqueos SQL serializan la confirmación y la anulación entre las dos instancias de Ventas.

## Anular y reembolsar

Solo el administrador anula ventas, tanto pendientes como pagadas. Primero se guarda anulando. Si hubo captura, se solicita un reembolso completo de Sandbox; si está pendiente, se guarda su identificador y se permite reintentar. El stock vuelve después de confirmar el reembolso. Una reserva sin cobro se libera sin reembolso. La anulación conserva el comprobante y la auditoría.

Cerrar la ventana de pago o cancelar en PayPal no anula automáticamente la reserva. Las existencias permanecen apartadas hasta completar el pago o hasta que un administrador anule desde Historial. No hay vencimiento automático ni trabajo programado de reservas en este bloque universitario.

## Rutas

- GET /api/ventas/pagos/config: disponibilidad y tasa, sin secretos; requiere usuario activo.
- POST /api/ventas/{id}/pago/orden: prepara o recupera la orden; propietario o administrador.
- POST /api/ventas/{id}/pago/confirmar: confirma el cobro; propietario o administrador.
- POST /api/ventas/{id}/anular: anula y, si corresponde, reembolsa; solo administrador.

## Pruebas

scripts/test-paypal.ps1 comprueba el contrato y el flujo local usando Inventario real y un transporte PHP controlado exclusivamente en pruebas. No existe una ruta ni opción pública para aprobar un pago ficticio. La prueba incluye reserva, conversión, permisos, reintentos, cobro, facturas, reportes y reembolsos. Se debe repetir una compra y anulación con las credenciales propias antes de declarar la integración externa completa.

Referencias: https://developer.paypal.com/api/orders/v2 y https://developer.paypal.com/api/payments/v2


Validación local final: 47 comprobaciones de PayPal, 79 de Ventas, 42 del bloque administrativo, 55 de Reportes y 29 del Historial. Caja también se verificó en el navegador con una venta temporal en efectivo; todos los datos de prueba se retiraron.

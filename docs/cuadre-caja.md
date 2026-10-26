# Cuadre de caja

Cada cajero (incluido el administrador que cobra) abre un turno con el fondo inicial. Solo puede tener uno abierto. Para cobrar efectivo necesita un turno abierto. PayPal no necesita turno y no suma efectivo.

Esperado = fondo + ingresos por ventas en efectivo - devoluciones en efectivo.

Al cerrar se registra el dinero contado. Diferencia = contado - esperado: negativa es faltante, positiva es sobrante. Si hay diferencia, se exige observación. El cierre es definitivo; reintentar el mismo cierre no lo duplica. Los turnos anteriores y sus ventas no se migran artificialmente: las ventas previas no entran al nuevo cuadre.

El cajero abre/cierra sus turnos y consulta sus movimientos. El administrador consulta todos, pero no cierra el turno de otro cajero. El historial muestra los últimos 100 turnos y permite ver cada movimiento con su factura. Fechas: Guatemala.

## Anulaciones
Solo el administrador puede anular. Para devolver efectivo debe tener su propio turno abierto. La salida se registra en ese turno, incluso si la venta corresponde a un turno cerrado u otro cajero. El administrador entrega físicamente el dinero al comprador. El turno original conserva su ingreso y cierre. Una anulación repetida no duplica la devolución. Si hay una anulación en proceso, no puede cerrarse el turno que devolverá el efectivo hasta terminarla. PayPal conserva su flujo de reembolso externo.

Este módulo no administra gastos, retiros, transferencias entre cajas ni denominaciones de billetes.

## Estructura
- `services/ventas-service/src/CashRegister.php`: apertura, movimientos, consulta y cierre.
- `db-master/migrations/011-cuadre-caja.sql`: tablas `cajas`, `caja_movimientos` y vínculo para devolver efectivo.
- `frontend/js/cash-register.js`: pantalla, historial y detalle.
- `tests/cash-register-integration.php` y `scripts/test-cash-register.ps1`: validación temporal por REST.

## API (JWT obligatorio)
- `GET /api/ventas/cajas`: turno propio abierto e historial (todos para administrador).
- `POST /api/ventas/cajas`: abrir con `fondo` en Q (texto decimal).
- `GET /api/ventas/cajas/{id}`: resumen y movimientos, solo propietario o administrador.
- `POST /api/ventas/cajas/{id}/cerrar`: cerrar turno propio con `contado` y `observaciones`.

Los bloqueos SQL coordinan las dos instancias de Ventas. Los importes se guardan como DECIMAL, los cálculos de validación usan centavos enteros y los cierres guardan sus totales. Cada factura tiene como máximo un ingreso y una devolución. No se accede directamente a tablas de Auth o Inventario.
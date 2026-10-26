# Estado de la entrega

Revisión del 23 de octubre de 2026. El sistema local no se considera todavía la entrega final publicada.

| Punto | Estado y evidencia |
| --- | --- |
| Autenticación, Inventario, Caja, factura e historial | Implementados; Ventas 79 e Historial 29 comprobaciones. |
| Cuadre de caja | Apertura, movimientos y cierre definitivo; 36 comprobaciones REST y prueba visual. Solo efectivo. |
| Reportes con gráficas y PDF | Implementados; 55 comprobaciones. Excluyen ventas anuladas. |
| Réplica MariaDB y lecturas de reportes | Implementadas; prueba integrada del bloque administrativo incluye la replicación. |
| Clientes, pagos y relaciones | Implementados para el flujo en efectivo; FK a cliente, detalle, cobro y eventos verificadas. |
| Editar comprador y anular solo con admin | Implementado; 42 comprobaciones del bloque con permisos, stock y auditoría. |
| Cambiar rol y estado de usuarios | Implementado para otras cuentas; permisos de cada rol fijos en PHP. |
| Balanceo y continuidad | Dos instancias de Ventas; probado con una detenida y restaurada. No hay failover de MariaDB. |
| Primera instalación | start.ps1 probado con bases vacías y claves nuevas en proyecto Docker aislado. Arranque posterior con Compose. |
| SQL de estructura y demostración | db-master/schema.sql y demo.sql, además de migraciones ordenadas. No aplicar schema.sql sobre datos existentes; usar migrate.ps1. |
| Documentación | README actualizado, endpoints, permisos, modelo de datos, PDF de siete páginas y guion de diez minutos. Falta completar estudiante/carné y actualizar evidencias externas. |
| GitHub | Git local inicializado e ignores revisados. No existe remoto ni publicación; se requiere acceso a la cuenta y elegir el repositorio. |
| Clima | Cliente e interfaz preparados; tres comprobaciones del contrato. Falta una clave propia y prueba real con OpenWeatherMap. |
| Pago con tarjeta Sandbox | PayPal Sandbox implementado: reserva, orden, captura, recuperación y reembolso. Pruebas locales con respuestas controladas; usuario confirmó configuración, compra y anulación reales de Sandbox el 23/10/2026. |
| VPS Hostinger | Falta contratar/preparar acceso, desplegar datos e imágenes y configurar URL pública/TLS. |
| Aceptación del hosting | El enunciado indica hosting gratuito; confirmar con el catedrático la aceptación del VPS elegido. |

## Siguiente bloque externo

El usuario indicó que todavía no tiene accesos de pago Sandbox, OpenWeatherMap ni VPS. Se deben obtener sin enviar contraseñas o claves en el chat. Las claves se configuran localmente en .env. Después de disponer de cada acceso se valida la integración correspondiente y se actualiza este estado.

El PDF del proyecto es un documento de avance listo para completar con los datos del estudiante y las evidencias finales. La compra y anulación Sandbox fueron confirmadas por el usuario después de generar el PDF; falta actualizar esa evidencia en el documento. Todavía no hay URL pública. El guion de demo debe actualizarse cuando se prueben las funciones externas.

## Preparación de accesos

Para PayPal: seguir integraciones/pagos.md y ejecutar scripts/configure-paypal.ps1 después de crear la app Sandbox. Para clima: scripts/configure-weather.ps1 valida la clave antes de guardarla. Las comprobaciones de OAuth y clima no sustituyen la prueba de una compra completa.

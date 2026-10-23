# Estado de la entrega

Revisión del 23 de octubre de 2026. El sistema local no se considera todavía la entrega final publicada.

| Punto | Estado y evidencia |
| --- | --- |
| Autenticación, Inventario, Caja, factura e historial | Implementados; Ventas 79 e Historial 29 comprobaciones. |
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
| Pago con tarjeta Sandbox | Falta implementar y validar el flujo con Stripe o PayPal. La tabla pagos actual registra efectivo; no se simula una aprobación de tarjeta. |
| VPS Hostinger | Falta contratar/preparar acceso, desplegar datos e imágenes y configurar URL pública/TLS. |
| Aceptación del hosting | El enunciado indica hosting gratuito; confirmar con el catedrático la aceptación del VPS elegido. |

## Siguiente bloque externo

El usuario indicó que todavía no tiene accesos de pago Sandbox, OpenWeatherMap ni VPS. Se deben obtener sin enviar contraseñas o claves en el chat. Las claves se configuran localmente en .env. Después de disponer de cada acceso se valida la integración correspondiente y se actualiza este estado.

El PDF del proyecto es un documento de avance listo para completar con los datos del estudiante y las evidencias finales. No afirma que exista pago Sandbox ni URL pública. El guion de demo debe actualizarse cuando se prueben las funciones externas.

# Balanceo y continuidad de Ventas

Nginx reparte por turnos las rutas /api/ventas/ entre ventas-service y ventas-secondary. Ambas instancias usan la misma base y el mismo directorio de QR, porque ejecutan el mismo microservicio. Los JWT no dependen de una sesión local en un contenedor.

El grupo upstream ventas_pool comparte el estado de fallos. Las consultas fallidas por conexión o timeout pueden pasar a la otra instancia. No se activa la repetición de peticiones no idempotentes ya enviadas: la referencia de compra sigue siendo la protección frente a reintentos del cliente. Reportes llama a Ventas a través del gateway para aprovechar el grupo.

scripts/test-load-balancing.ps1 comprueba que aparecen dos instancias, detiene temporalmente la secundaria, verifica seis respuestas correctas y la vuelve a iniciar en finally. Esta prueba se realizó correctamente el 23 de octubre de 2026.

Es tolerancia a la caída de una instancia de Ventas. Nginx, Autenticación, Inventario y la base primaria siguen siendo dependencias únicas; no existe failover automático de MariaDB. Después de recrear contenedores, recargar Nginx permite actualizar las direcciones internas.

Referencia oficial: https://nginx.org/en/docs/http/load_balancing.html

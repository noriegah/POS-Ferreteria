# Replicación de MariaDB

La primaria recibe las escrituras. La réplica mantiene una copia de `ventas_db` para consultar reportes. Autenticación e Inventario siguen usando sus bases en la primaria; el historial y las facturas también consultan la primaria.

Reportes llama a Ventas por REST. Ventas usa una cuenta SELECT en la réplica para responder las consultas estadísticas. Así ningún servicio lee tablas de otro servicio directamente.

## Archivos

- `db-master/replication.cnf`: servidor 1, binlog ROW y filtro de ventas.
- `db-replica/replication.cnf`: servidor 2, modo de solo lectura y relay log.
- `scripts/prepare-replication-env.ps1`: agrega las credenciales faltantes a `.env` sin mostrarlas.
- `scripts/setup-replication.ps1`: respalda la primaria, prepara cuentas e importa una copia consistente con sus coordenadas de binlog.
- `services/ventas-service/src/Database.php`: distingue conexión normal y conexión de reportes.

## Preparación inicial

Desde PowerShell 7, en la raíz del proyecto, con `.env`, las claves JWT y las migraciones de negocio ya preparadas:

```powershell
./scripts/prepare-replication-env.ps1
./scripts/setup-replication.ps1
docker compose up -d --build
```

El respaldo queda en `.backups/`. El script no borra el volumen primario. Si encuentra datos en una réplica todavía sin configurar, se detiene para evitar sobrescribirlos. Después de la preparación, los reinicios normales conservan la configuración en `db_replica_data`.

Nunca usar `docker compose down -v` para aplicar estos cambios. La réplica no sustituye el respaldo: una eliminación también se replica.

## Comprobación

```powershell
./scripts/test-replication.ps1
./scripts/test-reportes.ps1
./scripts/test-ventas.ps1
```

El 23 de octubre de 2026 pasaron 16 comprobaciones de replicación, 55 de Reportes y 79 de Ventas. La prueba registra una venta temporal mediante REST, espera su llegada a la réplica, consulta el reporte y limpia sus datos. También comprueba que la cuenta de reportes no pueda escribir ni consultar cuentas técnicas.

Los dos hilos de replicación deben estar en Yes. Los reportes rechazan la consulta si falta conexión, algún hilo se detiene o el atraso supera 30 segundos. No existe promoción automática de la réplica. La replicación es asíncrona, por lo que una venta recién registrada puede tardar un momento en aparecer en reportes.

## Visor

Consultar [ver-base-datos.md](ver-base-datos.md). Adminer es una herramienta opcional de consulta local. En el VPS se conserva el acceso por localhost; para acceder desde otra computadora se debe usar un túnel SSH.

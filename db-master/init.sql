-- Crea las cuatro bases al inicializar un volumen nuevo; no se repite sobre uno existente.
-- Fase 1: bases lógicas independientes; las tablas y credenciales por servicio se implementarán en Fase 2.
CREATE DATABASE IF NOT EXISTS auth_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS inventario_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ventas_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS reportes_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

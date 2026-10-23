-- Historial de cambios de rol y estado. Los identificadores son del mismo servicio.
CREATE TABLE IF NOT EXISTS auth_db.usuario_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    administrador_id BIGINT UNSIGNED NOT NULL,
    rol_anterior ENUM('administrador','cajero') NOT NULL,
    rol_nuevo ENUM('administrador','cajero') NOT NULL,
    activo_anterior TINYINT(1) NOT NULL,
    activo_nuevo TINYINT(1) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES auth_db.usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (administrador_id) REFERENCES auth_db.usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

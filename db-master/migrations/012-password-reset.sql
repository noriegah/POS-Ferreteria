-- Los cambios de contraseña invalidan los JWT anteriores de la cuenta.
ALTER TABLE auth_db.usuarios ADD COLUMN IF NOT EXISTS session_version BIGINT UNSIGNED NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS auth_db.usuario_password_eventos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id BIGINT UNSIGNED NOT NULL,
 administrador_id BIGINT UNSIGNED NOT NULL,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_password_usuario FOREIGN KEY(usuario_id) REFERENCES auth_db.usuarios(id),
 CONSTRAINT fk_password_administrador FOREIGN KEY(administrador_id) REFERENCES auth_db.usuarios(id)
) ENGINE=InnoDB;

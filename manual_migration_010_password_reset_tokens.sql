CREATE TABLE IF NOT EXISTS password_reset_tokens (
    reset_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reset_id),
    UNIQUE KEY password_reset_user_unique (user_id),
    UNIQUE KEY password_reset_token_unique (token_hash),
    KEY password_reset_expiry_idx (expires_at),
    CONSTRAINT password_reset_user_fk
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

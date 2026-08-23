CREATE TABLE IF NOT EXISTS bets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    question VARCHAR(255) NOT NULL,
    description TEXT NULL,
    closes_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    winning_option_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT bets_owner_fk FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT bets_status_check CHECK (status IN ('open', 'closed', 'settled', 'cancelled')),
    INDEX bets_owner_status_index (owner_user_id, status),
    INDEX bets_status_closes_at_index (status, closes_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bet_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bet_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT bet_options_bet_fk FOREIGN KEY (bet_id) REFERENCES bets (id) ON DELETE CASCADE,
    CONSTRAINT bet_options_position_unique UNIQUE (bet_id, position),
    CONSTRAINT bet_options_label_unique UNIQUE (bet_id, label),
    INDEX bet_options_bet_index (bet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bets
    ADD CONSTRAINT bets_winning_option_fk
    FOREIGN KEY (winning_option_id) REFERENCES bet_options (id) ON DELETE RESTRICT;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('bets.view', 'View own bets'),
    ('bets.view_all', 'View bets owned by other users'),
    ('bets.create', 'Create bets'),
    ('bets.edit', 'Edit bets'),
    ('bets.delete', 'Cancel eligible bets'),
    ('bets.close', 'Close bets'),
    ('bets.settle', 'Settle bets');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'admin'
  AND permissions.name LIKE 'bets.%';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'bookmaker'
  AND permissions.name IN (
      'bets.view', 'bets.create', 'bets.edit', 'bets.delete', 'bets.close', 'bets.settle'
  );
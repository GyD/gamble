ALTER TABLE bet_options
    ADD CONSTRAINT bet_options_bet_id_id_unique UNIQUE (bet_id, id);

CREATE TABLE IF NOT EXISTS stakes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bet_id BIGINT UNSIGNED NOT NULL,
    bet_option_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT stakes_bet_fk FOREIGN KEY (bet_id) REFERENCES bets (id) ON DELETE CASCADE,
    CONSTRAINT stakes_bet_option_fk FOREIGN KEY (bet_id, bet_option_id) REFERENCES bet_options (bet_id, id) ON DELETE CASCADE,
    CONSTRAINT stakes_contact_fk FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE RESTRICT,
    CONSTRAINT stakes_amount_check CHECK (amount_cents > 0),
    INDEX stakes_bet_index (bet_id),
    INDEX stakes_contact_index (contact_id),
    INDEX stakes_bet_option_index (bet_option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (name, description) VALUES
    ('stakes.view', 'View stakes'),
    ('stakes.create', 'Create stakes'),
    ('stakes.edit', 'Edit stakes'),
    ('stakes.delete', 'Delete stakes');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'admin'
  AND permissions.name LIKE 'stakes.%';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'bookmaker'
  AND permissions.name IN ('stakes.view', 'stakes.create', 'stakes.edit', 'stakes.delete');
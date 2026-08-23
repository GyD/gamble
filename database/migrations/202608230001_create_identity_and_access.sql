CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    twitch_id VARCHAR(32) NOT NULL,
    twitch_login VARCHAR(64) NOT NULL,
    twitch_display_name VARCHAR(255) NOT NULL,
    twitch_avatar_url VARCHAR(2048) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    CONSTRAINT users_twitch_id_unique UNIQUE (twitch_id),
    CONSTRAINT users_status_check CHECK (status IN ('pending', 'active', 'suspended')),
    INDEX users_status_index (status),
    INDEX users_last_login_at_index (last_login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    label VARCHAR(128) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT roles_name_unique UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT permissions_name_unique UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT user_roles_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT user_roles_role_fk FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT role_permissions_role_fk FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT role_permissions_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    effect VARCHAR(10) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT user_permissions_effect_check CHECK (effect IN ('allow', 'deny')),
    CONSTRAINT user_permissions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT user_permissions_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    state_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT oauth_states_hash_unique UNIQUE (state_hash),
    INDEX oauth_states_expires_at_index (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    before_data JSON NULL,
    after_data JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT audit_logs_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    INDEX audit_logs_actor_index (actor_user_id),
    INDEX audit_logs_entity_index (entity_type, entity_id),
    INDEX audit_logs_created_at_index (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (name, label) VALUES
    ('admin', 'Administrateur'),
    ('bookmaker', 'Organisateur');

INSERT IGNORE INTO permissions (name, description) VALUES
    ('bets.view', 'View own bets'),
    ('bets.view_all', 'View bets owned by other users'),
    ('bets.create', 'Create bets'),
    ('bets.edit', 'Edit bets'),
    ('bets.delete', 'Delete eligible bets'),
    ('bets.close', 'Close bets'),
    ('bets.settle', 'Settle bets'),
    ('contacts.view', 'View contacts'),
    ('contacts.create', 'Create contacts'),
    ('contacts.edit', 'Edit contacts'),
    ('contacts.delete', 'Archive or delete contacts'),
    ('groups.view', 'View groups'),
    ('groups.create', 'Create groups'),
    ('groups.edit', 'Edit groups'),
    ('groups.delete', 'Delete groups'),
    ('stakes.view', 'View stakes'),
    ('stakes.create', 'Create stakes'),
    ('stakes.edit', 'Edit stakes'),
    ('stakes.delete', 'Delete eligible stakes'),
    ('payments.view', 'View payments'),
    ('payments.manage', 'Create, edit, and delete payments'),
    ('statistics.view', 'View statistics'),
    ('users.view', 'View application users'),
    ('users.manage', 'Activate, suspend, and reactivate users'),
    ('permissions.manage', 'Manage roles and permissions'),
    ('settings.manage', 'Manage application settings');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'bookmaker'
  AND permissions.name IN (
      'bets.view', 'bets.create', 'bets.edit', 'bets.delete', 'bets.close', 'bets.settle',
      'contacts.view', 'contacts.create', 'contacts.edit',
      'groups.view', 'groups.create', 'groups.edit',
      'stakes.view', 'stakes.create', 'stakes.edit', 'stakes.delete',
      'payments.view', 'payments.manage', 'statistics.view'
  );
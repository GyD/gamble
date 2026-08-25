INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles
CROSS JOIN permissions
WHERE roles.name = 'bookmaker'
  AND permissions.name IN ('bets.view_all', 'contacts.delete', 'groups.delete');

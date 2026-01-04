-- Grant necessary privileges for aMember setup
-- This allows the user to set session variables required during installation
-- Note: This runs on first initialization. For existing databases, run the command manually.

-- Grant privileges to the amember user (adjust if using different MYSQL_USER)
GRANT SYSTEM_VARIABLES_ADMIN, SESSION_VARIABLES_ADMIN ON *.* TO 'amember'@'%';
FLUSH PRIVILEGES;


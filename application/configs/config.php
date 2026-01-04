<?php
/**
*  aMember Pro Config File
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    FileName $RCSfile$
*    Release: 6.3.5 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forums
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*/

const AM_USE_NEW_CSS = 1;

// Parse database configuration from environment variables
// Support both Railway's MYSQL_URL format and individual variables
$dbHost = getenv('MYSQL_HOST') ?: 'mysql';
$dbPort = getenv('MYSQL_PORT') ?: '3306';
$dbName = getenv('MYSQL_DATABASE') ?: 'amember';
$dbUser = getenv('MYSQL_USER') ?: 'amember';
$dbPass = getenv('MYSQL_PASSWORD') ?: 'amember';

// If MYSQL_URL is provided (Railway format: mysql://user:pass@host:port/dbname), parse it
if (($mysqlUrl = getenv('MYSQL_URL')) || ($mysqlUrl = getenv('DATABASE_URL'))) {
    fwrite(STDERR, "[aMember Config] Found MYSQL_URL/DATABASE_URL environment variable\n");
    error_log("[aMember Config] Found MYSQL_URL/DATABASE_URL environment variable", 4); // 4 = send to stderr
    $parsed = parse_url($mysqlUrl);
    if ($parsed) {
        $dbHost = isset($parsed['host']) ? $parsed['host'] : $dbHost;
        $dbPort = isset($parsed['port']) ? $parsed['port'] : $dbPort;
        $dbName = isset($parsed['path']) ? ltrim($parsed['path'], '/') : $dbName;
        $dbUser = isset($parsed['user']) ? $parsed['user'] : $dbUser;
        $dbPass = isset($parsed['pass']) ? $parsed['pass'] : $dbPass;
        fwrite(STDERR, "[aMember Config] Parsed DB config - Host: $dbHost, Port: $dbPort, DB: $dbName, User: $dbUser\n");
        error_log("[aMember Config] Parsed DB config - Host: $dbHost, Port: $dbPort, DB: $dbName, User: $dbUser", 4);
    } else {
        fwrite(STDERR, "[aMember Config] Warning: Failed to parse MYSQL_URL\n");
        error_log("[aMember Config] Warning: Failed to parse MYSQL_URL", 4);
    }
} else {
    fwrite(STDERR, "[aMember Config] Using individual environment variables or defaults\n");
    fwrite(STDERR, "[aMember Config] DB config - Host: $dbHost, Port: $dbPort, DB: $dbName, User: $dbUser\n");
    error_log("[aMember Config] Using individual environment variables or defaults - Host: $dbHost, Port: $dbPort, DB: $dbName, User: $dbUser", 4);
}

return [
    'db' => [
        'mysql' => [
            'db'    => $dbName,
            'user'  => $dbUser,
            'pass'  => $dbPass,
            'host'  => $dbHost,
            'prefix' => 'am_',
            'port'  => $dbPort ?: '',
            'pdo_options' => [],
        ],
    ],
    'cache' => [
        'type' => '',
        'options' => []
    ]
];
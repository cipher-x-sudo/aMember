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

// Helper function to get environment variable from multiple sources
// (getenv, $_ENV, $_SERVER - different PHP/Apache configs use different methods)
function _getEnvVar($name, $default = null) {
    // Try getenv first
    $value = getenv($name);
    if ($value !== false && $value !== '') return $value;
    
    // Try $_ENV
    if (isset($_ENV[$name]) && $_ENV[$name] !== '') return $_ENV[$name];
    
    // Try $_SERVER (Apache sometimes puts env vars here)
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') return $_SERVER[$name];
    
    return $default;
}

// Parse database configuration from environment variables
// Support both Railway's MYSQL_URL format and individual variables
$dbHost = _getEnvVar('MYSQL_HOST', 'mysql');
$dbPort = _getEnvVar('MYSQL_PORT', '3306');
$dbName = _getEnvVar('MYSQL_DATABASE', 'amember');
$dbUser = _getEnvVar('MYSQL_USER', 'amember');
$dbPass = _getEnvVar('MYSQL_PASSWORD', 'amember');

// If MYSQL_URL is provided (Railway format: mysql://user:pass@host:port/dbname), parse it
$mysqlUrl = _getEnvVar('MYSQL_URL') ?: _getEnvVar('DATABASE_URL');
if ($mysqlUrl) {
    $parsed = parse_url($mysqlUrl);
    if ($parsed) {
        $dbHost = isset($parsed['host']) ? $parsed['host'] : $dbHost;
        $dbPort = isset($parsed['port']) ? $parsed['port'] : $dbPort;
        $dbName = isset($parsed['path']) ? ltrim($parsed['path'], '/') : $dbName;
        $dbUser = isset($parsed['user']) ? $parsed['user'] : $dbUser;
        $dbPass = isset($parsed['pass']) ? $parsed['pass'] : $dbPass;
    }
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
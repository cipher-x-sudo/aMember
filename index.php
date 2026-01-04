<?php

// Trust reverse proxy headers (Railway, etc.)
// This tells PHP that the connection is HTTPS when behind a proxy
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_SCHEME'] = 'https';
}

// Hardcode session cookie domain for Railway
ini_set('session.cookie_domain', 'amember-production.up.railway.app');

if (!defined('APPLICATION_CONFIG'))
    define('APPLICATION_CONFIG', dirname(__FILE__) . '/application/configs/config.php');

// Debug: Check config file status (remove after debugging)
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain');
    echo "=== CONFIG.PHP ===\n";
    echo "APPLICATION_CONFIG path: " . APPLICATION_CONFIG . "\n";
    echo "File exists: " . (file_exists(APPLICATION_CONFIG) ? 'YES' : 'NO') . "\n";
    echo "Is readable: " . (is_readable(APPLICATION_CONFIG) ? 'YES' : 'NO') . "\n";
    echo "dirname(__FILE__): " . dirname(__FILE__) . "\n";
    echo "Real path: " . realpath(APPLICATION_CONFIG) . "\n";
    if (file_exists(APPLICATION_CONFIG)) {
        echo "File size: " . filesize(APPLICATION_CONFIG) . " bytes\n";
        echo "\nFirst 500 chars of config.php:\n";
        echo substr(file_get_contents(APPLICATION_CONFIG), 0, 500);
    }
    
    echo "\n\n=== .USER.INI ===\n";
    $userIniPath = dirname(__FILE__) . '/.user.ini';
    echo ".user.ini path: " . $userIniPath . "\n";
    echo "File exists: " . (file_exists($userIniPath) ? 'YES' : 'NO') . "\n";
    if (file_exists($userIniPath)) {
        echo "Contents:\n" . file_get_contents($userIniPath);
    }
    
    echo "\n\n=== PHP SESSION SETTINGS ===\n";
    echo "session.cookie_domain: " . ini_get('session.cookie_domain') . "\n";
    echo "session.cookie_secure: " . ini_get('session.cookie_secure') . "\n";
    echo "session.cookie_httponly: " . ini_get('session.cookie_httponly') . "\n";
    echo "session.cookie_samesite: " . ini_get('session.cookie_samesite') . "\n";
    echo "session.save_path: " . ini_get('session.save_path') . "\n";
    echo "session.use_cookies: " . ini_get('session.use_cookies') . "\n";
    echo "session.use_only_cookies: " . ini_get('session.use_only_cookies') . "\n";
    
    echo "\n\n=== SESSION TEST ===\n";
    $sessionPath = ini_get('session.save_path') ?: '/tmp';
    echo "Session save path: " . $sessionPath . "\n";
    echo "Path exists: " . (file_exists($sessionPath) ? 'YES' : 'NO') . "\n";
    echo "Path is writable: " . (is_writable($sessionPath) ? 'YES' : 'NO') . "\n";
    
    // Check session status before starting
    echo "Session status before start: " . session_status() . " (0=disabled, 1=none, 2=active)\n";
    
    // Try to start a session with error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    // Check if headers already sent
    if (headers_sent($file, $line)) {
        echo "Headers already sent in $file on line $line\n";
    } else {
        echo "Headers not sent yet - good\n";
    }
    
    // Try starting session
    $sessionResult = session_start();
    echo "session_start() returned: " . ($sessionResult ? 'TRUE' : 'FALSE') . "\n";
    echo "Session status after start: " . session_status() . " (0=disabled, 1=none, 2=active)\n";
    echo "Session ID: " . session_id() . "\n";
    
    // Show response headers that will be sent
    echo "\n=== RESPONSE HEADERS ===\n";
    $headers = headers_list();
    foreach ($headers as $h) {
        echo $h . "\n";
    }
    
    // Check for any session errors
    $lastError = error_get_last();
    if ($lastError) {
        echo "Last PHP error: " . print_r($lastError, true) . "\n";
    }
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['test'] = 'debug_test_' . time();
        echo "Session test value set: " . $_SESSION['test'] . "\n";
        
        // Check session file
        $sessionFile = $sessionPath . '/sess_' . session_id();
        echo "Session file path: " . $sessionFile . "\n";
        echo "Session file exists: " . (file_exists($sessionFile) ? 'YES' : 'NO') . "\n";
    } else {
        echo "Session NOT active - cannot set test value\n";
    }
    
    echo "\n\n=== REQUEST HEADERS (Proxy Info) ===\n";
    echo "HTTP_X_FORWARDED_PROTO: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'not set') . "\n";
    echo "HTTP_X_FORWARDED_FOR: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'not set') . "\n";
    echo "HTTPS: " . ($_SERVER['HTTPS'] ?? 'not set') . "\n";
    echo "REQUEST_SCHEME: " . ($_SERVER['REQUEST_SCHEME'] ?? 'not set') . "\n";
    echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'not set') . "\n";
    
    echo "\n\n=== COOKIES RECEIVED ===\n";
    print_r($_COOKIE);
    
    echo "\n\n=== ENVIRONMENT VARIABLES ===\n";
    echo "MYSQL_URL set: " . (getenv('MYSQL_URL') ? 'YES' : 'NO') . "\n";
    echo "DATABASE_URL set: " . (getenv('DATABASE_URL') ? 'YES' : 'NO') . "\n";
    echo "_ENV MYSQL_URL: " . (isset($_ENV['MYSQL_URL']) ? 'YES' : 'NO') . "\n";
    echo "_SERVER MYSQL_URL: " . (isset($_SERVER['MYSQL_URL']) ? 'YES' : 'NO') . "\n";
    
    exit;
}

### check if config.php was properly copied (for setup.php)
if (isset($_GET['a']) && ($_GET['a'] == 'cce'))
{
    header('Content-Type: text/html; charset=utf-8');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', FALSE);
    header('Pragma: no-cache');
    @ini_set('display_errors', 1);
    error_reporting(E_ALL ^ E_NOTICE);
    if (!file_exists(APPLICATION_CONFIG))
    {
        echo("File ".APPLICATION_CONFIG." does not exist. Please <a href='javascript: history.back(-1)'>go back</a> and create config file as described.");
        exit();
    }
    $config = include(APPLICATION_CONFIG);
    if (empty($config['db']['mysql']['user'])) {
        print "File amember/config.php is exist, but something went wrong. Database configuration was empty or cannot be read. Please remove amember/config.php <a href='setup.php'>and repeat installation</a>.";
        exit();
    }
    //all ok - redirect
    $url = "setup/?step=5";
    @header("Location: $url");
    exit();
}

#### regular config check
if (!file_exists(APPLICATION_CONFIG))
{
    /// try to determine baseurl here
    $setupUrl = htmlentities(str_replace('index.php', 'setup/', $_SERVER['PHP_SELF']), ENT_COMPAT, 'UTF-8');
    /// be careful with replacing this message, it is used for test in /setup/index.php
    $msg = "aMember is not configured yet. Go to <a href='$setupUrl'>configuration page</a>";
    print <<<CUT
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
    <head>
        <title>aMember PRO</title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <style type="text/css">
        <!--
        body {
            background: #eee;
            font: 80%/100% verdana, arial, helvetica, sans-serif;
            text-align: center; /* for IE */
        }
        a {
            color: #34536e;
            text-decoration: none;
            position: relative;
        }
        a:after {
            border-bottom:1px #9aa9b3 solid;
            content: '';
            height: 0;
            left: 0;
            right: 0;
            bottom: 1px;
            position: absolute;
        }
        a:hover:after {
            content: none;
        }
        #container {
            display: inline-block;
            margin: 50px auto 0;
            text-align: left;
            border: 2px solid #CCDDEB;
            background-color: #DFE8F0;
            padding: 10px;
            width: 60%;
        }
        -->
        </style>
    </head>
    <body>
        <div id="container">
            $msg
        </div>
    </body>
</html>
CUT;
    exit();
}

require_once dirname(__FILE__) . '/bootstrap.php';
$_amApp->run();
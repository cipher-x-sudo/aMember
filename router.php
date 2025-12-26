<?php
/**
 * Router script for PHP built-in server
 * Handles URL rewriting for aMember Pro
 */

$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$queryString = parse_url($requestUri, PHP_URL_QUERY);

// Remove leading slash
$requestPath = ltrim($requestPath, '/');

// Handle public files
if (preg_match('#^public(/.*)?$#', $requestPath)) {
    require_once __DIR__ . '/public.php';
    exit;
}

// Handle js.php
if ($requestPath === 'js.php') {
    require_once __DIR__ . '/js.php';
    exit;
}

// Handle setup directory
if (preg_match('#^setup(/.*)?$#', $requestPath)) {
    $setupPath = __DIR__ . '/setup/index.php';
    if (file_exists($setupPath)) {
        // Preserve query string
        if ($queryString) {
            $_SERVER['QUERY_STRING'] = $queryString;
            parse_str($queryString, $_GET);
        }
        require_once $setupPath;
        exit;
    }
}

// Handle static files (don't process through index.php)
$staticExtensions = ['js', 'ico', 'gif', 'jpg', 'jpeg', 'png', 'css', 'swf', 'csv', 'html', 'pdf', 'woff', 'woff2', 'ttf', 'eot', 'svg', 'css.map', 'js.map'];
$extension = pathinfo($requestPath, PATHINFO_EXTENSION);
if (in_array(strtolower($extension), $staticExtensions)) {
    // Serve static file if it exists
    $filePath = __DIR__ . '/' . $requestPath;
    if (file_exists($filePath) && is_file($filePath)) {
        return false; // Let PHP built-in server serve it
    }
}

// Check if the requested file exists and serve it directly
$filePath = __DIR__ . '/' . $requestPath;
if (file_exists($filePath) && is_file($filePath) && !is_dir($filePath)) {
    return false; // Let PHP built-in server serve it
}

// All other requests go to index.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once __DIR__ . '/index.php';


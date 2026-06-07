<?php
/**
 * Admin Panel Entry Point
 * Access via: yourdomain.com/manage-panel-xk92/
 * (Change ADMIN_PATH in config.php to any secret slug)
 */
require_once __DIR__ . '/../core/Bootstrap.php';

// Only serve admin panel from the secret path
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$adminPath  = '/' . ADMIN_PATH;
if (strpos($requestUri, $adminPath) === false) {
    http_response_code(404);
    exit('Not found.');
}

// For the admin panel HTML (non-API requests), just serve the HTML
// API calls go to /api/admin/*.php directly
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');

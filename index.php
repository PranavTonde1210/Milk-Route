<?php
/**
 * MilkRoute Root Entry Point
 * Serves the customer-facing SPA for all non-API, non-admin requests.
 */

// If this is an API request, route to the appropriate API file
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Block admin access via root (admin has its own protected path)
if (strpos($uri, '/admin') !== false) {
    http_response_code(404);
    exit('Not found.');
}

// Serve the customer SPA
require_once __DIR__ . '/public/index.html';

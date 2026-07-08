<?php
/**
 * Broasted Chicken POS — front controller.
 * All requests route through here:  index.php?page=<name>
 */

declare(strict_types=1);

session_start();

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/auth.php';

// Whitelisted pages -> file in /pages
$routes = [
    'login'     => 'login.php',
    'logout'    => 'logout.php',
    'dashboard' => 'dashboard.php',
    'sales'     => 'sales.php',
    'sale_view' => 'sale_view.php',
    'products'  => 'products.php',
    'inventory' => 'inventory.php',
    'reports'   => 'reports.php',
    'users'     => 'users.php',
    'settings'  => 'settings.php',
];

$page = $_GET['page'] ?? (is_logged_in() ? 'dashboard' : 'login');

if (!isset($routes[$page])) {
    http_response_code(404);
    $page = is_logged_in() ? 'dashboard' : 'login';
}

require __DIR__ . '/../pages/' . $routes[$page];

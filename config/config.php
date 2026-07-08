<?php
/**
 * Application configuration.
 *
 * For real deployments, prefer environment variables over editing this file.
 * Any value below can be overridden by setting the matching environment var.
 */

return [
    // ---- Database (MySQL) ----
    'db' => [
        'host'     => getenv('DB_HOST')     ?: 'localhost',
        'port'     => getenv('DB_PORT')     ?: '3306',
        'name'     => getenv('DB_NAME')     ?: 'sbpos',
        'user'     => getenv('DB_USER')     ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],

    // ---- App ----
    'app_name'        => 'sbPOS',
    'app_tagline'     => 'small business point of sale',
    'currency_symbol' => '₱',          // Philippine Peso
    'currency_code'   => 'PHP',

    // Folder (relative to /public) where menu photos are stored
    'upload_dir'      => __DIR__ . '/../public/uploads',
    'upload_url'      => 'uploads',
    'max_upload_mb'   => 4,
];

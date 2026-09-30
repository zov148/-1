<?php
return [
  'brand' => 'NEXA OFFICE',
  'db' => [
    'driver' => getenv('DB_DRIVER') ?: 'mysql',
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_NAME') ?: 'nexa_office',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: '',
    'sqlite_path' => __DIR__ . '/storage/nexa.sqlite',
  ],
];

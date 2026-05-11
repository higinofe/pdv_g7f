<?php
// Configuração do banco SQLite local
return [
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database/pdv.db',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];

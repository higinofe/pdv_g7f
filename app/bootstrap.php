<?php
/**
 * Bootstrap: autoloader simples (PSR-4 manual), .env, banco e sessão.
 * Incluído por public/index.php e por cada endpoint em public/api.
 */

declare(strict_types=1);

// Autoload PSR-4 manual para o namespace "App\"
spl_autoload_register(function (string $classe): void {
    if (!str_starts_with($classe, 'App\\')) return;
    $relativo = str_replace('\\', '/', substr($classe, 4));
    $arquivo  = __DIR__ . '/' . $relativo . '.php';
    if (is_file($arquivo)) {
        require_once $arquivo;
    }
});

use App\Helpers\Env;
use App\Helpers\Database;
use App\Helpers\Auth;

// Carrega .env e inicializa banco/sessão. Overrides do banco rodam DEPOIS
// do Database::pdo() porque dependem dele e devem vencer o .env.
Env::load();
Database::pdo();
Env::aplicarOverrides();
Auth::iniciar();

// Configurações de timezone e charset
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Response;
use App\Services\ErpClient;

Auth::exigirLogin();

$pdo = Database::pdo();

$pendentesCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM vendas WHERE status = 'pendente'"
)->fetchColumn();

$ultimaSync = $pdo->query(
    "SELECT atualizado_em FROM vendas
     WHERE status = 'finalizada' AND atualizado_em IS NOT NULL
     ORDER BY atualizado_em DESC LIMIT 1"
)->fetchColumn();

// Ping rápido ao ERP — timeout curto pra não travar o polling da UI
$online = false;
try {
    $online = (new ErpClient())->pingRapido(3);
} catch (\Throwable $e) {
    $online = false;
}

Response::ok([
    'online'      => $online,
    'pendentes'   => $pendentesCount,
    'ultima_sync' => $ultimaSync ?: null,
]);

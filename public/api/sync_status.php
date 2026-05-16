<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Response;
use App\Services\ErpClient;

Auth::exigirLogin();

$pdo = Database::pdo();

$pendentesVendas = (int) $pdo->query(
    "SELECT COUNT(*) FROM vendas WHERE status = 'pendente'"
)->fetchColumn();

// Fechamentos com snapshot mas ainda não confirmados pelo ERP — também contam
// como "pendentes" no badge da topbar, pra avisar que falta sync.
$pendentesFechamentos = (int) $pdo->query(
    "SELECT COUNT(*) FROM sessoes_caixa
      WHERE status = 'fechada'
        AND dados_fechamento IS NOT NULL
        AND enviado_erp_em IS NULL"
)->fetchColumn();

$pendentesCount = $pendentesVendas + $pendentesFechamentos;

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
    'online'                => $online,
    'pendentes'             => $pendentesCount,
    'pendentes_vendas'      => $pendentesVendas,
    'pendentes_fechamentos' => $pendentesFechamentos,
    'ultima_sync'           => $ultimaSync ?: null,
]);

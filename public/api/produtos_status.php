<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Response;

Auth::exigirLogin();

$pdo = Database::pdo();
$total    = (int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn();
$totalErp = (int) $pdo->query('SELECT COUNT(*) FROM produtos WHERE erp_id IS NOT NULL')->fetchColumn();
$ultimo   = (string) ($pdo->query('SELECT MAX(atualizado_em) FROM produtos')->fetchColumn() ?: '');

Response::ok([
    'total'         => $total,
    'total_erp'     => $totalErp,
    'precisa_carga' => $totalErp === 0,
    'ultima_carga'  => $ultimo,
]);

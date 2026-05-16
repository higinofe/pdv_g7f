<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Response;
use App\Services\ComandaSync;

// Sem auth: é usado pela tela de terminal de comanda (que não exige login)
// e também pode ser disparado da frente de caixa.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$r = ComandaSync::executar();
if (!$r['sucesso']) {
    Response::erro($r['mensagem'] ?? 'Falha ao sincronizar comandas', 502);
}
Response::ok($r);

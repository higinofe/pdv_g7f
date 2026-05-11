<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\CupomService;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$vendaId = (int)($dados['venda_id'] ?? 0);
if ($vendaId <= 0) {
    Response::erro('Informe o ID da venda');
}

$resultado = CupomService::transmitir($vendaId);
if (!$resultado['sucesso']) {
    Response::erro($resultado['mensagem'], 422);
}

Response::ok($resultado);

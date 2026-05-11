<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\ProdutoSync;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

// Carga pode demorar — relaxa o tempo limite
set_time_limit(300);

$resultado = ProdutoSync::executar();
if (!$resultado['sucesso']) {
    Response::erro($resultado['mensagem'], 502);
}

Response::ok($resultado);

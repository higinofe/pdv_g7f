<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\OperadorSync;

Auth::exigirLogin();

$op = Auth::operador();
if (($op['perfil'] ?? '') !== 'admin') {
    Response::erro('Apenas administradores podem sincronizar operadores', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

set_time_limit(120);

$resultado = OperadorSync::executar();
if (!$resultado['sucesso']) {
    Response::erro($resultado['mensagem'], 502);
}

Response::ok($resultado);

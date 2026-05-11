<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Models\Operador;

Auth::exigirLogin();

$logado = Auth::operador();
if (($logado['perfil'] ?? null) !== 'admin') {
    Response::erro('Apenas administradores podem listar operadores', 403);
}

Response::ok(['operadores' => Operador::listar()]);

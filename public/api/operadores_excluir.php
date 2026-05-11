<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Models\Operador;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$logado = Auth::operador();
if (($logado['perfil'] ?? null) !== 'admin') {
    Response::erro('Apenas administradores podem desativar operadores', 403);
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$id    = (int) ($dados['id'] ?? 0);

if ($id <= 0) Response::erro('ID inválido');
if ($id === (int) $logado['id']) Response::erro('Você não pode desativar a si mesmo');

Operador::desativar($id);
Response::ok([]);

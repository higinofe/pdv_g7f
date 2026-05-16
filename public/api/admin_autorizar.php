<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Services\AdminSenha;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$senha = (string) ($dados['senha'] ?? $dados['password'] ?? '');

if ($senha === '') {
    Response::erro('Informe a senha de administrador');
}

if (!AdminSenha::validar($senha)) {
    Response::erro('Senha de administrador inválida', 401);
}

Auth::concederAdmin(300);

$logado = Auth::operador();
Logger::info('Elevação admin concedida', [
    'operador' => $logado['usuario'] ?? null,
]);

Response::ok(['expira_em' => 300]);

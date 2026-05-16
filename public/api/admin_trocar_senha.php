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

$dados        = json_decode(file_get_contents('php://input'), true) ?? [];
$senhaAtual   = (string) ($dados['senha_atual']   ?? '');
$senhaNova    = (string) ($dados['senha_nova']    ?? '');
$confirmacao  = (string) ($dados['confirmacao']   ?? '');

if ($senhaAtual === '' || $senhaNova === '') {
    Response::erro('Informe a senha atual e a nova senha');
}
if (strlen($senhaNova) < 4) {
    Response::erro('A nova senha precisa ter pelo menos 4 caracteres');
}
if ($senhaNova !== $confirmacao) {
    Response::erro('A confirmação não bate com a nova senha');
}

if (!AdminSenha::trocar($senhaAtual, $senhaNova)) {
    Response::erro('Senha atual incorreta', 401);
}

// Revoga elevações em curso — qualquer ação subsequente vai exigir a NOVA
// senha. Operador continua logado normalmente.
Auth::revogarAdmin();

$logado = Auth::operador();
Logger::info('Senha de admin alterada', [
    'operador' => $logado['usuario'] ?? null,
]);

Response::ok([]);

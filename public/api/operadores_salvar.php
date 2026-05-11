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
    Response::erro('Apenas administradores podem salvar operadores', 403);
}

$dados   = json_decode(file_get_contents('php://input'), true) ?? [];
$id      = isset($dados['id']) && $dados['id'] !== '' ? (int) $dados['id'] : null;
$usuario = (string) ($dados['usuario'] ?? '');
$nome    = (string) ($dados['nome']    ?? '');
$perfil  = (string) ($dados['perfil']  ?? 'operador');
$ativo   = !empty($dados['ativo']);
$senha   = isset($dados['senha']) && $dados['senha'] !== '' ? (string) $dados['senha'] : null;

try {
    $novoId = Operador::salvar($id, $usuario, $nome, $perfil, $ativo, $senha);
    Response::ok(['id' => $novoId]);
} catch (\Throwable $e) {
    Response::erro($e->getMessage());
}

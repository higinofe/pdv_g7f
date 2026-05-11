<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Models\Caixa;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$dados      = json_decode(file_get_contents('php://input'), true) ?? [];
$valor      = (float) ($dados['valor_abertura'] ?? 0);
$observacao = trim((string) ($dados['observacao'] ?? '')) ?: null;

if ($valor < 0) Response::erro('Valor de abertura inválido');

$pdvId    = (string) Env::get('PDV_ID', '001');
$operador = Auth::operador();

try {
    $sessaoId = Caixa::abrir($pdvId, (int) $operador['id'], $valor, $observacao);
    Logger::info('Caixa aberto', ['sessao_id' => $sessaoId, 'operador' => $operador['usuario'], 'valor' => $valor]);
    Response::ok(['sessao_id' => $sessaoId]);
} catch (\Throwable $e) {
    Response::erro($e->getMessage());
}

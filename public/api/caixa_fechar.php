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

$pdvId  = (string) Env::get('PDV_ID', '001');
$sessao = Caixa::sessaoAberta($pdvId);
if (!$sessao) Response::erro('Não há caixa aberto para fechar');

$dados        = json_decode(file_get_contents('php://input'), true) ?? [];
$informado    = isset($dados['valor_informado']) ? (float) $dados['valor_informado'] : null;
$observacao   = trim((string) ($dados['observacao'] ?? '')) ?: null;

try {
    $resumo = Caixa::fechar((int) $sessao['id'], $informado, $observacao);
    Logger::info('Caixa fechado', ['sessao_id' => $sessao['id'], 'diferenca' => $resumo['diferenca']]);
    Response::ok(['resumo' => $resumo]);
} catch (\Throwable $e) {
    Response::erro($e->getMessage());
}

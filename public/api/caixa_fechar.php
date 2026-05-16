<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Models\Caixa;
use App\Services\FechamentoSync;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$pdvId  = (string) Env::get('PDV_ID', '001');
$sessao = Caixa::sessaoAberta($pdvId);
if (!$sessao) Response::erro('Não há caixa aberto para fechar');

$dados      = json_decode(file_get_contents('php://input'), true) ?? [];
$informado  = isset($dados['valor_informado']) ? (float) $dados['valor_informado'] : null;
$observacao = trim((string) ($dados['observacao'] ?? '')) ?: null;
$op         = Auth::operador();
$operadorId = isset($op['id']) ? (int) $op['id'] : null;

try {
    // 1. Fecha localmente — gera snapshot completo (vendas+itens+pagamentos+
    //    movimentos+totais+operadores) e grava em sessoes_caixa.dados_fechamento.
    $resumo = Caixa::fechar((int) $sessao['id'], $informado, $observacao, $operadorId);
    Logger::info('Caixa fechado', [
        'sessao_id'  => $sessao['id'],
        'diferenca'  => $resumo['diferenca'],
        'operador'   => $op['usuario'] ?? null,
    ]);

    // 2. Tenta enviar ao ERP imediatamente. Se falhar (offline), fica pendente
    //    e será reenviado pelo /api/sync_fechamentos.php (manual) ou pelo cron.
    $envio = FechamentoSync::executarUma((int) $sessao['id']);

    Response::ok([
        'resumo'    => $resumo,
        'envio_erp' => $envio,
    ]);
} catch (\Throwable $e) {
    Response::erro($e->getMessage());
}

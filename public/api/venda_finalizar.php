<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Response;
use App\Models\Caixa;
use App\Models\Comanda;
use App\Services\VendaService;

Auth::exigirLogin();

$pdvId  = (string) Env::get('PDV_ID', '001');
$sessao = Caixa::sessaoAberta($pdvId);
if (!$sessao) {
    Response::erro('Não há caixa aberto. Abra o caixa antes de vender.', 409);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];

$itens = $dados['itens'] ?? [];
if (!is_array($itens) || count($itens) === 0) {
    Response::erro('Carrinho vazio');
}

$formasValidas = ['dinheiro', 'debito', 'credito', 'pix', 'outros'];

// Pagamento misto: aceita `pagamentos: [{forma, valor}, ...]`. Mantém o
// formato antigo `forma_pagamento` como fallback (vira lista de 1).
$pagamentos = [];
if (!empty($dados['pagamentos']) && is_array($dados['pagamentos'])) {
    foreach ($dados['pagamentos'] as $p) {
        if (!is_array($p)) continue;
        $forma = (string)($p['forma'] ?? '');
        $valor = (float)($p['valor'] ?? 0);
        if (!in_array($forma, $formasValidas, true)) {
            Response::erro("Forma de pagamento inválida: {$forma}");
        }
        if ($valor <= 0) Response::erro('Pagamentos devem ter valor positivo');
        $pagamentos[] = ['forma' => $forma, 'valor' => $valor];
    }
}

if (empty($pagamentos)) {
    $forma = $dados['forma_pagamento'] ?? null;
    if (!in_array($forma, $formasValidas, true)) {
        Response::erro('Forma de pagamento inválida');
    }
    $valorTotal = (float)($dados['valor_total'] ?? 0);
    $pagamentos[] = [
        'forma' => $forma,
        'valor' => isset($dados['valor_recebido']) && (float)$dados['valor_recebido'] > 0
            ? (float)$dados['valor_recebido']
            : $valorTotal,
    ];
}

// Valida cobertura do total: a soma dos pagamentos não pode ser menor que o
// valor total da venda. Excesso só é permitido se houver pagamento em
// dinheiro (excesso vira troco).
$valorTotal = (float)($dados['valor_total'] ?? 0);
$soma = 0.0;
$temDinheiro = false;
foreach ($pagamentos as $p) {
    $soma += $p['valor'];
    if ($p['forma'] === 'dinheiro') $temDinheiro = true;
}
if ($soma + 0.005 < $valorTotal) {
    Response::erro('Soma dos pagamentos é menor que o total da venda');
}
if ($soma - 0.005 > $valorTotal && !$temDinheiro) {
    Response::erro('Excesso de pagamento só é permitido em dinheiro (troco)');
}

$op = Auth::operador();
$cabecalho = [
    'operador_id'     => $op['id'] ?? null,
    'pdv_id'          => $pdvId,
    'sessao_id'       => (int) $sessao['id'],
    'pagamentos'      => $pagamentos,                  // novo formato
    'forma_pagamento' => null,                          // o Model decide ("misto" se >1)
    'valor_total'     => $valorTotal,
    'valor_desconto'  => (float)($dados['valor_desconto'] ?? 0),
    'valor_recebido'  => $soma,
    'valor_troco'     => max(0.0, $soma - $valorTotal),
];

$resultado = VendaService::finalizar($cabecalho, $itens);

// Se a venda baixou uma comanda (operador bipou o código no caixa), marca a
// comanda como finalizada. Idempotente — só altera se ainda estiver aberta.
$comandaCodigo = trim((string)($dados['comanda_codigo'] ?? ''));
if ($comandaCodigo !== '' && !empty($resultado['venda_id'])) {
    $comanda = Comanda::porCodigo($comandaCodigo);
    if ($comanda) {
        Comanda::finalizar((int)$comanda['id'], (int)$resultado['venda_id']);
        $resultado['comanda_baixada'] = $comandaCodigo;
    }
}

Response::ok($resultado);

<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Response;
use App\Models\Caixa;
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

$forma = $dados['forma_pagamento'] ?? null;
$formasValidas = ['dinheiro', 'debito', 'credito', 'pix', 'outros'];
if (!in_array($forma, $formasValidas, true)) {
    Response::erro('Forma de pagamento inválida');
}

$op = Auth::operador();
$cabecalho = [
    'operador_id'     => $op['id'] ?? null,
    'pdv_id'          => $pdvId,
    'sessao_id'       => (int) $sessao['id'],
    'forma_pagamento' => $forma,
    'valor_total'     => (float)($dados['valor_total'] ?? 0),
    'valor_desconto'  => (float)($dados['valor_desconto'] ?? 0),
    'valor_recebido'  => isset($dados['valor_recebido']) ? (float)$dados['valor_recebido'] : null,
    'valor_troco'     => isset($dados['valor_troco'])    ? (float)$dados['valor_troco']    : null,
];

$resultado = VendaService::finalizar($cabecalho, $itens);
Response::ok($resultado);

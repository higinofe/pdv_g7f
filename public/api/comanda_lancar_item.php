<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Env;
use App\Helpers\Response;
use App\Models\Caixa;
use App\Models\Comanda;
use App\Models\Produto;

// Endpoint do "terminal de comanda" — sem login, mas só funciona com o caixa
// do PDV fechado (regra do briefing: a tela de comanda só existe quando o caixa
// está fechado, pra não rodar em paralelo com a frente de caixa).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$pdvId = (string) Env::get('PDV_ID', '001');
if (Caixa::sessaoAberta($pdvId)) {
    Response::erro('Caixa aberto — terminal de comanda indisponível', 409);
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];

$codigoComanda = trim((string)($dados['comanda_codigo'] ?? ''));
$codigoProduto = trim((string)($dados['produto_codigo'] ?? ''));
$quantidade    = (float)($dados['quantidade'] ?? 1);

if ($codigoComanda === '') Response::erro('Comanda não informada');
if ($codigoProduto === '') Response::erro('Produto não informado');
if ($quantidade <= 0)      Response::erro('Quantidade inválida');

$comanda = Comanda::porCodigo($codigoComanda);
if (!$comanda)                       Response::erro('Comanda não encontrada', 404);
if ($comanda['status'] !== 'aberta') Response::erro('Comanda já finalizada', 409);

$produto = Produto::porCodigoOuBarras($codigoProduto);
if (!$produto) Response::erro('Produto não encontrado', 404);

$itemId = Comanda::lancarItem((int) $comanda['id'], $produto, $quantidade, $pdvId);
$itens  = Comanda::itens((int) $comanda['id']);
$total  = 0.0;
foreach ($itens as $i) $total += (float) $i['subtotal'];

Response::ok([
    'item_id' => $itemId,
    'comanda' => $comanda,
    'itens'   => $itens,
    'total'   => $total,
]);

<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Env;
use App\Helpers\Response;
use App\Models\Caixa;
use App\Models\Comanda;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$pdvId = (string) Env::get('PDV_ID', '001');
if (Caixa::sessaoAberta($pdvId)) {
    Response::erro('Caixa aberto — terminal de comanda indisponível', 409);
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$comandaCodigo = trim((string)($dados['comanda_codigo'] ?? ''));
$itemId        = (int)($dados['item_id'] ?? 0);

if ($comandaCodigo === '') Response::erro('Comanda não informada');
if ($itemId <= 0)          Response::erro('Item inválido');

$comanda = Comanda::porCodigo($comandaCodigo);
if (!$comanda) Response::erro('Comanda não encontrada', 404);

$ok = Comanda::removerItem((int)$comanda['id'], $itemId);
if (!$ok) Response::erro('Item não encontrado', 404);

$itens = Comanda::itens((int) $comanda['id']);
$total = 0.0;
foreach ($itens as $i) $total += (float) $i['subtotal'];

Response::ok([
    'comanda' => $comanda,
    'itens'   => $itens,
    'total'   => $total,
]);

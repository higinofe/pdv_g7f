<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Response;
use App\Models\Comanda;

// Sem auth: usado pelo terminal (sem login) e pelo caixa (com login) —
// ambos precisam buscar uma comanda pelo código bipado.
$codigo = trim((string)($_GET['codigo'] ?? ''));
if ($codigo === '') {
    Response::erro('Informe o código da comanda');
}

$comanda = Comanda::porCodigo($codigo);
if (!$comanda) {
    Response::erro('Comanda não encontrada', 404);
}

$itens = Comanda::itens((int) $comanda['id']);
$total = 0.0;
foreach ($itens as $i) $total += (float) $i['subtotal'];

Response::ok([
    'comanda' => $comanda,
    'itens'   => $itens,
    'total'   => $total,
]);

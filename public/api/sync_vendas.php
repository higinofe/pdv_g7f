<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\VendaSync;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

set_time_limit(300);

$resultado = VendaSync::executar();

$total = $resultado['total'];
$ok    = $resultado['ok'];
$falhas = $resultado['falhas'];

if ($total === 0) {
    Response::ok([
        'mensagem' => 'Nenhuma venda pendente.',
        'total'    => 0,
        'ok'       => 0,
        'falhas'   => 0,
    ]);
}

if ($ok === 0 && $falhas > 0) {
    Response::erro(
        'Não foi possível reenviar: ' . ($resultado['ultimo_erro'] ?? 'erro desconhecido'),
        502,
        ['total' => $total, 'ok' => $ok, 'falhas' => $falhas]
    );
}

$msg = $falhas === 0
    ? "{$ok} venda(s) reenviada(s) com sucesso."
    : "{$ok} reenviada(s), {$falhas} com falha — tentaremos novamente.";

Response::ok([
    'mensagem'    => $msg,
    'total'       => $total,
    'ok'          => $ok,
    'falhas'      => $falhas,
    'ultimo_erro' => $resultado['ultimo_erro'],
]);

<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\FechamentoSync;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

set_time_limit(120);

$r = FechamentoSync::executar();
Response::ok($r + [
    'mensagem' => $r['total'] === 0
        ? 'Nenhum fechamento pendente.'
        : "Fechamentos: {$r['ok']} enviado(s), {$r['falhas']} falha(s).",
]);

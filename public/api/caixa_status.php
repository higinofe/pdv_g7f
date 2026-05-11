<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Response;
use App\Models\Caixa;

Auth::exigirLogin();

$pdvId  = (string) Env::get('PDV_ID', '001');
$sessao = Caixa::sessaoAberta($pdvId);

if (!$sessao) {
    Response::ok(['aberta' => false, 'sessao' => null]);
}

$totais = Caixa::totais((int) $sessao['id']);

Response::ok([
    'aberta' => true,
    'sessao' => $sessao,
    'totais' => $totais,
]);

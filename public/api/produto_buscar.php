<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Models\Produto;

Auth::exigirLogin();

$valor = trim((string)($_GET['valor'] ?? ''));
if ($valor === '') {
    Response::erro('Informe o código ou código de barras');
}

$produto = Produto::porCodigoOuBarras($valor);
if (!$produto) {
    Response::erro('Produto não encontrado', 404);
}

Response::ok(['produto' => $produto]);

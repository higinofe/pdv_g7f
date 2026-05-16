<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Env;
use App\Helpers\Response;
use App\Models\Caixa;
use App\Models\Produto;

// Igual ao /api/produto_buscar.php, mas sem exigir login — usado no terminal
// de comanda. Continua bloqueado se houver caixa aberto (a tela só existe
// quando o caixa está fechado).
$pdvId = (string) Env::get('PDV_ID', '001');
if (Caixa::sessaoAberta($pdvId)) {
    Response::erro('Caixa aberto — terminal de comanda indisponível', 409);
}

$valor = trim((string)($_GET['valor'] ?? ''));
if ($valor === '') Response::erro('Informe o código ou código de barras');

$produto = Produto::porCodigoOuBarras($valor);
if (!$produto) Response::erro('Produto não encontrado', 404);

Response::ok(['produto' => $produto]);

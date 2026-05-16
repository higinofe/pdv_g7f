<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Response;

Auth::exigirAdmin();

// Chaves expostas na tela "Configurações da Integração".
// Listadas explicitamente para não vazar sem querer alguma var nova.
$campos = ['API_URL', 'API_TOKEN', 'PDV_ID', 'PDV_NOME', 'HTTP_TIMEOUT', 'SLIDES_INTERVALO_MS',
           'ENDPOINT_PRODUTOS', 'ENDPOINT_OPERADORES', 'ENDPOINT_OPERADOR_LOGIN',
           'ENDPOINT_VENDAS', 'ENDPOINT_CUPOM', 'ENDPOINT_COMANDAS', 'ENDPOINT_FECHAMENTOS'];

$valores = [];
foreach ($campos as $c) {
    $valores[$c] = (string) Env::get($c, '');
}

// Token mascarado: a senha real não trafega pra UI — só os últimos 4
// caracteres, para o admin confirmar que o token salvo bate com o que
// ele tem. Para regravar, basta digitar o token novo no campo.
$token = (string) Env::get('API_TOKEN', '');
$tokenMascarado = $token === '' ? '' : str_repeat('•', max(0, strlen($token) - 4)) . substr($token, -4);
$valores['API_TOKEN'] = $tokenMascarado;

Response::ok(['config' => $valores]);

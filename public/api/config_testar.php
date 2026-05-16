<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Response;
use App\Services\ErpClient;

Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

// Aceita valores não salvos via body — assim o admin testa antes de gravar.
$dados      = json_decode(file_get_contents('php://input'), true) ?? [];
$urlOver    = isset($dados['API_URL'])   ? trim((string) $dados['API_URL'])   : null;
$tokenOver  = isset($dados['API_TOKEN']) ? trim((string) $dados['API_TOKEN']) : null;
$pdvOver    = isset($dados['PDV_NOME'])  ? trim((string) $dados['PDV_NOME'])  : null;

// Se vier vazio/mascarado, usa o atual.
if ($tokenOver === '' || ($tokenOver !== null && preg_match('/^[•\*]+/', $tokenOver))) {
    $tokenOver = null;
}
if ($urlOver === '')   $urlOver   = null;
if ($pdvOver === '')   $pdvOver   = null;

$erp = new ErpClient($tokenOver, $pdvOver, $urlOver);
$r   = $erp->testarConexao();

if ($r['erro']) {
    Response::erro($r['erro'], 502);
}
Response::ok([
    'status' => $r['status'],
    'ok'     => true,
]);

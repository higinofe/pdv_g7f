<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Models\Caixa;

Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$pdvId  = (string) Env::get('PDV_ID', '001');
$sessao = Caixa::sessaoAberta($pdvId);
if (!$sessao) Response::erro('Não há caixa aberto');

$dados  = json_decode(file_get_contents('php://input'), true) ?? [];
$tipo   = (string) ($dados['tipo']   ?? '');
$valor  = (float)  ($dados['valor']  ?? 0);
$motivo = trim((string) ($dados['motivo'] ?? '')) ?: null;

try {
    $id = Caixa::registrarMovimento(
        (int) $sessao['id'],
        (int) Auth::operador()['id'],
        $tipo, $valor, $motivo
    );
    Logger::info('Movimento de caixa', ['id' => $id, 'tipo' => $tipo, 'valor' => $valor]);
    Response::ok(['id' => $id, 'totais' => Caixa::totais((int) $sessao['id'])]);
} catch (\Throwable $e) {
    Response::erro($e->getMessage());
}

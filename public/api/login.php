<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Models\Operador;
use App\Services\ErpClient;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$dados   = json_decode(file_get_contents('php://input'), true) ?? [];
// Aceita "usuario" (legado, login do admin local) ou "email" (operadores do ERP).
$entrada = trim((string)($dados['email'] ?? $dados['usuario'] ?? ''));
$senha   = (string)($dados['senha'] ?? $dados['password'] ?? '');

if ($entrada === '' || $senha === '') {
    Response::erro('Informe e-mail e senha');
}

// 1) Login local primeiro: cobre o admin do PDV (sempre funciona offline).
$opLocal = Operador::autenticar($entrada, $senha);
if ($opLocal) {
    Auth::logar($opLocal);
    Response::ok(['operador' => $opLocal, 'origem' => 'local']);
}

// 2) Login online no ERP B7F: POST /operadores/login?pdv=<nome>
//    Payload exigido: { usuario, password } (PIN de 4 dígitos para caixa/atendente).
//    Backend normaliza usuario p/ minúsculas — fazemos o mesmo no client.
$endpoint = (string) Env::get('ENDPOINT_OPERADOR_LOGIN', '/operadores/login');
$erp = new ErpClient();
$resp = $erp->post($endpoint, [
    'usuario'  => strtolower($entrada),
    'password' => $senha,
]);

if ($resp['erro'] === null && is_array($resp['body'] ?? null) && !empty($resp['body']['operador'])) {
    $erpOp = $resp['body']['operador'];

    // Upsert local + grava hash da senha (passa a permitir offline depois).
    Operador::upsertDoErp($erpOp);

    // Reencontra o registro local (pode ter sido criado agora) — tenta erp_id, email e usuario.
    $opLocal = null;
    if (!empty($erpOp['id'])) {
        $opLocal = Operador::porId((int)$erpOp['id']) ?: null;
        // Caso porId tenha buscado pelo PK local em vez do erp_id, faz fallback:
        if (!$opLocal || (int)($opLocal['erp_id'] ?? 0) !== (int)$erpOp['id']) {
            $stmt = \App\Helpers\Database::pdo()->prepare('SELECT * FROM operadores WHERE erp_id = ? LIMIT 1');
            $stmt->execute([(int)$erpOp['id']]);
            $opLocal = $stmt->fetch() ?: $opLocal;
        }
    }
    if (!$opLocal && !empty($erpOp['email'])) $opLocal = Operador::porEmail($erpOp['email']);
    if (!$opLocal) $opLocal = Operador::porUsuario($entrada);

    if ($opLocal) {
        Operador::gravarHashSenha((int)$opLocal['id'], $senha);
        unset($opLocal['senha_hash']);
        Auth::logar($opLocal);
        Logger::info('Login online OK no ERP', ['login' => $entrada]);
        Response::ok(['operador' => $opLocal, 'origem' => 'erp']);
    }
}

// Online respondeu 401: credenciais inválidas — não cai para offline.
if (($resp['status'] ?? 0) === 401) {
    Response::erro('Credenciais inválidas.', 401);
}

// 3) Sem rota válida para autenticar.
Response::erro('Usuário ou senha inválidos', 401);

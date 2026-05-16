<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Logger;
use App\Helpers\Response;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

// Sem exigir login: o botão "Sistema" é exposto na splash (pré-login) e no
// terminal de comanda (que não tem sessão). A ação é física no próprio PDV
// — equivalente a apertar o botão de energia da máquina.

$dados = json_decode(file_get_contents('php://input'), true) ?? [];
$acao  = (string) ($dados['acao'] ?? '');

// Comandos absolutos (mesmo caminho liberado no /etc/sudoers.d/pdv-sistema).
$mapa = [
    'reboot'   => '/usr/sbin/reboot',
    'shutdown' => '/usr/sbin/shutdown -h now',
];

if (!isset($mapa[$acao])) {
    Response::erro('Ação inválida');
}

$op = Auth::operador();
Logger::info('Acionado ' . $acao . ' via PDV', [
    'operador' => $op['usuario'] ?? '?',
]);

// Dispara em background para o PHP conseguir responder ao cliente antes do
// kernel matar a conexão. nohup + & garante que o processo não seja morto
// quando o php-fpm encerrar o request.
$cmd = 'sudo -n ' . $mapa[$acao] . ' > /dev/null 2>&1 &';
shell_exec($cmd);

Response::ok(['acao' => $acao]);

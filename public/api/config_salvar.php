<?php
require __DIR__ . '/../../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Helpers\Logger;
use App\Helpers\Response;

Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::erro('Método não permitido', 405);
}

$dados = json_decode(file_get_contents('php://input'), true) ?? [];

// Whitelist de chaves editáveis — qualquer outra chave no body é ignorada.
$permitidas = ['API_URL', 'API_TOKEN', 'PDV_ID', 'PDV_NOME', 'HTTP_TIMEOUT', 'SLIDES_INTERVALO_MS',
               'ENDPOINT_PRODUTOS', 'ENDPOINT_OPERADORES', 'ENDPOINT_OPERADOR_LOGIN',
               'ENDPOINT_VENDAS', 'ENDPOINT_CUPOM', 'ENDPOINT_COMANDAS', 'ENDPOINT_FECHAMENTOS'];

$alteradas = [];
foreach ($permitidas as $chave) {
    if (!array_key_exists($chave, $dados)) continue;
    $valor = trim((string) $dados[$chave]);

    // API_TOKEN só é regravado se vier um valor novo (a UI mostra mascarado);
    // string vazia ou só com bullets significa "manter o atual".
    if ($chave === 'API_TOKEN') {
        if ($valor === '' || preg_match('/^[•\*]+/', $valor)) continue;
    }

    // Validação leve por tipo — evita gravar lixo óbvio.
    if (in_array($chave, ['HTTP_TIMEOUT', 'SLIDES_INTERVALO_MS'], true)) {
        if (!ctype_digit($valor) || (int) $valor <= 0) {
            Response::erro($chave . ' deve ser um número inteiro positivo');
        }
    }
    if ($chave === 'API_URL' && $valor !== '' && !filter_var($valor, FILTER_VALIDATE_URL)) {
        Response::erro('API_URL inválida');
    }

    Env::setOverride($chave, $valor);
    $alteradas[] = $chave;
}

$logado = Auth::operador();
Logger::info('Configurações da integração atualizadas', [
    'operador'  => $logado['usuario'] ?? null,
    'alteradas' => $alteradas,
]);

Response::ok(['alteradas' => $alteradas]);

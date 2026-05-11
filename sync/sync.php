<?php
/**
 * sync.php — script CLI executado periodicamente via cron.
 *
 * 1. Reenvia ao ERP todas as vendas marcadas como "pendente" (push).
 * 2. Atualiza o cadastro local de operadores a partir do ERP (pull).
 *
 * Vendas são o caminho crítico — falhas no pull de operadores não derrubam
 * o exit code, para não fazer o cron espalhar alertas por algo recuperável.
 *
 * Uso (cron, a cada 2 minutos):
 *     {asterisco}/2 {asterisco} {asterisco} {asterisco} {asterisco}  /usr/bin/php /var/www/pdv/sync/sync.php >> /var/www/pdv/logs/sync.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("sync.php só pode ser executado via CLI\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Services\OperadorSync;
use App\Services\VendaSync;

function log_console(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

log_console('--- Sync iniciado ---');

// 1) Vendas pendentes (caminho crítico — define o exit code do script)
$resultado = VendaSync::executar();

if ($resultado['total'] === 0) {
    log_console('Vendas: nada a sincronizar.');
} else {
    foreach ($resultado['detalhes'] as $d) {
        if ($d['sucesso']) {
            log_console("Venda {$d['venda_id']} → OK (pedido ERP: " . ($d['numero_pedido'] ?? 'sem número') . ')');
        } else {
            log_console("Venda {$d['venda_id']} → ERRO: {$d['erro']}");
        }
    }
    log_console("Vendas: {$resultado['ok']} enviada(s), {$resultado['falhas']} falha(s)");
}

// 2) Operadores (pull do ERP) — falha aqui não derruba o cron das vendas.
try {
    $op = OperadorSync::executar();
    if ($op['sucesso']) {
        if (($op['inseridos'] + $op['atualizados']) > 0) {
            log_console("Operadores: {$op['inseridos']} novo(s), {$op['atualizados']} atualizado(s)"
                . (($op['ignorados'] ?? 0) > 0 ? ", {$op['ignorados']} ignorado(s)" : ''));
        } else {
            log_console('Operadores: nada a atualizar.');
        }
    } else {
        log_console('Operadores: falha — ' . $op['mensagem']);
    }
} catch (\Throwable $e) {
    log_console('Operadores: exceção — ' . $e->getMessage());
}

log_console('--- Sync finalizado ---');
exit($resultado['falhas'] > 0 ? 1 : 0);

<?php
namespace App\Services;

use App\Helpers\Logger;
use App\Models\Comanda;

/**
 * Puxa do ERP a lista de comandas válidas para este PDV em /api/pdv/comandas?pdv=
 * e popula/atualiza a tabela local.
 *
 * O contrato esperado é flexível — aceita:
 *   { "comandas": [ { codigo, descricao, ... }, ... ] }
 *   { "data":     [ ... ] }
 *   [ ... ]   (array direto)
 *
 * Cada item precisa ter pelo menos `codigo` (ou `numero`). Campos opcionais:
 *   id          — vira erp_id
 *   descricao / nome / mesa — vira descrição local
 *   status      — 'aberta' (default) | 'finalizada'
 */
class ComandaSync
{
    public static function executar(): array
    {
        $endpoint = (string) \App\Helpers\Env::get('ENDPOINT_COMANDAS', '/comandas');
        $erp  = new ErpClient();
        $resp = $erp->get($endpoint);

        if ($resp['erro']) {
            return ['sucesso' => false, 'mensagem' => $resp['erro']];
        }

        $body  = $resp['body'];
        $lista = null;
        if (is_array($body)) {
            if (isset($body['comandas']) && is_array($body['comandas'])) {
                $lista = $body['comandas'];
            } elseif (isset($body['data']) && is_array($body['data'])) {
                $lista = $body['data'];
            } elseif (array_is_list($body)) {
                $lista = $body;
            }
        }

        if (!is_array($lista)) {
            return ['sucesso' => false, 'mensagem' => 'Formato inesperado da resposta de /comandas'];
        }

        $criadas = 0;
        $atualizadas = 0;
        foreach ($lista as $c) {
            if (!is_array($c)) continue;
            $codigo = (string)($c['codigo'] ?? $c['numero'] ?? '');
            if ($codigo === '') continue;

            $existia = Comanda::porCodigo($codigo) !== null;
            try {
                Comanda::upsertDoErp($c);
                $existia ? $atualizadas++ : $criadas++;
            } catch (\Throwable $e) {
                Logger::erro('Falha ao salvar comanda do ERP', [
                    'codigo' => $codigo,
                    'erro'   => $e->getMessage(),
                ]);
            }
        }

        $total = $criadas + $atualizadas;
        Logger::info('Sync de comandas concluída', [
            'total'       => $total,
            'criadas'     => $criadas,
            'atualizadas' => $atualizadas,
        ]);

        return [
            'sucesso'     => true,
            'mensagem'    => "Comandas sincronizadas: {$criadas} nova(s), {$atualizadas} atualizada(s)",
            'total'       => $total,
            'criadas'     => $criadas,
            'atualizadas' => $atualizadas,
        ];
    }
}

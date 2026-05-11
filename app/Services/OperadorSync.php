<?php
namespace App\Services;

use App\Helpers\Env;
use App\Helpers\Logger;
use App\Models\Operador;

/**
 * Sincronização de operadores: busca no ERP e faz upsert no banco local
 * (chave natural = usuario). Não apaga locais sem correspondente — eles
 * podem ser admins do PDV cadastrados manualmente.
 */
class OperadorSync
{
    public static function executar(): array
    {
        $endpoint = (string) Env::get('ENDPOINT_OPERADORES', '/operadores');
        $erp = new ErpClient();
        $resp = $erp->get($endpoint);

        if ($resp['erro']) {
            return ['sucesso' => false, 'mensagem' => $resp['erro']];
        }

        $body = $resp['body'];
        $lista = $body;
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            $lista = $body['data'];
        } elseif (is_array($body) && isset($body['operadores']) && is_array($body['operadores'])) {
            $lista = $body['operadores'];
        }

        if (!is_array($lista)) {
            return ['sucesso' => false, 'mensagem' => 'Formato inesperado da resposta do ERP'];
        }

        $inseridos = 0;
        $atualizados = 0;
        $ignorados = 0;

        foreach ($lista as $op) {
            if (!is_array($op)) { $ignorados++; continue; }
            $r = Operador::upsertDoErp($op);
            if      ($r === 'inserido')   $inseridos++;
            elseif  ($r === 'atualizado') $atualizados++;
            else                          $ignorados++;
        }

        Logger::info('Sync de operadores concluído', [
            'inseridos'   => $inseridos,
            'atualizados' => $atualizados,
            'ignorados'   => $ignorados,
        ]);

        $msg = "Operadores: {$inseridos} novo(s), {$atualizados} atualizado(s)";
        if ($ignorados > 0) $msg .= ", {$ignorados} ignorado(s)";

        return [
            'sucesso'     => true,
            'mensagem'    => $msg,
            'inseridos'   => $inseridos,
            'atualizados' => $atualizados,
            'ignorados'   => $ignorados,
        ];
    }
}

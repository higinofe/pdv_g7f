<?php
namespace App\Services;

use App\Helpers\Logger;
use App\Models\Venda;

/**
 * Orquestra a finalização da venda:
 *   1. Salva localmente no SQLite (sempre como "pendente").
 *   2. Tenta enviar ao ERP via VendaSync (que aplica o contrato B7F /venda).
 *   3. VendaSync atualiza o status para "finalizada" se o ERP responder OK.
 *
 * Centralizar o envio em VendaSync evita duplicar lógica de payload
 * entre a finalização imediata e o reenvio de pendentes.
 */
class VendaService
{
    public static function finalizar(array $cabecalho, array $itens): array
    {
        $cabecalho['status'] = 'pendente';
        $vendaId = Venda::criar($cabecalho, $itens);

        $resultado = VendaSync::executarUma($vendaId);

        if (!$resultado['sucesso']) {
            Logger::alerta('Venda salva offline (ERP indisponível)', [
                'venda_id' => $vendaId,
                'erro'     => $resultado['erro'] ?? null,
            ]);
            return [
                'sucesso'  => true,
                'offline'  => true,
                'venda_id' => $vendaId,
                'mensagem' => 'Venda salva localmente (ERP offline). Será sincronizada automaticamente.',
            ];
        }

        Logger::info('Venda finalizada e enviada ao ERP', [
            'venda_id'      => $vendaId,
            'numero_pedido' => $resultado['numero_pedido'] ?? null,
        ]);

        return [
            'sucesso'       => true,
            'offline'       => false,
            'venda_id'      => $vendaId,
            'numero_pedido' => $resultado['numero_pedido'] ?? null,
            'mensagem'      => 'Venda finalizada com sucesso',
        ];
    }
}

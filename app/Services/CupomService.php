<?php
namespace App\Services;

use App\Helpers\Env;
use App\Helpers\Logger;
use App\Models\Venda;

/**
 * Transmissão de cupom fiscal.
 * STUB: estrutura pronta para futura integração com SEFAZ.
 * Hoje: chama um endpoint genérico do ERP que devolve "transmitido".
 */
class CupomService
{
    public static function transmitir(int $vendaId): array
    {
        $venda = Venda::porId($vendaId);
        if (!$venda) {
            return ['sucesso' => false, 'mensagem' => 'Venda não encontrada'];
        }

        if ($venda['status'] === 'pendente') {
            return [
                'sucesso'  => false,
                'mensagem' => 'Finalize o pedido antes de transmitir o cupom',
            ];
        }

        if ($venda['status'] === 'cupom_transmitido') {
            return [
                'sucesso'  => true,
                'mensagem' => 'Cupom já transmitido anteriormente',
            ];
        }

        $endpoint = (string) Env::get('ENDPOINT_CUPOM', '/cupom-fiscal');
        $erp = new ErpClient();
        $resp = $erp->post($endpoint, [
            'venda_id'       => $vendaId,
            'numero_pedido'  => $venda['numero_pedido_erp'],
            'pdv_id'         => $venda['pdv_id'],
            'valor_total'    => (float) $venda['valor_total'],
            'itens'          => $venda['itens'],
        ]);

        if ($resp['erro']) {
            Logger::erro('Falha ao transmitir cupom', [
                'venda_id' => $vendaId,
                'erro'     => $resp['erro'],
            ]);
            return ['sucesso' => false, 'mensagem' => 'Falha na transmissão: ' . $resp['erro']];
        }

        Venda::atualizarStatus($vendaId, 'cupom_transmitido');
        Logger::info('Cupom transmitido', ['venda_id' => $vendaId]);

        return [
            'sucesso'  => true,
            'mensagem' => 'Cupom transmitido com sucesso',
            'retorno' => $resp['body'],
        ];
    }
}

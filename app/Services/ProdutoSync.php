<?php
namespace App\Services;

use App\Helpers\Env;
use App\Helpers\Logger;
use App\Models\Produto;

/**
 * Carga de produtos: busca no ERP e substitui o cache local.
 */
class ProdutoSync
{
    public static function executar(): array
    {
        $endpoint = (string) Env::get('ENDPOINT_PRODUTOS', '/carga');
        $erp = new ErpClient();
        $resp = $erp->get($endpoint);

        if ($resp['erro']) {
            return ['sucesso' => false, 'mensagem' => $resp['erro']];
        }

        $body = $resp['body'];

        // Formato B7F: { empresa, produtos, categorias, unidades, pessoas, gerado_em }
        // Também aceitamos array direto e variantes legadas { data:[…] } / { produtos:[…] }.
        $lista = null;
        if (is_array($body)) {
            if (isset($body['produtos']) && is_array($body['produtos'])) {
                $lista = $body['produtos'];
            } elseif (isset($body['data']) && is_array($body['data'])) {
                $lista = $body['data'];
            } elseif (array_is_list($body)) {
                $lista = $body;
            }
        }

        if (!is_array($lista)) {
            return ['sucesso' => false, 'mensagem' => 'Formato inesperado da resposta do ERP'];
        }

        $total = Produto::substituirCatalogo($lista);
        Logger::info('Carga de produtos concluída', ['total' => $total]);

        return [
            'sucesso'  => true,
            'mensagem' => "Carga concluída: {$total} produto(s) atualizado(s)",
            'total'    => $total,
        ];
    }
}

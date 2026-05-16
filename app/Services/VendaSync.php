<?php
namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Env;
use App\Helpers\Logger;
use App\Models\Venda;

/**
 * Reenvia ao ERP todas as vendas marcadas como "pendente".
 * Usado tanto pelo cron (sync/sync.php) quanto pelo endpoint HTTP
 * de disparo manual.
 */
class VendaSync
{
    /**
     * Processa todas as pendentes.
     *
     * @return array{
     *   total: int,
     *   ok: int,
     *   falhas: int,
     *   ultimo_erro: ?string,
     *   detalhes: array<int, array{venda_id:int, sucesso:bool, erro?:string, numero_pedido?:string}>
     * }
     */
    public static function executar(): array
    {
        $pendentes = Venda::pendentes();
        $total = count($pendentes);

        if ($total === 0) {
            return [
                'total'       => 0,
                'ok'          => 0,
                'falhas'      => 0,
                'ultimo_erro' => null,
                'detalhes'    => [],
            ];
        }

        $endpoint = (string) Env::get('ENDPOINT_VENDAS', '/venda');
        $erp = new ErpClient();

        $ok = 0;
        $falhas = 0;
        $ultimoErro = null;
        $detalhes = [];

        foreach ($pendentes as $venda) {
            $vendaId = (int) $venda['id'];
            $detalhe = Venda::porId($vendaId);
            if (!$detalhe) continue;

            $payload = self::montarPayload($detalhe, $vendaId);
            $resp = $erp->post($endpoint, $payload);

            if ($resp['erro']) {
                $falhas++;
                $ultimoErro = $resp['erro'];
                $detalhes[] = [
                    'venda_id' => $vendaId,
                    'sucesso'  => false,
                    'erro'     => $resp['erro'],
                ];
                Logger::alerta('Sync: falha ao reenviar venda', [
                    'venda_id' => $vendaId,
                    'erro'     => $resp['erro'],
                ]);
                continue;
            }

            $body = $resp['body'] ?? [];
            // ERP B7F devolve { success, message, data:{ order_id, numero, total, nfe_id } }
            $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
            $numeroPedido = $data['numero'] ?? $data['order_id'] ?? $body['numero_pedido'] ?? null;
            Venda::atualizarStatus(
                $vendaId,
                'finalizada',
                $numeroPedido !== null ? (string) $numeroPedido : null
            );

            $ok++;
            $detalhes[] = [
                'venda_id'      => $vendaId,
                'sucesso'       => true,
                'numero_pedido' => $numeroPedido !== null ? (string) $numeroPedido : null,
            ];
            Logger::info('Sync: venda enviada ao ERP', [
                'venda_id'      => $vendaId,
                'numero_pedido' => $numeroPedido,
            ]);
        }

        return [
            'total'       => $total,
            'ok'          => $ok,
            'falhas'      => $falhas,
            'ultimo_erro' => $ultimoErro,
            'detalhes'    => $detalhes,
        ];
    }

    /**
     * Tenta enviar uma única venda (a recém-criada) ao ERP.
     * Retorna ['sucesso' => bool, 'numero_pedido' => ?string, 'erro' => ?string].
     */
    public static function executarUma(int $vendaId): array
    {
        $detalhe = Venda::porId($vendaId);
        if (!$detalhe) {
            return ['sucesso' => false, 'erro' => 'Venda não encontrada'];
        }

        $endpoint = (string) Env::get('ENDPOINT_VENDAS', '/venda');
        $erp = new ErpClient();
        $resp = $erp->post($endpoint, self::montarPayload($detalhe, $vendaId));

        if ($resp['erro']) {
            return ['sucesso' => false, 'erro' => $resp['erro']];
        }

        $body = $resp['body'] ?? [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $numeroPedido = $data['numero'] ?? $data['order_id'] ?? $body['numero_pedido'] ?? null;

        Venda::atualizarStatus(
            $vendaId,
            'finalizada',
            $numeroPedido !== null ? (string) $numeroPedido : null
        );

        return [
            'sucesso'       => true,
            'numero_pedido' => $numeroPedido !== null ? (string) $numeroPedido : null,
        ];
    }

    /**
     * Monta o payload conforme contrato da API B7F:
     *   { tipo, forma_pagamento, person_id, observacoes, desconto, acrescimo,
     *     valor_pago, items:[{ product_id, descricao, quantidade, preco_unitario, desconto }] }
     */
    private static function montarPayload(array $detalhe, int $vendaId): array
    {
        $items = [];
        foreach ($detalhe['itens'] as $item) {
            $erpId = $item['produto_erp_id'] ?? null;

            // Se a venda foi gerada antes do erp_id existir no cache, tenta achar agora.
            if (!$erpId && !empty($item['produto_codigo'])) {
                $stmt = Database::pdo()->prepare(
                    'SELECT erp_id FROM produtos WHERE codigo = ? OR codigo_barras = ? LIMIT 1'
                );
                $stmt->execute([$item['produto_codigo'], $item['produto_codigo']]);
                $erpId = $stmt->fetchColumn() ?: null;
            }

            $items[] = [
                'product_id'      => $erpId !== null ? (int) $erpId : null,
                'descricao'       => (string) ($item['descricao'] ?? ''),
                'quantidade'      => (float)  ($item['quantidade'] ?? 0),
                'preco_unitario'  => (float)  ($item['preco_unitario'] ?? 0),
                'desconto'        => (float)  ($item['desconto'] ?? 0),
            ];
        }

        // Pagamento misto: o contrato B7F atual só aceita uma `forma_pagamento`
        // por pedido. Estratégia:
        //   - 1 pagamento: envia normalmente
        //   - >1 pagamento: envia a forma com MAIOR valor como "forma_pagamento"
        //     e detalha o split em `observacoes` (auditoria humana).
        $pagamentos = Venda::pagamentos($vendaId);
        $formaErp   = (string) ($detalhe['forma_pagamento'] ?? 'dinheiro');
        $detalhePag = '';
        if (count($pagamentos) > 1) {
            // Acha a forma dominante.
            usort($pagamentos, fn($a, $b) => $b['valor'] <=> $a['valor']);
            $formaErp = (string) $pagamentos[0]['forma'];
            $partes = [];
            foreach ($pagamentos as $p) {
                $partes[] = sprintf('%s=%.2f', $p['forma'], (float)$p['valor']);
            }
            $detalhePag = ' [pagamentos: ' . implode(', ', $partes) . ']';
        }

        // UUID guardado em observacoes para deduplicação caso o ERP receba a mesma venda.
        $obs = '[uuid:' . self::uuidPorVenda($vendaId) . ']' . $detalhePag;

        return [
            'tipo'            => 'balcao',
            'forma_pagamento' => $formaErp,
            'person_id'       => null,
            'observacoes'     => $obs,
            'desconto'        => (float) ($detalhe['valor_desconto'] ?? 0),
            'acrescimo'       => 0,
            'valor_pago'      => $detalhe['valor_recebido'] !== null ? (float) $detalhe['valor_recebido'] : (float) ($detalhe['valor_total'] ?? 0),
            'items'           => $items,
        ];
    }

    /** UUID v4 estável por venda local (determinístico via id+pdv para idempotência). */
    private static function uuidPorVenda(int $vendaId): string
    {
        $pdv = (string) Env::get('PDV_ID', '000');
        $bytes = hash('sha256', "pdv-{$pdv}-venda-{$vendaId}", true);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // versão 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variante
        $hex = bin2hex(substr($bytes, 0, 16));
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

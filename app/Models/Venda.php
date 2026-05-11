<?php
namespace App\Models;

use App\Helpers\Database;

/**
 * Venda — cabeçalho e itens armazenados no SQLite.
 * Status:
 *   - pendente          → ainda não enviada ao ERP (offline)
 *   - finalizada        → enviada e confirmada pelo ERP
 *   - cupom_transmitido → cupom fiscal já transmitido
 *   - cancelada         → venda cancelada
 */
class Venda
{
    /**
     * Cria a venda + itens em uma única transação.
     * Retorna o ID local da venda.
     */
    public static function criar(array $cabecalho, array $itens): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO vendas
                    (operador_id, pdv_id, sessao_id, forma_pagamento, valor_total, valor_desconto,
                     valor_recebido, valor_troco, status)
                 VALUES
                    (:operador_id, :pdv_id, :sessao_id, :forma, :total, :desc, :recebido, :troco, :status)'
            );
            $stmt->execute([
                ':operador_id' => $cabecalho['operador_id'] ?? null,
                ':pdv_id'      => $cabecalho['pdv_id'] ?? null,
                ':sessao_id'   => $cabecalho['sessao_id'] ?? null,
                ':forma'       => $cabecalho['forma_pagamento'] ?? null,
                ':total'       => (float)($cabecalho['valor_total'] ?? 0),
                ':desc'        => (float)($cabecalho['valor_desconto'] ?? 0),
                ':recebido'    => isset($cabecalho['valor_recebido']) ? (float)$cabecalho['valor_recebido'] : null,
                ':troco'       => isset($cabecalho['valor_troco'])    ? (float)$cabecalho['valor_troco']    : null,
                ':status'      => $cabecalho['status'] ?? 'pendente',
            ]);
            $vendaId = (int) $pdo->lastInsertId();

            $stmtItem = $pdo->prepare(
                'INSERT INTO venda_itens
                    (venda_id, produto_erp_id, produto_codigo, descricao, quantidade,
                     preco_unitario, desconto, subtotal)
                 VALUES
                    (:venda_id, :erp_id, :codigo, :descricao, :qtd, :preco, :desc, :sub)'
            );

            foreach ($itens as $item) {
                $qtd      = (float)($item['quantidade'] ?? 1);
                $preco    = (float)($item['preco_unitario'] ?? 0);
                $desconto = (float)($item['desconto'] ?? 0);
                $subtotal = isset($item['subtotal'])
                    ? (float)$item['subtotal']
                    : ($qtd * $preco) - $desconto;

                $stmtItem->execute([
                    ':venda_id'  => $vendaId,
                    ':erp_id'    => isset($item['produto_erp_id']) ? (int)$item['produto_erp_id'] : null,
                    ':codigo'    => (string)($item['produto_codigo'] ?? ''),
                    ':descricao' => (string)($item['descricao'] ?? ''),
                    ':qtd'       => $qtd,
                    ':preco'     => $preco,
                    ':desc'      => $desconto,
                    ':sub'       => $subtotal,
                ]);
            }

            $pdo->commit();
            return $vendaId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function porId(int $id): ?array
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM vendas WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $venda = $stmt->fetch();
        if (!$venda) return null;

        $stmt = $pdo->prepare('SELECT * FROM venda_itens WHERE venda_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $venda['itens'] = $stmt->fetchAll();
        return $venda;
    }

    public static function atualizarStatus(int $id, string $status, ?string $numeroPedidoErp = null): void
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare(
            "UPDATE vendas
             SET status = :status,
                 numero_pedido_erp = COALESCE(:num, numero_pedido_erp),
                 atualizado_em = datetime('now','localtime')
             WHERE id = :id"
        );
        $stmt->execute([
            ':status' => $status,
            ':num'    => $numeroPedidoErp,
            ':id'     => $id,
        ]);
    }

    /** Vendas pendentes (offline) que precisam ser enviadas ao ERP. */
    public static function pendentes(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM vendas WHERE status = 'pendente' ORDER BY id"
        );
        return $stmt->fetchAll();
    }
}

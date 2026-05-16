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
     * Cria a venda + itens + pagamentos em uma única transação.
     * Retorna o ID local da venda.
     *
     * O cabeçalho aceita:
     *  - 'pagamentos' => [['forma'=>x,'valor'=>y], ...]  (formato novo, pagamento misto)
     *  - 'forma_pagamento' => x (fallback p/ pagamento único, gera 1 linha em venda_pagamentos)
     *
     * `vendas.forma_pagamento` vira "misto" quando há mais de uma forma — quem
     * precisa do detalhe lê `venda_pagamentos`.
     */
    public static function criar(array $cabecalho, array $itens): int
    {
        $pagamentos = self::normalizarPagamentos($cabecalho);

        $somaPagamentos = 0.0;
        foreach ($pagamentos as $p) $somaPagamentos += (float)$p['valor'];

        $valorTotal = (float)($cabecalho['valor_total'] ?? 0);
        $formaConsolidada = count($pagamentos) > 1
            ? 'misto'
            : (string)($pagamentos[0]['forma'] ?? 'outros');

        // valor_recebido = soma dos pagamentos; valor_troco = excesso sobre o
        // total (só faz sentido quando há dinheiro no mix, mas guardamos do
        // mesmo jeito — quem consome decide se exibe).
        $valorRecebido = isset($cabecalho['valor_recebido'])
            ? (float)$cabecalho['valor_recebido']
            : $somaPagamentos;
        $valorTroco = isset($cabecalho['valor_troco'])
            ? (float)$cabecalho['valor_troco']
            : max(0.0, $somaPagamentos - $valorTotal);

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
                ':forma'       => $formaConsolidada,
                ':total'       => $valorTotal,
                ':desc'        => (float)($cabecalho['valor_desconto'] ?? 0),
                ':recebido'    => $valorRecebido,
                ':troco'       => $valorTroco,
                ':status'      => $cabecalho['status'] ?? 'pendente',
            ]);
            $vendaId = (int) $pdo->lastInsertId();

            $stmtPag = $pdo->prepare(
                'INSERT INTO venda_pagamentos (venda_id, forma, valor, ordem)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($pagamentos as $i => $p) {
                $stmtPag->execute([$vendaId, $p['forma'], (float)$p['valor'], $i]);
            }

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

    /**
     * Padroniza a lista de pagamentos a partir do cabeçalho recebido:
     *  - Se vier `pagamentos[]`: usa, filtrando formas inválidas e valores não-positivos.
     *  - Senão, se vier `forma_pagamento`: monta lista de 1 pagamento com valor_total.
     */
    public static function normalizarPagamentos(array $cabecalho): array
    {
        $formasOk = ['dinheiro', 'debito', 'credito', 'pix', 'outros'];
        $lista = [];

        if (!empty($cabecalho['pagamentos']) && is_array($cabecalho['pagamentos'])) {
            foreach ($cabecalho['pagamentos'] as $p) {
                if (!is_array($p)) continue;
                $forma = (string)($p['forma'] ?? '');
                $valor = (float)($p['valor'] ?? 0);
                if (!in_array($forma, $formasOk, true) || $valor <= 0) continue;
                $lista[] = ['forma' => $forma, 'valor' => $valor];
            }
        }

        if (empty($lista)) {
            $forma = (string)($cabecalho['forma_pagamento'] ?? 'outros');
            if (!in_array($forma, $formasOk, true)) $forma = 'outros';
            $valor = isset($cabecalho['valor_recebido']) && (float)$cabecalho['valor_recebido'] > 0
                ? (float)$cabecalho['valor_recebido']
                : (float)($cabecalho['valor_total'] ?? 0);
            $lista[] = ['forma' => $forma, 'valor' => $valor];
        }

        return $lista;
    }

    /** Lista os pagamentos de uma venda. */
    public static function pagamentos(int $vendaId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT forma, valor, ordem FROM venda_pagamentos WHERE venda_id = ? ORDER BY ordem, id'
        );
        $stmt->execute([$vendaId]);
        return $stmt->fetchAll();
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

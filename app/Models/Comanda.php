<?php
namespace App\Models;

use App\Helpers\Database;

/**
 * Comanda — pedido em aberto lançado no "terminal de comanda" (sem login)
 * e baixado em qualquer caixa quando o cliente vai pagar.
 *
 * Fluxo:
 *   1. ERP cadastra a comanda (mesa, ficha, cliente) → /api/pdv/comandas?pdv=
 *   2. PDV puxa a lista e popula a tabela `comandas` (status=aberta).
 *   3. Terminal de comanda bipa o código e adiciona itens via lancarItem().
 *   4. Qualquer caixa bipa o mesmo código → carrega os itens no carrinho.
 *   5. Ao finalizar a venda, a comanda é marcada como finalizada.
 */
class Comanda
{
    public static function porCodigo(string $codigo): ?array
    {
        $codigo = trim($codigo);
        if ($codigo === '') return null;

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM comandas WHERE codigo = ? LIMIT 1'
        );
        $stmt->execute([$codigo]);
        return $stmt->fetch() ?: null;
    }

    public static function porId(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM comandas WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Lista de comandas em aberto (para autocomplete / debug). */
    public static function listarAbertas(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT id, codigo, descricao, criado_em
               FROM comandas
              WHERE status = 'aberta'
              ORDER BY codigo ASC"
        );
        return $stmt->fetchAll();
    }

    /** Itens de uma comanda, na ordem em que foram lançados. */
    public static function itens(int $comandaId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM comanda_itens WHERE comanda_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$comandaId]);
        return $stmt->fetchAll();
    }

    public static function totalItens(int $comandaId): float
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(subtotal), 0) AS t FROM comanda_itens WHERE comanda_id = ?'
        );
        $stmt->execute([$comandaId]);
        return (float) $stmt->fetch()['t'];
    }

    /**
     * Cria (ou atualiza) uma comanda vinda do ERP. Usa o `codigo` como chave —
     * permite o ERP "ressincronizar" sem duplicar.
     */
    public static function upsertDoErp(array $dadosErp): int
    {
        $codigo    = (string)($dadosErp['codigo'] ?? $dadosErp['numero'] ?? '');
        $erpId     = isset($dadosErp['id']) ? (int) $dadosErp['id'] : null;
        // ERP B7F devolve `titulo`; outros sistemas usam descricao/nome/mesa.
        $descricao = (string)($dadosErp['titulo'] ?? $dadosErp['descricao'] ?? $dadosErp['nome'] ?? $dadosErp['mesa'] ?? '');
        $status    = (string)($dadosErp['status'] ?? 'aberta');

        if ($codigo === '') {
            throw new \InvalidArgumentException('Comanda do ERP sem código');
        }

        $pdo = Database::pdo();
        $existente = self::porCodigo($codigo);

        if ($existente) {
            // Não sobrescreve status local "finalizada" com "aberta" do ERP:
            // se já baixamos, mantemos baixada até o ERP reabrir explicitamente.
            $stmt = $pdo->prepare(
                'UPDATE comandas
                    SET erp_id          = COALESCE(?, erp_id),
                        descricao       = ?,
                        sincronizado_em = datetime("now","localtime"),
                        atualizado_em   = datetime("now","localtime")
                  WHERE id = ?'
            );
            $stmt->execute([$erpId, $descricao, (int)$existente['id']]);
            return (int) $existente['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO comandas (codigo, erp_id, descricao, status, sincronizado_em)
             VALUES (?, ?, ?, ?, datetime("now","localtime"))'
        );
        $stmt->execute([$codigo, $erpId, $descricao, $status]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Adiciona um item à comanda. Mantém um snapshot do produto para que
     * a baixa no caixa funcione mesmo se o catálogo mudar depois.
     */
    public static function lancarItem(int $comandaId, array $produto, float $quantidade, string $pdvOrigem): int
    {
        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('Quantidade inválida');
        }

        $preco    = (float)($produto['preco'] ?? 0);
        $subtotal = $preco * $quantidade;

        $stmt = Database::pdo()->prepare(
            'INSERT INTO comanda_itens
                (comanda_id, produto_codigo, produto_erp_id, descricao,
                 quantidade, preco_unitario, subtotal, pdv_origem)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $comandaId,
            (string)($produto['codigo'] ?? ''),
            isset($produto['erp_id']) ? (int)$produto['erp_id'] : null,
            (string)($produto['descricao'] ?? ''),
            $quantidade,
            $preco,
            $subtotal,
            $pdvOrigem,
        ]);

        Database::pdo()->prepare(
            'UPDATE comandas SET atualizado_em = datetime("now","localtime") WHERE id = ?'
        )->execute([$comandaId]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function removerItem(int $comandaId, int $itemId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM comanda_itens WHERE id = ? AND comanda_id = ?'
        );
        $stmt->execute([$itemId, $comandaId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Marca a comanda como finalizada (após baixa no caixa).
     * Idempotente — chamar de novo não muda nada.
     */
    public static function finalizar(int $comandaId, int $vendaId): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE comandas
                SET status = 'finalizada',
                    venda_id = ?,
                    atualizado_em = datetime('now','localtime')
              WHERE id = ? AND status = 'aberta'"
        );
        $stmt->execute([$vendaId, $comandaId]);
    }

    /** Apaga todos os itens lançados (usado se o operador "cancelar" a comanda no terminal). */
    public static function limparItens(int $comandaId): int
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM comanda_itens WHERE comanda_id = ?'
        );
        $stmt->execute([$comandaId]);
        return $stmt->rowCount();
    }
}

<?php
namespace App\Models;

use App\Helpers\Database;
use PDO;

/**
 * Produto — cache local do catálogo do ERP.
 */
class Produto
{
    /**
     * Busca por código OU código de barras.
     *
     * Estratégia:
     *  1. Match exato em `codigo` ou `codigo_barras` (ex.: bipe de EAN-13,
     *     ou código completo `0009274`).
     *  2. Se não achar, tenta sem zeros à esquerda em ambos os lados — o ERP
     *     costuma armazenar `codigo` com padding (`0009274`) mas o operador
     *     digitando manualmente lembra só `9274`. Só vale se houver UM
     *     candidato (evita falso match quando o `9274` "puro" também existe).
     */
    public static function porCodigoOuBarras(string $valor): ?array
    {
        $valor = trim($valor);
        if ($valor === '') return null;

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM produtos
             WHERE codigo = :v OR codigo_barras = :v
             LIMIT 1'
        );
        $stmt->execute([':v' => $valor]);
        $row = $stmt->fetch();
        if ($row) return $row;

        // Fallback sem zeros à esquerda — só dispara se o que veio for numérico
        // (evita comparar `0001A` com `1A` em códigos alfanuméricos). Comparamos
        // o input normalizado contra LTRIM dos campos do banco, então funciona
        // tanto pra "9274" quanto "00009274" digitados pelo operador.
        if (ctype_digit($valor)) {
            $semZeros = ltrim($valor, '0');
            if ($semZeros === '') $semZeros = '0';
            $stmt = $pdo->prepare(
                "SELECT * FROM produtos
                 WHERE LTRIM(codigo, '0')        = :v
                    OR LTRIM(codigo_barras, '0') = :v
                 LIMIT 2"
            );
            $stmt->execute([':v' => $semZeros]);
            $resultados = $stmt->fetchAll();
            // Só retorna se for único — múltiplos = ambíguo, melhor pedir o
            // código completo do que adicionar o produto errado.
            if (count($resultados) === 1) return $resultados[0];
        }

        return null;
    }

    /** Busca textual: por código, código de barras (LIKE) ou descrição (LIKE). */
    public static function pesquisar(string $termo, int $limite = 50): array
    {
        $termo = trim($termo);
        if ($termo === '') return [];

        $like = '%' . $termo . '%';
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM produtos
             WHERE codigo = :exato
                OR codigo_barras = :exato
                OR descricao    LIKE :like
                OR codigo       LIKE :like
                OR codigo_barras LIKE :like
             ORDER BY descricao ASC
             LIMIT :limite'
        );
        $stmt->bindValue(':exato',  $termo);
        $stmt->bindValue(':like',   $like);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Substitui o catálogo inteiro: limpa a tabela e insere todos os produtos.
     * Usa transação para performance e atomicidade.
     */
    public static function substituirCatalogo(array $produtos): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM produtos');

            $stmt = $pdo->prepare(
                'INSERT INTO produtos
                    (erp_id, codigo, codigo_barras, descricao, preco, unidade, estoque, ncm, categoria)
                 VALUES
                    (:erp_id, :codigo, :codigo_barras, :descricao, :preco, :unidade, :estoque, :ncm, :categoria)'
            );

            $total = 0;
            foreach ($produtos as $p) {
                // Aceita tanto o formato antigo (descricao/preco/estoque) quanto
                // o do ERP B7F (nome/preco_venda/estoque_atual + id).
                $descricao = (string)($p['descricao'] ?? $p['nome'] ?? '');
                $preco     = (float)($p['preco']     ?? $p['preco_venda']  ?? 0);
                $estoque   = (float)($p['estoque']   ?? $p['estoque_atual'] ?? 0);
                $codigo    = (string)($p['codigo']   ?? $p['id'] ?? '');
                $ativo     = $p['ativo'] ?? true;

                if ($codigo === '' || (isset($p['ativo']) && !$ativo)) {
                    continue; // produto sem código ou desativado: pula
                }

                $stmt->execute([
                    ':erp_id'        => isset($p['id']) ? (int)$p['id'] : null,
                    ':codigo'        => $codigo,
                    ':codigo_barras' => $p['codigo_barras'] ?? null,
                    ':descricao'     => $descricao,
                    ':preco'         => $preco,
                    ':unidade'       => $p['unidade'] ?? 'UN',
                    ':estoque'       => $estoque,
                    ':ncm'           => $p['ncm'] ?? null,
                    ':categoria'     => $p['categoria'] ?? null,
                ]);
                $total++;
            }

            $pdo->commit();
            return $total;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function totalCadastrado(): int
    {
        $stmt = Database::pdo()->query('SELECT COUNT(*) AS t FROM produtos');
        return (int) $stmt->fetch()['t'];
    }
}

<?php
namespace App\Models;

use App\Helpers\Database;

/**
 * Sessão de caixa (abertura/fechamento) e movimentos manuais (sangria/reforço).
 */
class Caixa
{
    /** Sessão atualmente aberta no PDV informado, ou null. */
    public static function sessaoAberta(string $pdvId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.*, o.nome AS operador_nome, o.usuario AS operador_usuario
               FROM sessoes_caixa s
               JOIN operadores o ON o.id = s.operador_id
              WHERE s.pdv_id = ? AND s.status = ?
              LIMIT 1'
        );
        $stmt->execute([$pdvId, 'aberta']);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function porId(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sessoes_caixa WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Cria uma nova sessão de caixa aberta. */
    public static function abrir(string $pdvId, int $operadorId, float $valorAbertura, ?string $observacao): int
    {
        if (self::sessaoAberta($pdvId)) {
            throw new \RuntimeException('Já existe um caixa aberto para este PDV');
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO sessoes_caixa (pdv_id, operador_id, valor_abertura, observacao)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$pdvId, $operadorId, $valorAbertura, $observacao]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Fecha a sessão informando o valor contado e calculando a diferença. */
    public static function fechar(int $sessaoId, ?float $valorInformado, ?string $observacao): array
    {
        $sessao = self::porId($sessaoId);
        if (!$sessao || $sessao['status'] !== 'aberta') {
            throw new \RuntimeException('Sessão de caixa não encontrada ou já fechada');
        }

        $tot = self::totais($sessaoId);
        $valorCalculado = $tot['saldo_dinheiro'];
        $diferenca = $valorInformado !== null ? ($valorInformado - $valorCalculado) : null;

        $stmt = Database::pdo()->prepare(
            'UPDATE sessoes_caixa
                SET status = ?, fechado_em = datetime("now","localtime"),
                    valor_fechamento = ?, valor_informado = ?, diferenca = ?,
                    observacao = COALESCE(?, observacao)
              WHERE id = ?'
        );
        $stmt->execute(['fechada', $valorCalculado, $valorInformado, $diferenca, $observacao, $sessaoId]);

        return array_merge($tot, [
            'sessao_id'        => $sessaoId,
            'valor_calculado'  => $valorCalculado,
            'valor_informado'  => $valorInformado,
            'diferenca'        => $diferenca,
        ]);
    }

    /** Registra sangria ou reforço. */
    public static function registrarMovimento(int $sessaoId, int $operadorId, string $tipo, float $valor, ?string $motivo): int
    {
        if (!in_array($tipo, ['sangria', 'reforco'], true)) {
            throw new \InvalidArgumentException('Tipo de movimento inválido');
        }
        if ($valor <= 0) {
            throw new \InvalidArgumentException('Valor do movimento deve ser maior que zero');
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO movimentos_caixa (sessao_id, operador_id, tipo, valor, motivo)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sessaoId, $operadorId, $tipo, $valor, $motivo]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Totais consolidados da sessão: vendas por forma de pagamento,
     * sangrias, reforços e saldo esperado em dinheiro.
     */
    public static function totais(int $sessaoId): array
    {
        $pdo = Database::pdo();
        $sessao = self::porId($sessaoId);
        if (!$sessao) {
            throw new \RuntimeException('Sessão não encontrada');
        }

        // Totais por forma de pagamento
        $stmt = $pdo->prepare(
            'SELECT forma_pagamento, COUNT(*) AS qtd, COALESCE(SUM(valor_total),0) AS total
               FROM vendas
              WHERE sessao_id = ?
              GROUP BY forma_pagamento'
        );
        $stmt->execute([$sessaoId]);
        $porForma = [];
        $totalVendas = 0.0;
        $qtdVendas   = 0;
        foreach ($stmt->fetchAll() as $r) {
            $forma = $r['forma_pagamento'] ?? 'outros';
            $porForma[$forma] = [
                'qtd'   => (int)   $r['qtd'],
                'total' => (float) $r['total'],
            ];
            $totalVendas += (float) $r['total'];
            $qtdVendas   += (int)   $r['qtd'];
        }

        // Sangrias e reforços
        $stmt = $pdo->prepare(
            'SELECT tipo, COALESCE(SUM(valor),0) AS total FROM movimentos_caixa
              WHERE sessao_id = ? GROUP BY tipo'
        );
        $stmt->execute([$sessaoId]);
        $sangria = 0.0;
        $reforco = 0.0;
        foreach ($stmt->fetchAll() as $r) {
            if ($r['tipo'] === 'sangria') $sangria = (float) $r['total'];
            if ($r['tipo'] === 'reforco') $reforco = (float) $r['total'];
        }

        $vendasDinheiro = $porForma['dinheiro']['total'] ?? 0.0;
        $saldoDinheiro  = (float)$sessao['valor_abertura'] + $vendasDinheiro + $reforco - $sangria;

        return [
            'abertura'        => (float) $sessao['valor_abertura'],
            'vendas_total'    => $totalVendas,
            'vendas_qtd'      => $qtdVendas,
            'por_forma'       => $porForma,
            'sangria'         => $sangria,
            'reforco'         => $reforco,
            'saldo_dinheiro'  => $saldoDinheiro,
        ];
    }

    /** Lista os movimentos de uma sessão (para relatório de fechamento). */
    public static function movimentos(int $sessaoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM movimentos_caixa WHERE sessao_id = ? ORDER BY criado_em ASC'
        );
        $stmt->execute([$sessaoId]);
        return $stmt->fetchAll();
    }
}

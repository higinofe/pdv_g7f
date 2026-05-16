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

    /**
     * Fecha a sessão informando o valor contado e calculando a diferença.
     * Também grava o operador que fechou + um snapshot JSON completo da sessão
     * (vendas, itens, pagamentos, movimentos, totais) em `dados_fechamento`
     * para auditoria e envio ao ERP.
     */
    public static function fechar(int $sessaoId, ?float $valorInformado, ?string $observacao, ?int $operadorFechamentoId = null): array
    {
        $sessao = self::porId($sessaoId);
        if (!$sessao || $sessao['status'] !== 'aberta') {
            throw new \RuntimeException('Sessão de caixa não encontrada ou já fechada');
        }

        $tot = self::totais($sessaoId);
        $valorCalculado = $tot['saldo_dinheiro'];
        $diferenca = $valorInformado !== null ? ($valorInformado - $valorCalculado) : null;

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE sessoes_caixa
                SET status = ?, fechado_em = datetime("now","localtime"),
                    valor_fechamento = ?, valor_informado = ?, diferenca = ?,
                    operador_fechamento_id = COALESCE(?, operador_fechamento_id),
                    observacao = COALESCE(?, observacao)
              WHERE id = ?'
        );
        $stmt->execute(['fechada', $valorCalculado, $valorInformado, $diferenca,
                        $operadorFechamentoId, $observacao, $sessaoId]);

        // Snapshot completo é gerado APÓS o UPDATE, para já vir com os campos
        // atualizados (fechado_em, diferenca, etc).
        $snapshot = self::snapshotFechamento($sessaoId);
        $stmt = $pdo->prepare('UPDATE sessoes_caixa SET dados_fechamento = ? WHERE id = ?');
        $stmt->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sessaoId]);

        return array_merge($tot, [
            'sessao_id'        => $sessaoId,
            'valor_calculado'  => $valorCalculado,
            'valor_informado'  => $valorInformado,
            'diferenca'        => $diferenca,
            'snapshot'         => $snapshot,
        ]);
    }

    /**
     * Arredondamento monetário para 2 casas, modo HALF_UP. Usado para todos
     * os campos que vão pra coluna DECIMAL(x,2) no ERP — preço unitário pode
     * vir com mais casas do cadastro (ex.: 36.9664), mas total/subtotal/
     * pagamento têm que bater com a casa de centavos pra não estourar.
     * null entra/sai como null (campos opcionais).
     */
    private static function dinheiro(?float $v): ?float
    {
        return $v === null ? null : round($v, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Snapshot completo de uma sessão para auditoria/envio ao ERP.
     * Estrutura estável — é o contrato com o endpoint de fechamentos do ERP.
     */
    public static function snapshotFechamento(int $sessaoId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT s.*,
                    oa.usuario AS op_abertura_usuario, oa.nome AS op_abertura_nome, oa.erp_id AS op_abertura_erp_id,
                    of.usuario AS op_fechamento_usuario, of.nome AS op_fechamento_nome, of.erp_id AS op_fechamento_erp_id
               FROM sessoes_caixa s
               LEFT JOIN operadores oa ON oa.id = s.operador_id
               LEFT JOIN operadores of ON of.id = s.operador_fechamento_id
              WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$sessaoId]);
        $sessao = $stmt->fetch();
        if (!$sessao) throw new \RuntimeException('Sessão não encontrada para snapshot');

        // Normaliza todos os valores monetários do bloco totais para 2 casas —
        // mesmo motivo de DECIMAL no ERP. `por_forma` é dict de {qtd, total}.
        $totais = self::totais($sessaoId);
        $totais['abertura']       = self::dinheiro((float) $totais['abertura']);
        $totais['vendas_total']   = self::dinheiro((float) $totais['vendas_total']);
        $totais['sangria']        = self::dinheiro((float) $totais['sangria']);
        $totais['reforco']        = self::dinheiro((float) $totais['reforco']);
        $totais['saldo_dinheiro'] = self::dinheiro((float) $totais['saldo_dinheiro']);
        foreach ($totais['por_forma'] as $f => $info) {
            $totais['por_forma'][$f]['total'] = self::dinheiro((float) $info['total']);
        }

        // Duração em minutos (best-effort — datetime SQLite vem como string local).
        $duracaoMin = null;
        if ($sessao['aberto_em'] && $sessao['fechado_em']) {
            $aberto  = strtotime($sessao['aberto_em']);
            $fechado = strtotime($sessao['fechado_em']);
            if ($aberto && $fechado) $duracaoMin = max(0, (int) round(($fechado - $aberto) / 60));
        }

        // Vendas da sessão com itens e pagamentos
        $stmt = $pdo->prepare(
            'SELECT v.id, v.numero_pedido_erp, v.forma_pagamento, v.valor_total, v.valor_desconto,
                    v.valor_recebido, v.valor_troco, v.status, v.criado_em, v.atualizado_em,
                    v.operador_id,
                    op.usuario AS operador_usuario, op.nome AS operador_nome, op.erp_id AS operador_erp_id
               FROM vendas v
               LEFT JOIN operadores op ON op.id = v.operador_id
              WHERE v.sessao_id = ?
              ORDER BY v.id'
        );
        $stmt->execute([$sessaoId]);
        $vendasRows = $stmt->fetchAll();

        $stmtItens = $pdo->prepare(
            'SELECT produto_codigo, produto_erp_id, descricao, quantidade,
                    preco_unitario, desconto, subtotal
               FROM venda_itens WHERE venda_id = ? ORDER BY id'
        );
        $stmtPag = $pdo->prepare(
            'SELECT forma, valor, ordem FROM venda_pagamentos WHERE venda_id = ? ORDER BY ordem, id'
        );

        $vendas = [];
        foreach ($vendasRows as $v) {
            $stmtItens->execute([$v['id']]);
            $itens = $stmtItens->fetchAll();
            $stmtPag->execute([$v['id']]);
            $pagamentos = $stmtPag->fetchAll();

            // numero_pedido_erp vinha como string ("8"). Convertendo p/ int quando
            // numérico — o ERP costuma esperar tipo inteiro nessa coluna.
            $numeroPedido = $v['numero_pedido_erp'];
            if ($numeroPedido !== null && ctype_digit((string) $numeroPedido)) {
                $numeroPedido = (int) $numeroPedido;
            }
            $vendas[] = [
                'id_local'           => (int) $v['id'],
                'numero_pedido_erp'  => $numeroPedido,
                'forma_pagamento'    => $v['forma_pagamento'],
                'valor_total'        => self::dinheiro((float) $v['valor_total']),
                'valor_desconto'     => self::dinheiro((float) $v['valor_desconto']),
                'valor_recebido'     => self::dinheiro(isset($v['valor_recebido']) ? (float) $v['valor_recebido'] : null),
                'valor_troco'        => self::dinheiro(isset($v['valor_troco'])    ? (float) $v['valor_troco']    : null),
                'status'             => $v['status'],
                'criado_em'          => $v['criado_em'],
                'atualizado_em'      => $v['atualizado_em'],
                'operador'           => [
                    'id_local' => (int) $v['operador_id'],
                    'erp_id'   => $v['operador_erp_id'] !== null ? (int) $v['operador_erp_id'] : null,
                    'usuario'  => $v['operador_usuario'],
                    'nome'     => $v['operador_nome'],
                ],
                'pagamentos'         => array_map(fn($p) => [
                    'forma' => $p['forma'],
                    'valor' => self::dinheiro((float) $p['valor']),
                    'ordem' => (int) $p['ordem'],
                ], $pagamentos),
                'itens'              => array_map(fn($it) => [
                    'produto_codigo' => $it['produto_codigo'],
                    'produto_erp_id' => $it['produto_erp_id'] !== null ? (int) $it['produto_erp_id'] : null,
                    'descricao'      => $it['descricao'],
                    'quantidade'     => (float) $it['quantidade'],
                    // preco_unitario tem fluxo decimal maior (cadastro do ERP);
                    // mantido como veio. Só os totais monetários são fixados em 2 casas.
                    'preco_unitario' => (float) $it['preco_unitario'],
                    'desconto'       => self::dinheiro((float) $it['desconto']),
                    'subtotal'       => self::dinheiro((float) $it['subtotal']),
                ], $itens),
            ];
        }

        // Movimentos (sangrias e reforços) com operador
        $stmt = $pdo->prepare(
            'SELECT m.tipo, m.valor, m.motivo, m.criado_em,
                    m.operador_id, op.usuario AS operador_usuario, op.nome AS operador_nome, op.erp_id AS operador_erp_id
               FROM movimentos_caixa m
               LEFT JOIN operadores op ON op.id = m.operador_id
              WHERE m.sessao_id = ?
              ORDER BY m.criado_em, m.id'
        );
        $stmt->execute([$sessaoId]);
        $movimentos = [];
        foreach ($stmt->fetchAll() as $m) {
            $movimentos[] = [
                'tipo'      => $m['tipo'],
                'valor'     => self::dinheiro((float) $m['valor']),
                'motivo'    => $m['motivo'],
                'criado_em' => $m['criado_em'],
                'operador'  => [
                    'id_local' => (int) $m['operador_id'],
                    'erp_id'   => $m['operador_erp_id'] !== null ? (int) $m['operador_erp_id'] : null,
                    'usuario'  => $m['operador_usuario'],
                    'nome'     => $m['operador_nome'],
                ],
            ];
        }

        return [
            'uuid'              => self::uuidPorSessao($sessaoId, (string) $sessao['pdv_id']),
            'pdv_id'            => $sessao['pdv_id'],
            'pdv_nome'          => (string) \App\Helpers\Env::get('PDV_NOME', ''),
            'sessao' => [
                'id_local'              => (int) $sessao['id'],
                'aberto_em'             => $sessao['aberto_em'],
                'fechado_em'            => $sessao['fechado_em'],
                'duracao_minutos'       => $duracaoMin,
                'valor_abertura'        => self::dinheiro((float) $sessao['valor_abertura']),
                'valor_calculado'       => self::dinheiro(isset($sessao['valor_fechamento']) ? (float) $sessao['valor_fechamento'] : null),
                'valor_informado'       => self::dinheiro(isset($sessao['valor_informado'])  ? (float) $sessao['valor_informado']  : null),
                'diferenca'             => self::dinheiro(isset($sessao['diferenca'])        ? (float) $sessao['diferenca']        : null),
                'observacao'            => $sessao['observacao'],
                'status'                => $sessao['status'],
            ],
            'operador_abertura' => [
                'id_local' => (int) $sessao['operador_id'],
                'erp_id'   => $sessao['op_abertura_erp_id'] !== null ? (int) $sessao['op_abertura_erp_id'] : null,
                'usuario'  => $sessao['op_abertura_usuario'],
                'nome'     => $sessao['op_abertura_nome'],
            ],
            'operador_fechamento' => $sessao['operador_fechamento_id'] ? [
                'id_local' => (int) $sessao['operador_fechamento_id'],
                'erp_id'   => $sessao['op_fechamento_erp_id'] !== null ? (int) $sessao['op_fechamento_erp_id'] : null,
                'usuario'  => $sessao['op_fechamento_usuario'],
                'nome'     => $sessao['op_fechamento_nome'],
            ] : null,
            'totais'     => $totais,
            'vendas'     => $vendas,
            'movimentos' => $movimentos,
        ];
    }

    /** UUID v4 estável por sessão (idempotência no ERP). */
    public static function uuidPorSessao(int $sessaoId, string $pdvId): string
    {
        $bytes = hash('sha256', "pdv-{$pdvId}-fechamento-{$sessaoId}", true);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex(substr($bytes, 0, 16));
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /** Fechamentos que ainda não foram aceitos pelo ERP. */
    public static function fechamentosPendentesEnvio(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT id, pdv_id, fechado_em, tentativas_envio, ultimo_erro_envio
               FROM sessoes_caixa
              WHERE status = 'fechada'
                AND dados_fechamento IS NOT NULL
                AND enviado_erp_em IS NULL
              ORDER BY fechado_em"
        );
        return $stmt->fetchAll();
    }

    /** Marca um fechamento como enviado ao ERP. */
    public static function marcarFechamentoEnviado(int $sessaoId, ?string $numeroErp): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE sessoes_caixa
                SET enviado_erp_em       = datetime("now","localtime"),
                    numero_fechamento_erp = COALESCE(?, numero_fechamento_erp),
                    ultimo_erro_envio    = NULL
              WHERE id = ?'
        );
        $stmt->execute([$numeroErp, $sessaoId]);
    }

    /** Registra uma falha de envio (contador + último erro). */
    public static function registrarFalhaEnvio(int $sessaoId, string $erro): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE sessoes_caixa
                SET tentativas_envio   = COALESCE(tentativas_envio,0) + 1,
                    ultimo_erro_envio  = ?
              WHERE id = ?'
        );
        $stmt->execute([$erro, $sessaoId]);
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

        // Totais POR FORMA — agora vêm de venda_pagamentos (pagamento misto).
        // qtd = nº de vendas distintas em que aquela forma aparece (uma venda
        // que mistura crédito + dinheiro conta 1 em cada). total = soma dos
        // valores naquela forma.
        $stmt = $pdo->prepare(
            'SELECT vp.forma                    AS forma,
                    COUNT(DISTINCT vp.venda_id) AS qtd,
                    COALESCE(SUM(vp.valor), 0)  AS total
               FROM venda_pagamentos vp
               JOIN vendas v ON v.id = vp.venda_id
              WHERE v.sessao_id = ?
              GROUP BY vp.forma'
        );
        $stmt->execute([$sessaoId]);
        // Inicializa as 5 formas com zero — o ERP exige que todas as chaves
        // (`dinheiro`, `debito`, `credito`, `pix`, `outros`) venham presentes
        // mesmo sem movimento, ou rejeita o payload com 422. Antes só
        // populávamos as formas que tiveram venda, e o resto vinha ausente.
        $porForma = [
            'dinheiro' => ['qtd' => 0, 'total' => 0.0],
            'debito'   => ['qtd' => 0, 'total' => 0.0],
            'credito'  => ['qtd' => 0, 'total' => 0.0],
            'pix'      => ['qtd' => 0, 'total' => 0.0],
            'outros'   => ['qtd' => 0, 'total' => 0.0],
        ];
        foreach ($stmt->fetchAll() as $r) {
            $forma = $r['forma'] ?? 'outros';
            if (!isset($porForma[$forma])) $forma = 'outros';
            $porForma[$forma] = [
                'qtd'   => (int)   $r['qtd'],
                'total' => (float) $r['total'],
            ];
        }

        // Total geral lê do cabeçalho de vendas (valor_total reflete itens +
        // descontos), independente de qual forma cobriu. Quantidade = vendas
        // distintas na sessão. Mantém o relatório de fechamento batendo com
        // a etiqueta "Total faturado".
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_total), 0) AS total
               FROM vendas
              WHERE sessao_id = ?'
        );
        $stmt->execute([$sessaoId]);
        $linhaTotais = $stmt->fetch() ?: ['qtd' => 0, 'total' => 0];
        $qtdVendas   = (int)   $linhaTotais['qtd'];
        $totalVendas = (float) $linhaTotais['total'];

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

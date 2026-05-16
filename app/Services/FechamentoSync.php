<?php
namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Env;
use App\Helpers\Logger;
use App\Models\Caixa;

/**
 * Envia ao ERP os fechamentos de caixa pendentes.
 * Equivalente a VendaSync, mas para sessões fechadas — payload é o snapshot
 * completo gravado em `sessoes_caixa.dados_fechamento`.
 *
 * Endpoint esperado no ERP: POST /api/pdv/fechamentos?pdv=<nome>
 * Idempotência: campo `uuid` no payload (derivado do id local + pdv_id).
 */
class FechamentoSync
{
    /** Tenta enviar uma sessão específica. Retorna detalhe pro caller agir. */
    public static function executarUma(int $sessaoId): array
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, dados_fechamento, enviado_erp_em
               FROM sessoes_caixa
              WHERE id = ? AND status = ?'
        );
        $stmt->execute([$sessaoId, 'fechada']);
        $row = $stmt->fetch();

        if (!$row) {
            return ['sucesso' => false, 'erro' => 'Sessão não encontrada ou ainda aberta'];
        }
        if ($row['enviado_erp_em']) {
            return ['sucesso' => true, 'mensagem' => 'já enviada anteriormente', 'reenvio' => false];
        }
        // Regenera o snapshot a cada tentativa — assim correções no formato
        // (ex.: novas chaves obrigatórias no contrato do ERP) entram em vigor
        // sem precisar reabrir e refechar o caixa. Também atualiza o dado
        // armazenado para auditoria pontual.
        $payload = Caixa::snapshotFechamento($sessaoId);
        $pdo->prepare('UPDATE sessoes_caixa SET dados_fechamento = ? WHERE id = ?')
            ->execute([
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $sessaoId,
            ]);

        $endpoint = (string) Env::get('ENDPOINT_FECHAMENTOS', '/fechamentos');
        $erp = new ErpClient();
        $resp = $erp->post($endpoint, $payload);

        if ($resp['erro']) {
            Caixa::registrarFalhaEnvio($sessaoId, $resp['erro']);
            Logger::alerta('FechamentoSync: falha', ['sessao_id' => $sessaoId, 'erro' => $resp['erro']]);
            return ['sucesso' => false, 'erro' => $resp['erro']];
        }

        $body   = $resp['body'] ?? [];
        $data   = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $numero = $data['numero_fechamento'] ?? $data['numero'] ?? $data['id'] ?? null;

        Caixa::marcarFechamentoEnviado($sessaoId, $numero !== null ? (string) $numero : null);
        Logger::info('FechamentoSync: enviado ao ERP', [
            'sessao_id' => $sessaoId,
            'numero'    => $numero,
        ]);

        return [
            'sucesso'           => true,
            'numero_fechamento' => $numero !== null ? (string) $numero : null,
        ];
    }

    /** Envia todos os fechamentos pendentes. */
    public static function executar(): array
    {
        $pendentes = Caixa::fechamentosPendentesEnvio();
        $total = count($pendentes);
        if ($total === 0) {
            return ['total' => 0, 'ok' => 0, 'falhas' => 0, 'detalhes' => []];
        }

        $ok = 0; $falhas = 0; $ultimoErro = null; $detalhes = [];
        foreach ($pendentes as $p) {
            $r = self::executarUma((int) $p['id']);
            if (!empty($r['sucesso'])) { $ok++; }
            else { $falhas++; $ultimoErro = $r['erro'] ?? null; }
            $detalhes[] = ['sessao_id' => (int) $p['id']] + $r;
        }

        return [
            'total'       => $total,
            'ok'          => $ok,
            'falhas'      => $falhas,
            'ultimo_erro' => $ultimoErro,
            'detalhes'    => $detalhes,
        ];
    }
}

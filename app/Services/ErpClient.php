<?php
namespace App\Services;

use App\Helpers\Env;
use App\Helpers\Logger;

/**
 * Cliente HTTP para a API do ERP (cURL).
 * Encapsula autenticação por Bearer token e o query param `?pdv=<nome>` exigidos
 * por todas as rotas /api/pdv/* do ERP B7F.
 *
 * Para fluxos de "testar conexão" / "salvar configuração", aceita overrides
 * de baseUrl, token e nome do PDV via construtor.
 */
class ErpClient
{
    private string $baseUrl;
    private string $token;
    private string $pdvNome;
    private int    $timeout;

    public function __construct(?string $tokenOverride = null, ?string $pdvOverride = null, ?string $baseUrlOverride = null)
    {
        $this->baseUrl = rtrim((string) ($baseUrlOverride ?? Env::get('API_URL', '')), '/');
        $this->token   = (string) ($tokenOverride ?? Env::get('API_TOKEN', ''));
        $this->pdvNome = (string) ($pdvOverride   ?? Env::get('PDV_NOME', ''));
        $this->timeout = (int) Env::get('HTTP_TIMEOUT', 30);
    }

    public function get(string $endpoint, array $query = []): array
    {
        return $this->executar('GET', $this->montarUrl($endpoint, $query));
    }

    public function post(string $endpoint, array $body = []): array
    {
        return $this->executar('POST', $this->montarUrl($endpoint), $body);
    }

    /**
     * Anexa o ?pdv=<nome> exigido pela API B7F e quaisquer outros params.
     * Se já existir `pdv` no $query, respeita o que veio (caller pode forçar outro).
     */
    private function montarUrl(string $endpoint, array $query = []): string
    {
        $url = $this->baseUrl . $endpoint;

        if ($this->pdvNome !== '' && !array_key_exists('pdv', $query)) {
            $query = ['pdv' => $this->pdvNome] + $query;
        }
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $url;
    }

    /** Faz a chamada e devolve ['status' => int, 'body' => array|null, 'erro' => ?string]. */
    private function executar(string $metodo, string $url, ?array $body = null): array
    {
        if ($this->baseUrl === '') {
            return ['status' => 0, 'body' => null, 'erro' => 'API_URL não configurada'];
        }

        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $resposta = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false) {
            Logger::erro('Falha cURL no ERP', ['url' => $url, 'erro' => $erroCurl]);
            return ['status' => 0, 'body' => null, 'erro' => $erroCurl ?: 'Sem conexão com o ERP'];
        }

        $json = json_decode($resposta, true);

        if ($status >= 400) {
            $msgErp = is_array($json) && !empty($json['error']) ? (string) $json['error'] : null;
            Logger::erro('Erro HTTP do ERP', ['url' => $url, 'status' => $status, 'body' => $resposta]);
            return [
                'status' => $status,
                'body'   => $json,
                'erro'   => $msgErp ?: 'ERP retornou HTTP ' . $status,
            ];
        }

        return ['status' => $status, 'body' => $json, 'erro' => null];
    }

    /**
     * Doc B7F manda usar /carga p/ teste de conexão. Filtramos com data futura
     * pra resposta voltar enxuta (o ERP devolve só metadados se não há nada novo).
     */
    public function testarConexao(): array
    {
        return $this->get('/carga', ['atualizado_apos' => '2099-01-01T00:00:00Z']);
    }

    /**
     * Ping curto usado pelo polling de status (online/offline).
     * Não loga falha — é chamado a cada poucos segundos.
     */
    public function pingRapido(int $timeoutSegundos = 3): bool
    {
        if ($this->baseUrl === '') return false;

        $ch = curl_init();
        $headers = ['Accept: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->montarUrl('/operadores'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => $timeoutSegundos,
            CURLOPT_TIMEOUT        => $timeoutSegundos,
        ]);

        $resposta = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 200 = ok ; 401/403 = problema de auth (mas servidor respondeu) — ainda online
        return $resposta !== false && $status > 0 && $status < 500;
    }

    /**
     * POST multipart/form-data — usado quando precisar enviar XML da NF-e/NFC-e.
     * `$campos` aceita strings e arrays do tipo ['file' => '/caminho.xml'].
     */
    public function postMultipart(string $endpoint, array $campos): array
    {
        if ($this->baseUrl === '') {
            return ['status' => 0, 'body' => null, 'erro' => 'API_URL não configurada'];
        }
        $url = $this->montarUrl($endpoint);

        $postFields = [];
        foreach ($campos as $k => $v) {
            if (is_array($v) && isset($v['file']) && is_file($v['file'])) {
                $postFields[$k] = new \CURLFile($v['file'], $v['mime'] ?? 'application/xml', basename($v['file']));
            } else {
                $postFields[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
            }
        }

        $ch = curl_init();
        $headers = ['Accept: application/json'];
        if ($this->token !== '') $headers[] = 'Authorization: Bearer ' . $this->token;

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);

        $resposta = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false) {
            Logger::erro('Falha cURL multipart no ERP', ['url' => $url, 'erro' => $erroCurl]);
            return ['status' => 0, 'body' => null, 'erro' => $erroCurl ?: 'Sem conexão com o ERP'];
        }
        $json = json_decode($resposta, true);
        if ($status >= 400) {
            $msgErp = is_array($json) && !empty($json['error']) ? (string) $json['error'] : null;
            Logger::erro('Erro HTTP do ERP (multipart)', ['url' => $url, 'status' => $status, 'body' => $resposta]);
            return [
                'status' => $status,
                'body'   => $json,
                'erro'   => $msgErp ?: 'ERP retornou HTTP ' . $status,
            ];
        }
        return ['status' => $status, 'body' => $json, 'erro' => null];
    }
}

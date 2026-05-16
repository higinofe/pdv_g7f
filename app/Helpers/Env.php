<?php
namespace App\Helpers;

/**
 * Leitor simples de arquivo .env (não usa pacote externo).
 * Carrega o arquivo uma única vez e mantém em memória.
 */
class Env
{
    private static array $vars = [];
    private static bool  $loaded = false;
    private static string $path = '';

    /** Carrega o .env (idempotente). */
    public static function load(string $path = null): void
    {
        if (self::$loaded) return;

        self::$path = $path ?? __DIR__ . '/../../config/.env';
        if (!is_file(self::$path)) {
            self::$loaded = true;
            return;
        }

        $linhas = file(self::$path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#')) continue;
            if (!str_contains($linha, '=')) continue;
            [$chave, $valor] = explode('=', $linha, 2);
            self::$vars[trim($chave)] = trim($valor);
        }
        self::$loaded = true;
    }

    /** Retorna o valor da variável, ou o default se não existir. */
    public static function get(string $chave, $default = null)
    {
        self::load();
        return self::$vars[$chave] ?? $default;
    }

    /** Define/atualiza valor em memória e persiste no arquivo. */
    public static function set(string $chave, string $valor): void
    {
        self::load();
        self::$vars[$chave] = $valor;
        self::salvar();
    }

    /**
     * Aplica overrides da tabela `configuracoes` (chaves com prefixo `env.`).
     * Chamado uma vez após Database::pdo() ficar pronto no bootstrap. Os
     * valores do DB vencem o .env — assim a UI consegue mudar API_URL/TOKEN
     * sem precisar escrever no arquivo (que costuma ser read-only no host).
     */
    public static function aplicarOverrides(): void
    {
        self::load();
        try {
            $pdo  = \App\Helpers\Database::pdo();
            $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'env.%'");
            foreach ($stmt->fetchAll() as $row) {
                $chave = substr($row['chave'], 4); // remove 'env.'
                if ($chave !== '') {
                    self::$vars[$chave] = (string) $row['valor'];
                }
            }
        } catch (\Throwable $e) {
            // Banco ainda não disponível em alguns contextos (instalador, CLI
            // de teste etc.) — segue só com o .env, sem quebrar.
        }
    }

    /** Persiste um override no banco (sem tocar o .env). Lê tabela `configuracoes`. */
    public static function setOverride(string $chave, string $valor): void
    {
        \App\Models\Configuracao::set('env.' . $chave, $valor);
        self::$vars[$chave] = $valor;
    }

    /** Remove o override do banco (volta a valer o .env). */
    public static function limparOverride(string $chave): void
    {
        $pdo = \App\Helpers\Database::pdo();
        $pdo->prepare('DELETE FROM configuracoes WHERE chave = ?')
            ->execute(['env.' . $chave]);
        // recarrega valor do .env em memória
        self::$loaded = false;
        self::$vars = [];
        self::load();
        self::aplicarOverrides();
    }

    /** Reescreve o .env preservando comentários quando possível. */
    private static function salvar(): void
    {
        $linhas = is_file(self::$path)
            ? file(self::$path, FILE_IGNORE_NEW_LINES)
            : [];

        $atualizadas = [];
        $vistas = [];

        foreach ($linhas as $linha) {
            $trim = trim($linha);
            if ($trim === '' || str_starts_with($trim, '#') || !str_contains($trim, '=')) {
                $atualizadas[] = $linha;
                continue;
            }
            [$chave] = explode('=', $trim, 2);
            $chave = trim($chave);
            if (array_key_exists($chave, self::$vars)) {
                $atualizadas[] = $chave . '=' . self::$vars[$chave];
                $vistas[$chave] = true;
            } else {
                $atualizadas[] = $linha;
            }
        }

        // Adiciona chaves que ainda não existiam no arquivo
        foreach (self::$vars as $chave => $valor) {
            if (!isset($vistas[$chave])) {
                $atualizadas[] = $chave . '=' . $valor;
            }
        }

        file_put_contents(self::$path, implode(PHP_EOL, $atualizadas) . PHP_EOL);
    }
}

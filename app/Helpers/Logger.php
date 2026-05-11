<?php
namespace App\Helpers;

/**
 * Logger simples: grava em logs/app.log e na tabela 'logs' do SQLite.
 */
class Logger
{
    private static string $arquivo = __DIR__ . '/../../logs/app.log';

    public static function info(string $msg, array $contexto = []): void
    {
        self::escrever('INFO', $msg, $contexto);
    }

    public static function erro(string $msg, array $contexto = []): void
    {
        self::escrever('ERRO', $msg, $contexto);
    }

    public static function alerta(string $msg, array $contexto = []): void
    {
        self::escrever('ALERTA', $msg, $contexto);
    }

    private static function escrever(string $nivel, string $msg, array $contexto): void
    {
        $linha = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $nivel,
            $msg,
            $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents(self::$arquivo, $linha, FILE_APPEND);

        // Também grava no banco se possível (não falhar caso ainda não exista)
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO logs (nivel, mensagem, contexto) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $nivel,
                $msg,
                $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            // ignora — log em arquivo já foi escrito
        }
    }
}

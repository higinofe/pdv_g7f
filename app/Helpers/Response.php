<?php
namespace App\Helpers;

/**
 * Padroniza a resposta JSON dos endpoints internos.
 */
class Response
{
    public static function json(array $dados, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(array $dados = []): void
    {
        self::json(array_merge(['sucesso' => true], $dados));
    }

    public static function erro(string $mensagem, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['sucesso' => false, 'erro' => $mensagem], $extra), $status);
    }
}

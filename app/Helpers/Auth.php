<?php
namespace App\Helpers;

/**
 * Sessão e autenticação do operador.
 */
class Auth
{
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function logar(array $operador): void
    {
        self::iniciar();
        $_SESSION['operador'] = [
            'id'      => (int) $operador['id'],
            'usuario' => $operador['usuario'],
            'nome'    => $operador['nome'],
            'perfil'  => $operador['perfil'] ?? 'operador',
        ];
    }

    public static function logado(): bool
    {
        self::iniciar();
        return !empty($_SESSION['operador']);
    }

    public static function operador(): ?array
    {
        self::iniciar();
        return $_SESSION['operador'] ?? null;
    }

    public static function deslogar(): void
    {
        self::iniciar();
        $_SESSION = [];
        session_destroy();
    }

    /** Exige login para endpoints internos da API. */
    public static function exigirLogin(): void
    {
        if (!self::logado()) {
            Response::erro('Não autenticado', 401);
        }
    }
}

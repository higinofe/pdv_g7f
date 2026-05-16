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
        // Garante que a elevação não sobreviva à troca de operador, mesmo
        // que o session_destroy falhe (handler customizado etc.).
        unset($_SESSION['admin_elev_ate']);
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

    // -----------------------------------------------------------------------
    // Elevação de privilégio admin sob demanda
    //
    // Permite que um operador comum (perfil != admin) execute uma ação de
    // administrador sem trocar a sessão: o supervisor digita usuário/senha
    // de admin no terminal, recebe uma janela curta de autorização e a ação
    // é liberada. Funciona junto com Auth::exigirAdmin() nos endpoints.
    // -----------------------------------------------------------------------

    /** Concede elevação de admin pela duração informada (segundos). */
    public static function concederAdmin(int $segundos = 300): void
    {
        self::iniciar();
        $_SESSION['admin_elev_ate'] = time() + max(1, $segundos);
    }

    /** Revoga a elevação de admin (chamado em logout e após uso pontual). */
    public static function revogarAdmin(): void
    {
        self::iniciar();
        unset($_SESSION['admin_elev_ate']);
    }

    /**
     * True se o operador logado é admin OU se há uma elevação válida na
     * sessão. Não há refresh automático aqui — o caller é responsável por
     * renovar via concederAdmin() quando quiser estender a janela.
     */
    public static function adminAutorizado(): bool
    {
        self::iniciar();
        $op = $_SESSION['operador'] ?? null;
        if ($op && ($op['perfil'] ?? '') === 'admin') return true;
        $ate = (int) ($_SESSION['admin_elev_ate'] ?? 0);
        return $ate > time();
    }

    /** Exige privilégio admin (perfil ou elevação ativa) — usado nos endpoints. */
    public static function exigirAdmin(): void
    {
        self::exigirLogin();
        if (!self::adminAutorizado()) {
            Response::erro('Autorização de administrador requerida', 403);
        }
    }
}

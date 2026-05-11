<?php
namespace App\Models;

use App\Helpers\Database;
use PDO;

/**
 * Operador (caixa) — usuário que faz login no PDV.
 */
class Operador
{
    /** Busca operador ativo por usuário OU e-mail (case-insensitive). */
    public static function porUsuario(string $usuario): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM operadores
             WHERE (usuario = :v OR LOWER(email) = LOWER(:v))
               AND ativo = 1
             LIMIT 1'
        );
        $stmt->execute([':v' => $usuario]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Busca por e-mail (qualquer status). Usado pelo upsert do ERP. */
    public static function porEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM operadores WHERE LOWER(email) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function porId(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM operadores WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Verifica login local: retorna o operador (sem hash) ou null. */
    public static function autenticar(string $usuario, string $senha): ?array
    {
        $op = self::porUsuario($usuario);
        if (!$op) return null;
        if (empty($op['senha_hash'])) return null;
        if (!password_verify($senha, $op['senha_hash'])) return null;

        unset($op['senha_hash']);
        return $op;
    }

    /** Atualiza o hash da senha local (usado após login online OK no ERP). */
    public static function gravarHashSenha(int $id, string $senha): void
    {
        $stmt = Database::pdo()->prepare('UPDATE operadores SET senha_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($senha, PASSWORD_BCRYPT), $id]);
    }

    /** Lista todos os operadores (para o cadastro). */
    public static function listar(): array
    {
        $rows = Database::pdo()->query(
            'SELECT id, usuario, nome, perfil, ativo, criado_em
               FROM operadores ORDER BY ativo DESC, nome ASC'
        )->fetchAll();
        return $rows ?: [];
    }

    /**
     * Cria ou atualiza um operador.
     * - Se id=null → INSERT (senha obrigatória).
     * - Se id≠null → UPDATE (senha opcional; vazio mantém a atual).
     */
    public static function salvar(?int $id, string $usuario, string $nome, string $perfil, bool $ativo, ?string $senha): int
    {
        $usuario = trim($usuario);
        $nome    = trim($nome);
        if ($usuario === '' || $nome === '') {
            throw new \InvalidArgumentException('Usuário e nome são obrigatórios');
        }
        if (!in_array($perfil, ['admin', 'operador'], true)) {
            throw new \InvalidArgumentException('Perfil inválido');
        }

        $pdo = Database::pdo();

        if ($id === null) {
            if (!$senha || strlen($senha) < 4) {
                throw new \InvalidArgumentException('Senha precisa ter ao menos 4 caracteres');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO operadores (usuario, senha_hash, nome, perfil, ativo)
                 VALUES (?, ?, ?, ?, ?)'
            );
            try {
                $stmt->execute([$usuario, password_hash($senha, PASSWORD_BCRYPT), $nome, $perfil, $ativo ? 1 : 0]);
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    throw new \RuntimeException('Já existe um operador com este usuário');
                }
                throw $e;
            }
            return (int) $pdo->lastInsertId();
        }

        $sets = ['usuario = ?', 'nome = ?', 'perfil = ?', 'ativo = ?'];
        $args = [$usuario, $nome, $perfil, $ativo ? 1 : 0];
        if ($senha !== null && $senha !== '') {
            if (strlen($senha) < 4) {
                throw new \InvalidArgumentException('Senha precisa ter ao menos 4 caracteres');
            }
            $sets[] = 'senha_hash = ?';
            $args[] = password_hash($senha, PASSWORD_BCRYPT);
        }
        $args[] = $id;

        $sql = 'UPDATE operadores SET ' . implode(', ', $sets) . ' WHERE id = ?';
        try {
            $pdo->prepare($sql)->execute($args);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new \RuntimeException('Já existe um operador com este usuário');
            }
            throw $e;
        }
        return $id;
    }

    /** Desativa o operador (não excluímos para preservar histórico de vendas). */
    public static function desativar(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE operadores SET ativo = 0 WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Upsert vindo do ERP B7F (chave natural: e-mail).
     *
     * O ERP NÃO devolve senha (por segurança). O hash local fica em branco
     * até o operador fazer login online — daí gravamos o hash e ele passa
     * a poder usar o PDV offline também.
     *
     * @return string 'inserido' | 'atualizado' | 'ignorado'
     */
    public static function upsertDoErp(array $dados): string
    {
        // O ERP B7F devolve `email` em /operadores/login mas `usuario` no /operadores;
        // aceitamos ambos como identificador único (login).
        $login = trim((string)($dados['email'] ?? $dados['usuario'] ?? ''));
        $email = trim((string)($dados['email'] ?? ''));
        $nome  = trim((string)($dados['nome'] ?? ''));
        if ($login === '' || $nome === '') return 'ignorado';

        // ERP usa slugs como "caixa" / "atendente"; mapeamos para o nosso modelo
        // (admin / operador). Apenas o admin do PDV é local — ERP entrega operadores.
        $perfilErp = strtolower((string)($dados['perfil'] ?? 'operador'));
        $perfil = in_array($perfilErp, ['admin', 'administrador', 'gestor'], true) ? 'admin' : 'operador';
        $ativo  = isset($dados['ativo']) ? ((int)(bool)$dados['ativo']) : 1;
        $erpId  = isset($dados['id']) ? (int)$dados['id'] : null;

        $pdo = Database::pdo();
        // Procura primeiro por erp_id (mais confiável), depois por email, depois por usuario.
        $existente = null;
        if ($erpId !== null) {
            $stmt = $pdo->prepare('SELECT * FROM operadores WHERE erp_id = ? LIMIT 1');
            $stmt->execute([$erpId]);
            $existente = $stmt->fetch() ?: null;
        }
        if (!$existente && $email !== '') {
            $existente = self::porEmail($email);
        }
        if (!$existente) {
            $stmt = $pdo->prepare('SELECT * FROM operadores WHERE usuario = ? LIMIT 1');
            $stmt->execute([$login]);
            $existente = $stmt->fetch() ?: null;
        }

        if (!$existente) {
            $placeholder = '$2y$10$' . bin2hex(random_bytes(11));
            $usuario = self::gerarUsuarioUnico($pdo, $login);
            $stmt = $pdo->prepare(
                'INSERT INTO operadores (usuario, email, erp_id, senha_hash, nome, perfil, ativo)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$usuario, $email !== '' ? $email : null, $erpId, $placeholder, $nome, $perfil, $ativo]);
            return 'inserido';
        }

        $stmt = $pdo->prepare(
            'UPDATE operadores
                SET email = COALESCE(NULLIF(?, ""), email),
                    erp_id = COALESCE(?, erp_id),
                    nome = ?, perfil = ?, ativo = ?
              WHERE id = ?'
        );
        $stmt->execute([$email, $erpId, $nome, $perfil, $ativo, (int)$existente['id']]);
        return 'atualizado';
    }

    /**
     * Garante um valor único na coluna `usuario` (UNIQUE NOT NULL no schema).
     * Aceita o login (e-mail ou usuário) e devolve uma versão sanitizada e única.
     */
    private static function gerarUsuarioUnico(PDO $pdo, string $login): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9._-]/i', '', strstr($login, '@', true) ?: $login));
        if ($base === '') $base = 'op';
        $tentativa = $base;
        $i = 1;
        $stmt = $pdo->prepare('SELECT 1 FROM operadores WHERE usuario = ?');
        while (true) {
            $stmt->execute([$tentativa]);
            if (!$stmt->fetchColumn()) return $tentativa;
            $tentativa = $base . $i++;
        }
    }
}

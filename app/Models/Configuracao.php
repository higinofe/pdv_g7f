<?php
namespace App\Models;

use App\Helpers\Database;

/**
 * Acesso chave/valor à tabela `configuracoes`.
 * Hoje hospeda só o hash de senha de admin, mas a interface é genérica.
 */
class Configuracao
{
    public static function get(string $chave): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $stmt->execute([$chave]);
        $valor = $stmt->fetchColumn();
        return $valor === false ? null : (string) $valor;
    }

    public static function set(string $chave, string $valor): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO configuracoes (chave, valor, atualizado_em)
             VALUES (?, ?, datetime("now","localtime"))
             ON CONFLICT(chave) DO UPDATE SET
                 valor = excluded.valor,
                 atualizado_em = excluded.atualizado_em'
        );
        $stmt->execute([$chave, $valor]);
    }
}

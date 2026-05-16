<?php
namespace App\Services;

use App\Helpers\Env;
use App\Models\Configuracao;

/**
 * Senha de admin usada para autorizar ações sensíveis (sincronizar
 * operadores, abrir cadastro, etc.) sem trocar a sessão do operador.
 *
 * Política de storage (decidida com o usuário):
 *   - Fonte de verdade: hash bcrypt em `configuracoes.admin_senha_hash`.
 *   - Bootstrap: na primeira leitura, se a config estiver vazia, semeamos
 *     a partir de ENV `ADMIN_PASSWORD` (texto plano só serve como seed).
 *   - Troca: gravada como novo hash em DB; o `.env` não é tocado em runtime.
 *
 * Para resetar pela primeira vez (ex.: esqueceu a senha), basta apagar a
 * linha `admin_senha_hash` em `configuracoes` — a próxima validação re-semeia
 * com o valor atual de `.env`.
 */
class AdminSenha
{
    private const CHAVE = 'admin_senha_hash';

    /** Retorna o hash atual, semeando do .env se ainda não houver. */
    public static function obterHash(): string
    {
        $hash = Configuracao::get(self::CHAVE);
        if ($hash !== null && $hash !== '') return $hash;

        $padrao = (string) Env::get('ADMIN_PASSWORD', 'admin');
        if ($padrao === '') $padrao = 'admin'; // último fallback defensivo
        $hash = password_hash($padrao, PASSWORD_DEFAULT);
        Configuracao::set(self::CHAVE, $hash);
        return $hash;
    }

    /** True se a senha informada bate com o hash atual. */
    public static function validar(string $senha): bool
    {
        if ($senha === '') return false;
        return password_verify($senha, self::obterHash());
    }

    /**
     * Troca a senha, exigindo a anterior. Retorna true em sucesso.
     * False se a senha atual não conferiu OU a nova senha for inválida.
     */
    public static function trocar(string $senhaAtual, string $senhaNova): bool
    {
        if (!self::validar($senhaAtual)) return false;
        if (strlen($senhaNova) < 4)      return false;
        Configuracao::set(self::CHAVE, password_hash($senhaNova, PASSWORD_DEFAULT));
        return true;
    }
}

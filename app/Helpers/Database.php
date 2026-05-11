<?php
namespace App\Helpers;

use PDO;

/**
 * Conexão singleton com o SQLite.
 * Cria o arquivo, aplica schema e rodas migrações idempotentes.
 */
class Database
{
    private static ?PDO $instancia = null;

    public static function pdo(): PDO
    {
        if (self::$instancia instanceof PDO) {
            return self::$instancia;
        }

        $config = require __DIR__ . '/../../config/database.php';
        $arquivo = $config['database'];

        if (!is_dir(dirname($arquivo))) {
            mkdir(dirname($arquivo), 0775, true);
        }

        $pdo = new PDO('sqlite:' . $arquivo, null, null, $config['options']);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        self::$instancia = $pdo;

        // 1. Migrações em tabelas pré-existentes (precisa rodar antes do schema
        //    porque o schema cria índices em colunas que talvez ainda não existam).
        self::aplicarMigracoes($pdo);

        // 2. Schema idempotente: cria tabelas e índices que ainda não existirem.
        $sql = file_get_contents(__DIR__ . '/../../database/schema.sql');
        $pdo->exec($sql);

        // 3. Seeds: operadores padrão e dados de teste.
        self::aplicarSeeds($pdo);

        return self::$instancia;
    }

    /** Adiciona colunas em tabelas pré-existentes, se ainda não tiverem. */
    private static function aplicarMigracoes(PDO $pdo): void
    {
        if (self::tabelaExiste($pdo, 'operadores') && !self::colunaExiste($pdo, 'operadores', 'perfil')) {
            $pdo->exec("ALTER TABLE operadores ADD COLUMN perfil TEXT NOT NULL DEFAULT 'operador'");
            $pdo->exec("UPDATE operadores SET perfil = 'admin' WHERE usuario = 'admin'");
        }
        if (self::tabelaExiste($pdo, 'vendas') && !self::colunaExiste($pdo, 'vendas', 'sessao_id')) {
            $pdo->exec('ALTER TABLE vendas ADD COLUMN sessao_id INTEGER');
        }
        // Integração com ERP B7F — id remoto e e-mail do operador
        if (self::tabelaExiste($pdo, 'operadores') && !self::colunaExiste($pdo, 'operadores', 'email')) {
            $pdo->exec('ALTER TABLE operadores ADD COLUMN email TEXT');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_operadores_email ON operadores (email)');
        }
        if (self::tabelaExiste($pdo, 'operadores') && !self::colunaExiste($pdo, 'operadores', 'erp_id')) {
            $pdo->exec('ALTER TABLE operadores ADD COLUMN erp_id INTEGER');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_operadores_erp_id ON operadores (erp_id)');
        }
        if (self::tabelaExiste($pdo, 'produtos') && !self::colunaExiste($pdo, 'produtos', 'erp_id')) {
            $pdo->exec('ALTER TABLE produtos ADD COLUMN erp_id INTEGER');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_produtos_erp_id ON produtos (erp_id)');
        }
        if (self::tabelaExiste($pdo, 'venda_itens') && !self::colunaExiste($pdo, 'venda_itens', 'produto_erp_id')) {
            $pdo->exec('ALTER TABLE venda_itens ADD COLUMN produto_erp_id INTEGER');
        }
    }

    private static function tabelaExiste(PDO $pdo, string $tabela): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$tabela]);
        return (bool) $stmt->fetchColumn();
    }

    private static function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool
    {
        $stmt = $pdo->query("PRAGMA table_info({$tabela})");
        foreach ($stmt->fetchAll() as $col) {
            if (($col['name'] ?? '') === $coluna) return true;
        }
        return false;
    }

    /** Cria operador admin padrão e produtos de teste se o banco estiver vazio. */
    private static function aplicarSeeds(PDO $pdo): void
    {
        // Operadores padrão (idempotente — não sobrescreve senhas existentes)
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO operadores (usuario, senha_hash, nome, perfil, ativo)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute(['admin',  password_hash('admin',  PASSWORD_BCRYPT), 'Administrador',     'admin']);
        $stmt->execute(['caixa1', password_hash('123456', PASSWORD_BCRYPT), 'Operador Caixa 1',  'operador']);

        // Produtos teste — só populados se a tabela estiver vazia (primeira inicialização).
        // Marcamos categoria '__seed__' para que a UI saiba que é placeholder e
        // dispare uma carga real do ERP no primeiro login.
        $qtdProd = (int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn();
        if ($qtdProd === 0) {
            $produtos = [
                ['001', '7891000100103', 'COCA-COLA 2L',                   12.90, 'UN', 'Bebidas'],
                ['002', '7891000100110', 'GUARANA ANTARCTICA 2L',          10.50, 'UN', 'Bebidas'],
                ['003', '7891000100127', 'AGUA MINERAL 500ML',              3.00, 'UN', 'Bebidas'],
                ['004', '7891000100134', 'CERVEJA HEINEKEN LONG NECK',      9.90, 'UN', 'Bebidas'],
                ['005', '7891000200103', 'PAO FRANCES KG',                 18.90, 'KG', 'Padaria'],
                ['006', '7891000200110', 'PAO DE QUEIJO 1KG',              28.50, 'UN', 'Padaria'],
                ['007', '7891000300103', 'LEITE INTEGRAL 1L',               5.49, 'UN', 'Mercearia'],
                ['008', '7891000300110', 'ARROZ TIPO 1 5KG',               29.90, 'UN', 'Mercearia'],
                ['009', '7891000300127', 'FEIJAO CARIOCA 1KG',              8.90, 'UN', 'Mercearia'],
                ['010', '7891000300134', 'OLEO DE SOJA 900ML',              7.49, 'UN', 'Mercearia'],
                ['011', '7891000300141', 'ACUCAR REFINADO 1KG',             4.99, 'UN', 'Mercearia'],
                ['012', '7891000300158', 'CAFE TORRADO 500G',              19.90, 'UN', 'Mercearia'],
                ['013', '7891000400103', 'BANANA PRATA KG',                 6.90, 'KG', 'Hortifruti'],
                ['014', '7891000400110', 'TOMATE KG',                       9.90, 'KG', 'Hortifruti'],
                ['015', '7891000400127', 'BATATA INGLESA KG',               5.49, 'KG', 'Hortifruti'],
                ['016', '7891000500103', 'SABONETE NEUTRO 90G',             2.49, 'UN', 'Higiene'],
                ['017', '7891000500110', 'PAPEL HIGIENICO 12X',            18.90, 'UN', 'Higiene'],
                ['018', '7891000500127', 'CREME DENTAL 90G',                6.90, 'UN', 'Higiene'],
                ['019', '7891000600103', 'DETERGENTE 500ML',                3.49, 'UN', 'Limpeza'],
                ['020', '7891000600110', 'AGUA SANITARIA 1L',               4.90, 'UN', 'Limpeza'],
            ];
            $stmt = $pdo->prepare(
                'INSERT INTO produtos (codigo, codigo_barras, descricao, preco, unidade, categoria, estoque)
                 VALUES (?, ?, ?, ?, ?, ?, 100)'
            );
            foreach ($produtos as $p) {
                $stmt->execute($p);
            }
        }
    }
}

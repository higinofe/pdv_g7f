-- Schema do banco SQLite local do PDV
-- Executado pelo bootstrap quando o banco ainda não existe

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

-- Operadores que podem fazer login no PDV
CREATE TABLE IF NOT EXISTS operadores (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario      TEXT NOT NULL UNIQUE,
    senha_hash   TEXT NOT NULL,
    nome         TEXT NOT NULL,
    perfil       TEXT NOT NULL DEFAULT 'operador',  -- admin | operador
    ativo        INTEGER NOT NULL DEFAULT 1,
    criado_em    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Cache local do catálogo de produtos (recarregado pela "Carga de Produtos")
CREATE TABLE IF NOT EXISTS produtos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo          TEXT NOT NULL,
    codigo_barras   TEXT,
    descricao       TEXT NOT NULL,
    preco           REAL NOT NULL DEFAULT 0,
    unidade         TEXT DEFAULT 'UN',
    estoque         REAL DEFAULT 0,
    ncm             TEXT,
    categoria       TEXT,
    atualizado_em   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE INDEX IF NOT EXISTS idx_produtos_codigo        ON produtos (codigo);
CREATE INDEX IF NOT EXISTS idx_produtos_codigo_barras ON produtos (codigo_barras);
CREATE INDEX IF NOT EXISTS idx_produtos_descricao    ON produtos (descricao);

-- Sessões de caixa: cada abertura gera uma linha; só pode haver uma "aberta" por PDV
CREATE TABLE IF NOT EXISTS sessoes_caixa (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    pdv_id                TEXT NOT NULL,
    operador_id           INTEGER NOT NULL,                   -- quem abriu
    operador_fechamento_id INTEGER,                            -- quem fechou (pode ser outro)
    valor_abertura        REAL NOT NULL DEFAULT 0,
    valor_fechamento      REAL,
    valor_informado       REAL,
    diferenca             REAL,
    observacao            TEXT,
    aberto_em             TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    fechado_em            TEXT,
    status                TEXT NOT NULL DEFAULT 'aberta',     -- aberta | fechada
    -- Histórico completo do fechamento (JSON) gerado por Caixa::snapshotFechamento.
    -- Inclui vendas, itens, pagamentos, movimentos, operadores e totais. Mantido
    -- aqui (e não computado on-the-fly no envio) para garantir auditoria pontual.
    dados_fechamento      TEXT,
    -- Envio do fechamento ao ERP
    enviado_erp_em        TEXT,
    numero_fechamento_erp TEXT,
    tentativas_envio      INTEGER NOT NULL DEFAULT 0,
    ultimo_erro_envio     TEXT,
    FOREIGN KEY (operador_id)            REFERENCES operadores(id),
    FOREIGN KEY (operador_fechamento_id) REFERENCES operadores(id)
);

CREATE INDEX IF NOT EXISTS idx_sessoes_status ON sessoes_caixa (pdv_id, status);

-- Configurações persistentes do PDV (chave/valor). Usada hoje só para o hash
-- da senha de admin (autorização de ações sensíveis). Mantida genérica
-- para outras configs futuras sem precisar de nova migração.
CREATE TABLE IF NOT EXISTS configuracoes (
    chave         TEXT PRIMARY KEY,
    valor         TEXT NOT NULL,
    atualizado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Garantia em nível de banco: só pode existir UMA sessão com status='aberta'
-- por PDV. Qualquer tentativa de criar uma segunda sessão "aberta" enquanto
-- a anterior não foi fechada (via caixa_fechar.php) viola o índice e falha.
-- Funciona em conjunto com Caixa::abrir(), que já checa antes do INSERT.
CREATE UNIQUE INDEX IF NOT EXISTS uniq_sessao_aberta_por_pdv
    ON sessoes_caixa (pdv_id) WHERE status = 'aberta';

-- Movimentos manuais (sangria / reforço) dentro da sessão
CREATE TABLE IF NOT EXISTS movimentos_caixa (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sessao_id    INTEGER NOT NULL,
    operador_id  INTEGER NOT NULL,
    tipo         TEXT NOT NULL,          -- sangria | reforco
    valor        REAL NOT NULL,
    motivo       TEXT,
    criado_em    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (sessao_id) REFERENCES sessoes_caixa(id) ON DELETE CASCADE,
    FOREIGN KEY (operador_id) REFERENCES operadores(id)
);

CREATE INDEX IF NOT EXISTS idx_mov_sessao ON movimentos_caixa (sessao_id);

-- Vendas finalizadas (cabeçalho)
CREATE TABLE IF NOT EXISTS vendas (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    operador_id        INTEGER,
    pdv_id             TEXT,
    sessao_id          INTEGER,
    numero_pedido_erp  TEXT,                 -- preenchido após resposta do ERP
    forma_pagamento    TEXT,                 -- dinheiro, debito, credito, pix, outros
    valor_total        REAL NOT NULL DEFAULT 0,
    valor_desconto     REAL NOT NULL DEFAULT 0,
    valor_recebido     REAL,
    valor_troco        REAL,
    -- Status: pendente (offline), finalizada (enviada ao ERP), cupom_transmitido
    status             TEXT NOT NULL DEFAULT 'pendente',
    criado_em          TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    atualizado_em      TEXT,
    FOREIGN KEY (operador_id) REFERENCES operadores(id),
    FOREIGN KEY (sessao_id)   REFERENCES sessoes_caixa(id)
);

CREATE INDEX IF NOT EXISTS idx_vendas_status    ON vendas (status);
CREATE INDEX IF NOT EXISTS idx_vendas_criado_em ON vendas (criado_em);
CREATE INDEX IF NOT EXISTS idx_vendas_sessao    ON vendas (sessao_id);

-- Itens de cada venda
CREATE TABLE IF NOT EXISTS venda_itens (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    venda_id        INTEGER NOT NULL,
    produto_codigo  TEXT NOT NULL,
    descricao       TEXT NOT NULL,
    quantidade      REAL NOT NULL DEFAULT 1,
    preco_unitario  REAL NOT NULL DEFAULT 0,
    desconto        REAL NOT NULL DEFAULT 0,
    subtotal        REAL NOT NULL DEFAULT 0,
    FOREIGN KEY (venda_id) REFERENCES vendas(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_venda_itens_venda ON venda_itens (venda_id);

-- Pagamentos de cada venda (pagamento misto). 1 venda → N formas.
-- vendas.forma_pagamento continua existindo: "misto" quando a venda usa mais
-- de uma forma, ou a única forma quando é só uma. Quem precisa de detalhe
-- (fechamento de caixa) lê daqui.
CREATE TABLE IF NOT EXISTS venda_pagamentos (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    venda_id  INTEGER NOT NULL,
    forma     TEXT NOT NULL,                 -- dinheiro | debito | credito | pix | outros
    valor     REAL NOT NULL,
    ordem     INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (venda_id) REFERENCES vendas(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_venda_pagamentos_venda ON venda_pagamentos (venda_id);
CREATE INDEX IF NOT EXISTS idx_venda_pagamentos_forma ON venda_pagamentos (forma);

-- Comandas: pedido em aberto que pode ser lançado num "terminal de comanda"
-- (sem login) e depois baixado em qualquer caixa. A lista de comandas
-- "válidas" vem do ERP via /api/pdv/comandas?pdv= ; os itens são lançados
-- localmente até a baixa no caixa.
CREATE TABLE IF NOT EXISTS comandas (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo          TEXT NOT NULL UNIQUE,    -- código que será bipado (ex.: C001, número da mesa, etc.)
    erp_id          INTEGER,                 -- id remoto, se vier do ERP
    descricao       TEXT,                    -- ex.: "Mesa 01", "Cliente João"
    status          TEXT NOT NULL DEFAULT 'aberta', -- aberta | finalizada
    pdv_origem      TEXT,                    -- PDV que lançou (terminal de comanda)
    venda_id        INTEGER,                 -- venda que baixou a comanda (após finalizar no caixa)
    sincronizado_em TEXT,                    -- última sync com ERP
    criado_em       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    atualizado_em   TEXT,
    FOREIGN KEY (venda_id) REFERENCES vendas(id)
);

CREATE INDEX IF NOT EXISTS idx_comandas_status ON comandas (status);
CREATE INDEX IF NOT EXISTS idx_comandas_codigo ON comandas (codigo);

-- Itens lançados em cada comanda
CREATE TABLE IF NOT EXISTS comanda_itens (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    comanda_id      INTEGER NOT NULL,
    produto_codigo  TEXT NOT NULL,
    produto_erp_id  INTEGER,
    descricao       TEXT NOT NULL,
    quantidade      REAL NOT NULL DEFAULT 1,
    preco_unitario  REAL NOT NULL DEFAULT 0,
    subtotal        REAL NOT NULL DEFAULT 0,
    pdv_origem      TEXT,
    criado_em       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (comanda_id) REFERENCES comandas(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_comanda_itens_comanda ON comanda_itens (comanda_id);

-- Log simples de eventos (carga, sync, erros)
CREATE TABLE IF NOT EXISTS logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    nivel      TEXT NOT NULL,
    mensagem   TEXT NOT NULL,
    contexto   TEXT,
    criado_em  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

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
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    pdv_id            TEXT NOT NULL,
    operador_id       INTEGER NOT NULL,
    valor_abertura    REAL NOT NULL DEFAULT 0,
    valor_fechamento  REAL,
    valor_informado   REAL,
    diferenca         REAL,
    observacao        TEXT,
    aberto_em         TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    fechado_em        TEXT,
    status            TEXT NOT NULL DEFAULT 'aberta', -- aberta | fechada
    FOREIGN KEY (operador_id) REFERENCES operadores(id)
);

CREATE INDEX IF NOT EXISTS idx_sessoes_status ON sessoes_caixa (pdv_id, status);

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

-- Log simples de eventos (carga, sync, erros)
CREATE TABLE IF NOT EXISTS logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    nivel      TEXT NOT NULL,
    mensagem   TEXT NOT NULL,
    contexto   TEXT,
    criado_em  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

// Wrapper genérico para chamadas ao backend interno (PHP).
// Centraliza tratamento de erro, JSON e credentials para sessão.

async function request(url, options = {}) {
    const opts = {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        ...options,
    };

    if (opts.body && typeof opts.body !== 'string') {
        opts.headers['Content-Type'] = 'application/json; charset=utf-8';
        opts.body = JSON.stringify(opts.body);
    }

    let resp, dados;
    try {
        resp = await fetch(url, opts);
        dados = await resp.json();
    } catch (e) {
        throw new Error('Falha de comunicação com o servidor local');
    }

    if (!resp.ok || dados.sucesso === false) {
        const erro = new Error(dados.erro || 'Erro inesperado');
        erro.status = resp.status;
        erro.dados  = dados;
        throw erro;
    }
    return dados;
}

export const api = {
    get:  (url)        => request(url),
    post: (url, body)  => request(url, { method: 'POST', body }),

    // Sessão / login (campo "email" cobre operadores do ERP; "usuario" é fallback p/ admin local)
    login:           (entrada, senha)  => api.post('/api/login.php',           { email: entrada, usuario: entrada, senha }),
    logout:          ()                => api.post('/api/logout.php',          {}),
    sessao:          ()                => api.get ('/api/sessao.php'),
    slidesListar:    ()                => api.get ('/api/slides_listar.php'),

    // Produtos / venda
    buscarProduto:   (valor)           => api.get (`/api/produto_buscar.php?valor=${encodeURIComponent(valor)}`),
    pesquisarProduto:(termo)           => api.get (`/api/produto_pesquisar.php?termo=${encodeURIComponent(termo)}`),
    cargaProdutos:   ()                => api.post('/api/carga_produtos.php',   {}),
    produtosStatus:  ()                => api.get ('/api/produtos_status.php'),
    finalizarVenda:  (dados)           => api.post('/api/venda_finalizar.php',  dados),
    transmitirCupom: (vendaId)         => api.post('/api/cupom_transmitir.php', { venda_id: vendaId }),

    // Caixa
    caixaStatus:     ()                => api.get ('/api/caixa_status.php'),
    caixaAbrir:      (dados)           => api.post('/api/caixa_abrir.php',      dados),
    caixaFechar:     (dados)           => api.post('/api/caixa_fechar.php',     dados),
    caixaMovimento:  (dados)           => api.post('/api/caixa_movimento.php',  dados),

    // Operadores (admin)
    operadoresListar:()                => api.get ('/api/operadores_listar.php'),
    operadorSalvar:  (dados)           => api.post('/api/operadores_salvar.php', dados),
    operadorExcluir: (id)              => api.post('/api/operadores_excluir.php',{ id }),
    operadoresSync:  ()                => api.post('/api/sync_operadores.php',   {}),
    adminAutorizar:  (senha)                       => api.post('/api/admin_autorizar.php',     { senha }),
    adminTrocarSenha:(senhaAtual, senhaNova, conf) => api.post('/api/admin_trocar_senha.php',  { senha_atual: senhaAtual, senha_nova: senhaNova, confirmacao: conf }),

    // Configurações da integração (admin)
    configListar:    ()                            => api.get ('/api/config_listar.php'),
    configSalvar:    (dados)                       => api.post('/api/config_salvar.php',       dados),
    configTestar:    (dados)                       => api.post('/api/config_testar.php',       dados || {}),

    // Sincronização (status + reenvio manual de pendentes)
    syncStatus:      ()                => api.get ('/api/sync_status.php'),
    syncVendas:      ()                => api.post('/api/sync_vendas.php',     {}),
    syncFechamentos: ()                => api.post('/api/sync_fechamentos.php',{}),

    // Comandas (terminal sem login + integração no caixa)
    buscarComanda:   (codigo)          => api.get (`/api/comanda_buscar.php?codigo=${encodeURIComponent(codigo)}`),
    comandasSync:    ()                => api.post('/api/comandas_sync.php',   {}),

    // Sistema operacional do PDV (reiniciar / desligar a máquina)
    sistemaAcao:     (acao)            => api.post('/api/sistema_acao.php',    { acao }),
};

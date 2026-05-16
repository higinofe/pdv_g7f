// Controller principal do PDV.
// Dois modos:
//   - splash: ninguém logado OU caixa fechado → slideshow + login do operador + abertura de caixa
//   - app   : caixa aberto → frente de caixa (vendas)

import { api }            from './api.js';
import { fmt, toast, loading, modal } from './ui.js';
import * as carrinho      from './carrinho.js';
import { instalarSistema } from './sistema.js';

const body = document.body;
const emSplash    = body.dataset.emSplash === '1';
// IMPORTANTE: reflete o estado REAL do caixa no BD (independente de haver
// operador logado). Em splash com caixaAberto=1, o operador foi deslogado
// (sessão expirou, Ctrl+R, troca de turno), mas o caixa NÃO fechou — só o
// endpoint caixa_fechar.php fecha. Login deve retomar a frente de caixa.
const caixaAberto = body.dataset.caixaAberto === '1';
const ehAdmin     = body.dataset.ehAdmin === '1';

// Reiniciar / desligar disponível em qualquer tela do PDV.
instalarSistema({ caixaAberto });

// ---------- Relógio ----------
function tickRelogio() {
    const el = document.getElementById('relogio');
    if (el) el.textContent = new Date().toLocaleTimeString('pt-BR');
}
setInterval(tickRelogio, 1000);
tickRelogio();

// ============================================================
// AJUDA — atalho Ctrl+H + botão no rodapé/splash
// ============================================================
function abrirAjuda() {
    if (typeof modal !== 'undefined') modal.abrir('modal-ajuda');
}
document.getElementById('btn-ajuda')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    abrirAjuda();
});
document.addEventListener('keydown', (e) => {
    // Ctrl+H em qualquer tela → abre ajuda. Não interfere se um input está em foco
    // (browser nativo de Ctrl+H costuma ser suprimido em modo kiosk).
    if (e.ctrlKey && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'h') {
        e.preventDefault();
        abrirAjuda();
    }
});

// Esc fecha modais em QUALQUER tela (inclusive na splash, onde antes só
// existia o handler dentro do bloco "app" — o usuário ficava preso quando
// abria um modal de login/sistema/ajuda na splash). Bubble phase para que
// handlers de input mais específicos (ex.: Esc dentro do pag-valor cancela
// só a entrada parcial) possam chamar stopPropagation e impedir o
// fechamento do modal inteiro.
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (modal.aberto()) {
        e.preventDefault();
        modal.fecharTodos();
        // Devolve foco ao input principal se estivermos em modo venda.
        document.getElementById('busca-produto')?.focus();
    }
});

// ============================================================
// SPLASH — slideshow + login do operador + abertura do caixa
// ============================================================
if (emSplash) {
    inicializarSplash();
}

async function inicializarSplash() {
    const stage    = document.getElementById('splash-slides');
    const barras   = document.getElementById('splash-progress');
    const fallback = document.getElementById('splash-fallback');

    let slides = [];
    let intervaloMs = 6000;
    try {
        const r = await api.slidesListar();
        slides = r.slides || [];
        if (r.intervalo_ms) intervaloMs = r.intervalo_ms;
    } catch {
        // sem internet com o backend local: segue com fundo branco
    }

    if (slides.length === 0) {
        // Sem imagens: mostra fundo branco com identificação do PDV
        stage.classList.add('vazio');
        fallback.hidden = false;
    } else {
        slides.forEach((s, idx) => {
            const div = document.createElement('div');
            div.className = 'splash-slide' + (idx === 0 ? ' ativo' : '');
            div.style.backgroundImage = `url("${s.url}")`;
            stage.appendChild(div);

            const bar = document.createElement('div');
            bar.className = 'splash-bar';
            bar.innerHTML = '<span class="splash-bar-fill"></span>';
            barras.appendChild(bar);
        });
        rodarSlideshow(slides.length, intervaloMs);
    }

    // Atalho dedicado: F4 (ou clique no botão) abre o terminal de comanda.
    // O modo comanda só roda com o caixa fechado (comanda.php redireciona
    // pra "/" caso contrário). Em vez de simplesmente desabilitar o F4
    // quando o caixa está aberto, damos feedback explícito — o operador
    // saber que precisa fechar o caixa antes.
    const irParaComanda = () => {
        if (caixaAberto) {
            toast('O modo comanda só está disponível com o caixa fechado. Feche o caixa primeiro.', 'aviso', 5000);
            return;
        }
        window.location.href = '/comanda.php';
    };
    document.getElementById('btn-comanda')?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        irParaComanda();
    });
    // F4 em fase de captura — funciona mesmo com o modal de login aberto.
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F4' && !e.ctrlKey && !e.altKey && !e.metaKey) {
            e.preventDefault();
            e.stopPropagation();
            irParaComanda();
        }
    }, true);

    // Captura qualquer interação (tecla, clique ou toque) para abrir o login
    const abrirLogin = (e) => {
        // Não dispara se o evento veio de dentro de um modal já aberto
        if (modal.aberto()) return;
        if (e && e.type === 'keydown') {
            if (e.key === 'Escape') return;
            // Combos com Ctrl/Alt/Meta (ex.: Ctrl+, p/ config, Ctrl+H p/ ajuda)
            // têm tratamento próprio — não abrem o login.
            if (e.ctrlKey || e.altKey || e.metaKey) return;
            // F4 já foi tratado no listener de captura acima.
            if (e.key === 'F4') return;
        }
        modal.abrir('modal-operador-login');
    };
    document.addEventListener('keydown', abrirLogin);
    document.getElementById('splash')?.addEventListener('click', (e) => {
        // Cliques nos botões da splash (Config, Ajuda) não devem abrir o login.
        if (e.target.closest('button')) return;
        abrirLogin(e);
    });
    document.getElementById('splash')?.addEventListener('touchstart', abrirLogin, { passive: true });

    // ---- Form de login do operador ----
    // Enter no usuário avança pro PIN — submeter com PIN vazio só mostraria
    // o erro de validação, atrapalhando o operador que está digitando.
    document.getElementById('op-login-email')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('op-login-senha')?.focus();
        }
    });
    const formLogin = document.getElementById('form-operador-login');
    formLogin?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const erroEl = document.getElementById('op-login-erro');
        erroEl.textContent = '';
        const email = document.getElementById('op-login-email').value.trim();
        const senha = document.getElementById('op-login-senha').value;
        if (!email || !senha) {
            erroEl.textContent = 'Informe usuário/e-mail e senha';
            return;
        }
        const stop = loading('Validando credenciais…');
        try {
            const r = await api.login(email, senha);
            const op = r.operador || {};
            modal.fechar('modal-operador-login');
            // Limpa o form (segurança)
            document.getElementById('op-login-email').value = '';
            document.getElementById('op-login-senha').value = '';

            // Auto-carga de produtos: se o banco ainda só tem dados de seed
            // (nenhum produto com erp_id), busca o catálogo real do ERP antes
            // de seguir. Operador não precisa lembrar de F3 no primeiro uso.
            try {
                const ps = await api.produtosStatus();
                if (ps.precisa_carga) {
                    const stopCarga = loading('Sincronizando produtos do ERP…');
                    try { await api.cargaProdutos(); }
                    catch (e) { toast('Falha na carga inicial: ' + e.message, 'aviso', 5000); }
                    finally { stopCarga(); }
                }
            } catch { /* segue mesmo se a checagem falhar */ }

            // Checa o estado REAL do caixa via API — o data-caixa-aberto no HTML
            // foi calculado antes do login (sem sessão), então estaria sempre 0.
            let caixaJaAberto = false;
            try {
                const st = await api.caixaStatus();
                caixaJaAberto = !!st.aberta;
            } catch { /* se falhar, segue para abertura */ }

            if (caixaJaAberto) {
                // Sessão já existe: vai direto para a frente de caixa.
                window.location.reload();
                return;
            }
            document.getElementById('ac-nome-operador').textContent = op.nome || 'operador';
            modal.abrir('modal-abrir-caixa');
        } catch (err) {
            erroEl.textContent = err.message || 'Falha no login';
        } finally { stop(); }
    });

    // ---- Form de abertura de caixa ----
    document.getElementById('ac-confirmar')?.addEventListener('click', confirmarAbertura);
    document.getElementById('ac-valor')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') confirmarAbertura();
    });
    async function confirmarAbertura() {
        const valor = Number(document.getElementById('ac-valor').value) || 0;
        const obs   = document.getElementById('ac-obs').value.trim();
        if (valor < 0) return toast('Valor inválido', 'erro');
        const stop = loading('Abrindo caixa…');
        try {
            await api.caixaAbrir({ valor_abertura: valor, observacao: obs });
            toast('Caixa aberto com sucesso', 'sucesso');
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            toast(err.message, 'erro');
        } finally { stop(); }
    }
}

function rodarSlideshow(qtd, intervaloMs) {
    let atual = 0;
    aplicarBarras(0, qtd, intervaloMs);

    setInterval(() => {
        const slidesEls = document.querySelectorAll('.splash-slide');
        slidesEls[atual]?.classList.remove('ativo');
        atual = (atual + 1) % qtd;
        slidesEls[atual]?.classList.add('ativo');
        aplicarBarras(atual, qtd, intervaloMs);
    }, intervaloMs);
}

function aplicarBarras(ativo, total, durMs) {
    document.querySelectorAll('.splash-bar-fill').forEach((el, idx) => {
        // remove animação anterior para reiniciar
        el.style.animation = 'none';
        el.style.width = idx < ativo ? '100%' : (idx > ativo ? '0%' : '0%');
        if (idx === ativo) {
            // força reflow para a animação reiniciar
            void el.offsetWidth;
            el.style.animation = `splashFill ${durMs}ms linear forwards`;
        }
    });
}

// ============================================================
// APP — caixa aberto (frente de caixa)
// ============================================================
if (!emSplash) {

    // ====================================================================
    // Elevação de privilégio admin sob demanda
    //
    // Qualquer ação que exija perfil admin (sincronizar operadores, abrir
    // cadastro, etc.) passa por exigirAdmin('título da ação', acao). Se o
    // operador atual já é admin, acao() roda direto. Senão, abre o modal
    // de autorização, valida no servidor (admin_autorizar.php), recebe uma
    // janela de 5 min e roda acao(). A elevação fica na sessão PHP.
    // ====================================================================
    let acaoPendenteAdmin = null;
    function exigirAdmin(titulo, acao) {
        if (typeof acao !== 'function') return;
        // Mesmo se for admin, ainda passamos pela autorização — a senha é a
        // única forma de provar identidade fora da sessão (consistente com
        // o que o backend exige). Se quiser bypass para admin, basta:
        // if (ehAdmin) { acao(); return; }
        acaoPendenteAdmin = acao;
        document.getElementById('adm-titulo').textContent = titulo || 'Autorização do Administrador';
        document.getElementById('adm-mensagem').textContent =
            `"${titulo}" exige a senha do administrador.`;
        document.getElementById('adm-senha').value = '';
        document.getElementById('adm-erro').textContent = '';
        modal.abrir('modal-admin-auth');
    }

    /**
     * Executa fn() e, se vier 403 (elevação expirada/ausente), abre o modal
     * de autorização e tenta de novo. Usado em ações longas dentro do
     * cadastro de operadores — a janela de 5 min pode acabar antes do
     * usuário terminar o formulário.
     */
    async function chamarComoAdmin(titulo, fn) {
        try {
            return await fn();
        } catch (err) {
            if (err?.status !== 403) throw err;
            return await new Promise((resolve, reject) => {
                exigirAdmin(titulo, async () => {
                    try { resolve(await fn()); }
                    catch (e2) { reject(e2); }
                });
            });
        }
    }

    document.getElementById('form-admin-auth')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const senha  = document.getElementById('adm-senha').value;
        const erroEl = document.getElementById('adm-erro');
        erroEl.textContent = '';
        if (!senha) {
            erroEl.textContent = 'Informe a senha de administrador';
            return;
        }
        const stop = loading('Verificando senha…');
        try {
            await api.adminAutorizar(senha);
            toast('Autorização concedida', 'sucesso', 3000);
            modal.fechar('modal-admin-auth');
            const acao = acaoPendenteAdmin;
            acaoPendenteAdmin = null;
            if (acao) acao();
        } catch (err) {
            erroEl.textContent = err?.message || 'Falha na autorização';
        } finally { stop(); }
    });

    // --- Alterar a senha do admin ---
    function abrirTrocarSenha() {
        document.getElementById('adm-ts-atual').value = '';
        document.getElementById('adm-ts-nova').value  = '';
        document.getElementById('adm-ts-conf').value  = '';
        document.getElementById('adm-ts-erro').textContent = '';
        // Fecha o modal de autorização caso esteja aberto — o trocar senha
        // tem fluxo próprio (senha atual já valida o usuário).
        modal.fechar('modal-admin-auth');
        modal.abrir('modal-admin-trocar-senha');
    }
    document.getElementById('adm-trocar-senha')?.addEventListener('click', (e) => {
        e.preventDefault();
        abrirTrocarSenha();
    });
    // Enter avança campo a campo (atual → nova → confirmar). No último, o
    // submit nativo do form cuida.
    document.getElementById('adm-ts-atual')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('adm-ts-nova')?.focus();
        }
    });
    document.getElementById('adm-ts-nova')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('adm-ts-conf')?.focus();
        }
    });

    document.getElementById('form-admin-trocar-senha')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const atual = document.getElementById('adm-ts-atual').value;
        const nova  = document.getElementById('adm-ts-nova').value;
        const conf  = document.getElementById('adm-ts-conf').value;
        const erro  = document.getElementById('adm-ts-erro');
        erro.textContent = '';
        if (!atual || !nova) { erro.textContent = 'Preencha todos os campos';   return; }
        if (nova.length < 4) { erro.textContent = 'Nova senha precisa ter pelo menos 4 caracteres'; return; }
        if (nova !== conf)   { erro.textContent = 'Confirmação não bate com a nova senha'; return; }
        const stop = loading('Salvando nova senha…');
        try {
            await api.adminTrocarSenha(atual, nova, conf);
            toast('Senha de administrador alterada', 'sucesso', 4000);
            modal.fechar('modal-admin-trocar-senha');
        } catch (err) {
            erro.textContent = err?.message || 'Falha ao alterar senha';
        } finally { stop(); }
    });

    // ============== Configurações da integração (Ctrl+,) ==============
    const CFG_CAMPOS = [
        'API_URL', 'API_TOKEN', 'PDV_ID', 'PDV_NOME', 'HTTP_TIMEOUT', 'SLIDES_INTERVALO_MS',
        'ENDPOINT_PRODUTOS', 'ENDPOINT_OPERADORES', 'ENDPOINT_OPERADOR_LOGIN',
        'ENDPOINT_VENDAS', 'ENDPOINT_CUPOM', 'ENDPOINT_COMANDAS', 'ENDPOINT_FECHAMENTOS',
    ];

    async function abrirConfigIntegracao() {
        const stop = loading('Carregando configurações…');
        try {
            const r = await chamarComoAdmin('Configurações da integração',
                () => api.configListar());
            const valores = r.config || {};
            CFG_CAMPOS.forEach(k => {
                const el = document.getElementById('cfg-' + k);
                if (el) el.value = valores[k] ?? '';
            });
            document.getElementById('cfg-msg').textContent = '';
            modal.abrir('modal-config-integracao');
        } catch (err) {
            toast('Falha ao carregar configurações: ' + (err?.message || ''), 'erro', 6000);
        } finally { stop(); }
    }

    function coletarConfig() {
        const dados = {};
        CFG_CAMPOS.forEach(k => {
            const el = document.getElementById('cfg-' + k);
            if (el) dados[k] = el.value;
        });
        return dados;
    }

    document.getElementById('form-config-integracao')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = document.getElementById('cfg-msg');
        msg.textContent = '';
        const stop = loading('Salvando configurações…');
        try {
            const r = await chamarComoAdmin('Salvar configurações',
                () => api.configSalvar(coletarConfig()));
            toast(`Configurações salvas (${(r.alteradas || []).length} campo(s))`, 'sucesso', 4500);
            modal.fechar('modal-config-integracao');
            // Atualiza o status de conexão imediatamente após salvar.
            window.__pdvSync?.atualizar?.();
        } catch (err) {
            msg.textContent = err?.message || 'Falha ao salvar';
        } finally { stop(); }
    });

    document.getElementById('cfg-testar')?.addEventListener('click', async () => {
        const msg = document.getElementById('cfg-msg');
        msg.textContent = '';
        const stop = loading('Testando conexão com o ERP…');
        try {
            const dados = coletarConfig();
            // Só envia campos relevantes pra teste — evita gravar antes da hora.
            await chamarComoAdmin('Testar conexão',
                () => api.configTestar({
                    API_URL:   dados.API_URL,
                    API_TOKEN: dados.API_TOKEN,
                    PDV_NOME:  dados.PDV_NOME,
                }));
            toast('Conexão OK com o ERP', 'sucesso', 4000);
        } catch (err) {
            toast('Falha na conexão: ' + (err?.message || ''), 'erro', 6000);
        } finally { stop(); }
    });

    // ⎋ no topbar = Sair completo (= F12). Reaproveita o fluxo do fechamento de
    // caixa, garantindo que a sessão é encerrada e que a próxima abertura volta
    // a pedir o saldo inicial.
    document.getElementById('btn-sair')?.addEventListener('click', (e) => {
        e.preventDefault();
        abrirFechamento();
    });
    // O 👤 abre o cadastro — se o operador atual não for admin, exigirAdmin()
    // pede credenciais antes de carregar a lista (qualquer outro endpoint
    // de operadores também passa pelo backend que valida elevação).
    document.getElementById('btn-operadores')?.addEventListener('click', () => {
        exigirAdmin('Cadastro de operadores', abrirOperadores);
    });

    // Reiniciar caixa (troca de operador) — mantém a sessão de caixa aberta
    document.getElementById('btn-reiniciar')?.addEventListener('click', (e) => {
        e.preventDefault();
        reiniciarCaixa();
    });

    async function reiniciarCaixa() {
        if (!carrinho.vazio()) {
            if (!confirm('Há itens no carrinho. Reiniciar mesmo assim cancelará a venda atual.')) return;
            carrinho.limpar();
        }
        const stop = loading('Reiniciando caixa…');
        try { await api.logout(); } catch {}
        // Sessão de caixa NÃO é fechada — só a sessão do operador.
        // Próximo login retoma direto na tela de venda.
        setTimeout(() => window.location.reload(), 200);
        // stop() é chamado apenas se a navegação demorar; não há finally porque
        // o reload descarta o contexto.
        void stop;
    }

    // ---------- Indicador de sincronização (online/offline + pendentes) ----------
    const syncEl       = document.getElementById('sync-status');
    const syncLabelEl  = document.getElementById('sync-label');
    const syncBadgeEl  = document.getElementById('sync-pendentes');
    let syncEstado     = { online: null, pendentes: 0 };
    let syncEnviando   = false;

    function pintarSync({ online, pendentes }) {
        if (!syncEl) return;
        syncEl.classList.toggle('online',  !!online);
        syncEl.classList.toggle('offline', online === false);
        const label = online ? 'Online' : (online === false ? 'Offline' : 'Verificando…');
        syncLabelEl.textContent = pendentes > 0 ? `${label} • ${pendentes} pend.` : label;
        if (pendentes > 0) {
            syncBadgeEl.hidden = false;
            syncBadgeEl.textContent = pendentes;
        } else {
            syncBadgeEl.hidden = true;
        }
        syncEl.title = pendentes > 0
            ? `${pendentes} venda(s) pendente(s) — clique para reenviar agora`
            : (online ? 'Conectado ao ERP' : 'Sem conexão com o ERP');
    }

    async function atualizarSync() {
        if (syncEnviando) return;
        try {
            const r = await api.syncStatus();
            syncEstado = { online: !!r.online, pendentes: r.pendentes || 0 };
            pintarSync(syncEstado);
        } catch {
            syncEstado = { online: false, pendentes: syncEstado.pendentes };
            pintarSync(syncEstado);
        }
    }

    async function reenviarPendentes() {
        if (syncEnviando) return;
        if (syncEstado.pendentes === 0) {
            return toast(syncEstado.online ? 'Nada pendente.' : 'Sem conexão com o ERP.', 'aviso');
        }
        if (!syncEstado.online) {
            return toast('Sem conexão com o ERP — tentaremos novamente quando voltar.', 'aviso');
        }
        syncEnviando = true;
        syncEl?.classList.add('enviando');
        syncLabelEl.textContent = 'Reenviando…';
        try {
            // Reenvia vendas E fechamentos pendentes em paralelo — cada
            // endpoint trata sua própria fila.
            const [vendas, fechamentos] = await Promise.allSettled([
                api.syncVendas(),
                api.syncFechamentos(),
            ]);
            const partes = [];
            if (vendas.status === 'fulfilled')     partes.push(vendas.value.mensagem || 'vendas ok');
            else                                   partes.push('vendas: falha');
            if (fechamentos.status === 'fulfilled') partes.push(fechamentos.value.mensagem || 'fechamentos ok');
            else                                    partes.push('fechamentos: falha');
            const houveFalha = [vendas, fechamentos].some(x => x.status === 'rejected'
                || (x.value && (x.value.falhas ?? 0) > 0));
            toast(partes.join(' | '), houveFalha ? 'aviso' : 'sucesso', 6000);
        } catch (err) {
            toast('Falha no reenvio: ' + err.message, 'erro', 6000);
        } finally {
            syncEnviando = false;
            syncEl?.classList.remove('enviando');
            atualizarSync();
        }
    }

    syncEl?.addEventListener('click', reenviarPendentes);
    atualizarSync();
    setInterval(atualizarSync, 15000);
    window.addEventListener('online',  atualizarSync);
    window.addEventListener('offline', () => pintarSync({ online: false, pendentes: syncEstado.pendentes }));
    window.__pdvSync = { atualizar: atualizarSync };

    // ---------- Operadores (admin) ----------
    // O gate de perfil saiu daqui: exigirAdmin() roda no caller (botão 👤,
    // Ctrl+O) e o backend revalida a elevação a cada request. Quem cai
    // direto aqui já tem autorização ativa.
    function abrirOperadores() {
        carregarOperadores();
        const f = document.getElementById('op-form');
        if (f) f.hidden = true;
        modal.abrir('modal-operadores');
    }

    async function carregarOperadores() {
        try {
            const r = await chamarComoAdmin('Listar operadores', () => api.operadoresListar());
            const tbody = document.getElementById('op-tabela-body');
            tbody.innerHTML = r.operadores.map(o => `
                <tr data-id="${o.id}">
                    <td>${escapar(o.usuario)}</td>
                    <td>${escapar(o.nome)}</td>
                    <td>${o.perfil === 'admin' ? '<span class="tag admin">admin</span>' : '<span class="tag">operador</span>'}</td>
                    <td>${Number(o.ativo) ? '<span class="tag ok">ativo</span>' : '<span class="tag off">inativo</span>'}</td>
                    <td class="acoes">
                        <button class="btn-mini" data-acao="editar">editar</button>
                        ${Number(o.ativo) ? '<button class="btn-mini danger" data-acao="desativar">desativar</button>' : ''}
                    </td>
                </tr>`).join('');

            tbody.querySelectorAll('button[data-acao]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = Number(btn.closest('tr').dataset.id);
                    const op = r.operadores.find(o => Number(o.id) === id);
                    if (btn.dataset.acao === 'editar') abrirFormOperador(op);
                    if (btn.dataset.acao === 'desativar') desativarOperador(id);
                });
            });
        } catch (err) {
            toast('Erro: ' + err.message, 'erro');
        }
    }

    document.getElementById('op-novo')?.addEventListener('click', () => abrirFormOperador(null));

    let sincronizandoOperadores = false;
    async function sincronizarOperadores() {
        if (sincronizandoOperadores) {
            toast('Sincronização já em andamento…', 'aviso');
            return;
        }
        sincronizandoOperadores = true;
        const btn = document.getElementById('op-sync');
        const txtOriginal = btn?.textContent;
        if (btn) { btn.disabled = true; btn.textContent = 'Sincronizando…'; }
        // Overlay de loading garante feedback mesmo quando o atalho é
        // disparado sem o modal aberto (botão op-sync fica invisível).
        const stop = loading('Sincronizando operadores com o ERP…');
        try {
            const r = await chamarComoAdmin('Sincronizar operadores',
                () => api.operadoresSync());
            toast(r.mensagem || 'Sincronização concluída', 'sucesso', 5000);
            const md = document.getElementById('modal-operadores');
            if (md && !md.hidden) carregarOperadores();
        } catch (err) {
            const detalhe = err?.message || 'erro desconhecido';
            toast('Falha na sincronização: ' + detalhe, 'erro', 7000);
            console.error('[sincronizarOperadores] falha', err);
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = txtOriginal; }
            sincronizandoOperadores = false;
            stop();
        }
    }
    document.getElementById('op-sync')?.addEventListener('click', sincronizarOperadores);
    document.getElementById('op-cancelar')?.addEventListener('click', () => {
        document.getElementById('op-form').hidden = true;
    });

    function abrirFormOperador(op) {
        const form = document.getElementById('op-form');
        form.hidden = false;
        document.getElementById('op-form-titulo').textContent = op ? `Editar: ${op.nome}` : 'Novo operador';
        document.getElementById('op-id').value      = op?.id   ?? '';
        document.getElementById('op-usuario').value = op?.usuario ?? '';
        document.getElementById('op-nome').value    = op?.nome    ?? '';
        document.getElementById('op-perfil').value  = op?.perfil  ?? 'operador';
        document.getElementById('op-senha').value   = '';
        document.getElementById('op-ativo').checked = op ? !!Number(op.ativo) : true;
        document.getElementById('op-senha-hint').textContent = op
            ? '(deixe em branco para manter a senha atual)'
            : '(mínimo 4 caracteres)';
        document.getElementById('op-msg').textContent = '';
        document.getElementById('op-usuario').focus();
    }

    document.getElementById('op-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const dados = {
            id:      document.getElementById('op-id').value || null,
            usuario: document.getElementById('op-usuario').value.trim(),
            nome:    document.getElementById('op-nome').value.trim(),
            perfil:  document.getElementById('op-perfil').value,
            senha:   document.getElementById('op-senha').value,
            ativo:   document.getElementById('op-ativo').checked,
        };
        try {
            await chamarComoAdmin('Salvar operador', () => api.operadorSalvar(dados));
            toast('Operador salvo', 'sucesso');
            document.getElementById('op-form').hidden = true;
            carregarOperadores();
        } catch (err) {
            document.getElementById('op-msg').textContent = err.message;
        }
    });

    async function desativarOperador(id) {
        if (!confirm('Desativar este operador? Ele não poderá mais fazer login.')) return;
        try {
            await chamarComoAdmin('Desativar operador', () => api.operadorExcluir(id));
            toast('Operador desativado', 'sucesso');
            carregarOperadores();
        } catch (err) {
            toast(err.message, 'erro');
        }
    }

    // ---------- Frente de caixa ----------
    let ultimaVendaId = null;
    let comandaAtiva = null;   // { codigo, descricao } — preenchida ao bipar uma comanda

    const buscaInput = document.getElementById('busca-produto');
    const qtdInput   = document.getElementById('qtd');

    function pintarComandaAtiva() {
        // Indica visualmente que o carrinho atual veio de uma comanda.
        const label = document.querySelector('.descricao-area > label');
        if (!label) return;
        if (comandaAtiva) {
            label.innerHTML = `Descrição <span class="badge-comanda">Comanda ${escapar(comandaAtiva.codigo)}${comandaAtiva.descricao ? ' — ' + escapar(comandaAtiva.descricao) : ''}</span>`;
        } else {
            label.textContent = 'Descrição';
        }
    }

    async function tentarCarregarComanda(codigo) {
        // Tenta como comanda primeiro. Retorna true se carregou.
        try {
            const r = await api.buscarComanda(codigo);
            if (!r.comanda || r.comanda.status !== 'aberta') return false;
            if (!carrinho.vazio()) {
                if (!confirm(`O carrinho tem itens. Substituir pelos itens da comanda ${r.comanda.codigo}?`)) {
                    return true;   // user recusou — tratado como "intenção de comanda"
                }
                carrinho.limpar();
            }
            (r.itens || []).forEach(it => {
                // Reconstrói o "produto" mínimo que o carrinho espera.
                carrinho.adicionar({
                    codigo:     it.produto_codigo,
                    erp_id:     it.produto_erp_id,
                    descricao:  it.descricao,
                    preco:      Number(it.preco_unitario) || 0,
                }, Number(it.quantidade) || 1);
            });
            comandaAtiva = { codigo: r.comanda.codigo, descricao: r.comanda.descricao };
            pintarComandaAtiva();
            toast(`Comanda ${r.comanda.codigo} carregada (${r.itens.length} item(ns))`, 'sucesso', 4000);
            return true;
        } catch (err) {
            // 404 = não é uma comanda → cai pro fluxo de produto.
            if (err.status === 404) return false;
            // Outros erros (rede, 409): mostra mensagem mas não impede tentar como produto.
            return false;
        }
    }

    buscaInput?.addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const valor = buscaInput.value.trim();
        if (!valor) return;
        const qtd = Number(qtdInput.value) || 1;

        // Bipou uma comanda? (Tenta antes do produto; o endpoint é local e rápido.)
        if (await tentarCarregarComanda(valor)) {
            buscaInput.value = '';
            qtdInput.value = 1;
            buscaInput.focus();
            return;
        }

        try {
            const r = await api.buscarProduto(valor);
            carrinho.adicionar(r.produto, qtd);
            buscaInput.value = '';
            qtdInput.value = 1;
            buscaInput.focus();
        } catch (err) {
            toast(err.message, 'erro');
            buscaInput.select();
        }
    });

    document.addEventListener('click', (e) => {
        if (modal.aberto()) return;
        if (e.target.matches('input, button, .atalho, .atalho *')) return;
        buscaInput?.focus();
    });

    const ACOES = {
        consulta:        abrirConsulta,
        calculadora:     () => { modal.abrir('modal-calc'); calcReset(); },
        carga:           cargaProdutos,
        'cancelar-item': cancelarItem,
        'cancelar-venda':cancelarVenda,
        desconto:        () => modal.abrir('modal-desconto'),
        sangria:         () => abrirMovimento('sangria'),
        reforco:         () => abrirMovimento('reforco'),
        finalizar:       abrirPagamento,
        cupom:           transmitirCupom,
        'fechar-caixa':  abrirFechamento,
    };

    document.querySelectorAll('.atalho').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            ACOES[btn.dataset.acao]?.();
        });
    });

    document.addEventListener('keydown', (e) => {
        // Esc é tratado no handler global (módulo) — não duplicar aqui.

        // ----- Combos com Ctrl ------------------------------------------
        // Combos com SHIFT são separados — não queremos que Ctrl+Shift+R do
        // navegador (hard reload) caia no reiniciar caixa, por exemplo.
        if (e.ctrlKey && !e.altKey && e.shiftKey) {
            const k = e.key.toLowerCase();
            if (k === 'a') {                // Ctrl+Shift+A — Alterar senha admin
                e.preventDefault();
                abrirTrocarSenha();
                return;
            }
            return; // outros Ctrl+Shift+X: deixa pro navegador / OS
        }
        if (e.ctrlKey && !e.altKey && !e.shiftKey) {
            const k = e.key.toLowerCase();
            if (e.key === ',' || k === ',') {  // Ctrl+, — Configurações da integração
                e.preventDefault();
                exigirAdmin('Configurações da integração', abrirConfigIntegracao);
                return;
            }
            if (k === 'r') {                // Reiniciar caixa (troca de operador)
                e.preventDefault();
                reiniciarCaixa();
                return;
            }
            if (k === 'o') {                // Sincronizar operadores com o ERP
                // preventDefault SEMPRE — caso contrário o Ctrl+O do Chrome
                // abre o diálogo "Abrir arquivo" do sistema operacional.
                e.preventDefault();
                exigirAdmin('Sincronizar operadores com o ERP', sincronizarOperadores);
                return;
            }
            if (k === 'e') {                // Ctrl+E — Encerrar caixa (alternativa
                // ao F11/F12, que em alguns setups Linux/GNOME são capturados
                // pelo window manager antes do navegador receber o evento).
                e.preventDefault();
                abrirFechamento();
                return;
            }
            // Ctrl+H e Ctrl+, são tratados pelos handlers globais (acima do bloco app).
        }

        // ----- Atalhos do modal de pagamento (F9) -----------------------
        const modalPag = document.getElementById('modal-pagamento');
        if (modalPag && !modalPag.hidden) {
            const mapa = { d: 'dinheiro', c: 'credito', b: 'debito', p: 'pix', o: 'outros' };
            const f = mapa[e.key.toLowerCase()];
            if (f && document.activeElement?.tagName !== 'INPUT') {
                e.preventDefault();
                document.querySelector(`.forma[data-forma="${f}"]`)?.click();
                return;
            }
        }

        // ----- Atalhos F1–F12 -------------------------------------------
        const teclas = {
            'F1':'consulta','F2':'calculadora','F3':'carga','F4':'cancelar-item',
            'F5':'cancelar-venda','F6':'desconto','F7':'sangria','F8':'reforco',
            'F9':'finalizar','F10':'cupom','F11':'fechar-caixa','F12':'fechar-caixa',
        };
        const acao = teclas[e.key];
        if (!acao) return;
        e.preventDefault();
        const btn = document.querySelector(`.atalho[data-acao="${acao}"]`);
        if (btn?.disabled) return;
        ACOES[acao]?.();
    });

    // Firefox dispara o fullscreen do F11 no keyup, ignorando o preventDefault
    // do keydown. Suprimimos também aqui para garantir que F11 abra o
    // fechamento de caixa e nunca caia no fullscreen do navegador. F12 idem
    // (em alguns setups abre DevTools, mas o preventDefault adicional não
    // atrapalha se o usuário já estiver de mãos no caixa).
    document.addEventListener('keyup', (e) => {
        if (e.key === 'F11' || e.key === 'F12') e.preventDefault();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F11' || e.key === 'F12') e.preventDefault();
    }, true);

    // ----- F1 Consulta -----
    function abrirConsulta() {
        const termo = document.getElementById('consulta-termo');
        const res   = document.getElementById('consulta-resultados');
        termo.value = '';
        res.innerHTML = '<p class="vazio">Digite para buscar...</p>';
        modal.abrir('modal-consulta');

        let timer;
        termo.oninput = () => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const t = termo.value.trim();
                if (t.length < 2) {
                    res.innerHTML = '<p class="vazio">Digite ao menos 2 caracteres...</p>';
                    return;
                }
                try {
                    const r = await api.pesquisarProduto(t);
                    if (r.total === 0) {
                        res.innerHTML = '<p class="vazio">Nenhum produto encontrado</p>';
                        return;
                    }
                    res.innerHTML = r.produtos.map(p => `
                        <div class="item" data-cod="${p.codigo}">
                            <span class="cod">${escapar(p.codigo)}</span>
                            <span>${escapar(p.descricao)}</span>
                            <span class="preco">${fmt.moeda(p.preco)}</span>
                        </div>`).join('');
                    res.querySelectorAll('.item').forEach(el => {
                        el.addEventListener('click', () => {
                            const prod = r.produtos.find(p => p.codigo === el.dataset.cod);
                            carrinho.adicionar(prod, Number(qtdInput.value) || 1);
                            qtdInput.value = 1;
                            modal.fechar('modal-consulta');
                            buscaInput.focus();
                        });
                    });
                } catch (err) {
                    res.innerHTML = `<p class="vazio">Erro: ${err.message}</p>`;
                }
            }, 220);
        };
    }

    // ----- F2 Calculadora -----
    let calcDisplay = '0', calcAcc = null, calcOp = null, calcReplace = false;
    function calcReset() { calcDisplay = '0'; calcAcc = null; calcOp = null; calcReplace = false; pintarCalc(); }
    function pintarCalc() { document.getElementById('calc-display').textContent = calcDisplay; }
    function calcAplicar(op, a, b) {
        a = Number(a); b = Number(b);
        switch (op) { case '+': return a+b; case '-': return a-b; case '*': return a*b; case '/': return b===0?0:a/b; }
        return b;
    }
    document.querySelectorAll('#modal-calc .calc-keys button').forEach(b => {
        b.addEventListener('click', () => {
            const k = b.dataset.k;
            if ('0123456789'.includes(k)) {
                calcDisplay = (calcReplace || calcDisplay === '0') ? k : calcDisplay + k;
                calcReplace = false;
            } else if (k === ',') { if (!calcDisplay.includes(',')) calcDisplay += ','; }
            else if (k === 'C') { calcReset(); return; }
            else if (k === '±') { calcDisplay = calcDisplay.startsWith('-') ? calcDisplay.slice(1) : '-' + calcDisplay; }
            else if (k === '%') { calcDisplay = String(Number(calcDisplay.replace(',','.'))/100).replace('.',','); }
            else if ('+-*/'.includes(k)) {
                if (calcAcc !== null && calcOp && !calcReplace) {
                    calcAcc = calcAplicar(calcOp, calcAcc, calcDisplay.replace(',','.'));
                    calcDisplay = String(calcAcc).replace('.',',');
                } else { calcAcc = Number(calcDisplay.replace(',','.')); }
                calcOp = k; calcReplace = true;
            } else if (k === '=') {
                if (calcOp !== null && calcAcc !== null) {
                    calcAcc = calcAplicar(calcOp, calcAcc, calcDisplay.replace(',','.'));
                    calcDisplay = String(calcAcc).replace('.',',');
                    calcOp = null; calcReplace = true;
                }
            }
            pintarCalc();
        });
    });

    // ----- F3 Carga -----
    async function cargaProdutos() {
        if (!confirm('Atualizar o catálogo de produtos a partir do ERP?\n\nIsso pode demorar alguns minutos.')) return;
        const stop = loading('Carregando produtos do ERP…');
        try {
            const r = await api.cargaProdutos();
            toast(r.mensagem || `Carga concluída: ${r.total} produtos`, 'sucesso');
        } catch (err) {
            toast('Falha na carga: ' + err.message, 'erro', 6000);
        } finally { stop(); }
    }

    // ----- F4 / F5 -----
    function cancelarItem() {
        if (carrinho.vazio()) return toast('Carrinho vazio', 'aviso');
        if (!carrinho.removerSelecionado()) toast('Selecione um item para cancelar', 'aviso');
    }
    function cancelarVenda() {
        if (carrinho.vazio()) return;
        if (!confirm('Cancelar a venda inteira?')) return;
        carrinho.limpar();
        ultimaVendaId = null;
        if (comandaAtiva) { comandaAtiva = null; pintarComandaAtiva(); }
        atualizarBotaoCupom();
        toast('Venda cancelada', 'aviso');
        buscaInput?.focus();
    }

    // ----- F6 Desconto -----
    function aplicarDesconto() {
        const tipo = document.querySelector('input[name="desc-tipo"]:checked').value;
        const valor = Number(document.getElementById('desc-valor').value) || 0;
        carrinho.aplicarDesconto(valor, tipo);
        modal.fechar('modal-desconto');
        toast('Desconto aplicado', 'sucesso');
    }
    document.getElementById('desc-aplicar')?.addEventListener('click', aplicarDesconto);
    // Enter no valor aplica direto — o radio de tipo só muda com clique/seta.
    document.getElementById('desc-valor')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            aplicarDesconto();
        }
    });

    // ----- F7/F8 Sangria/Reforço -----
    let movTipoAtual = 'sangria';
    function abrirMovimento(tipo) {
        movTipoAtual = tipo;
        document.getElementById('mov-titulo').textContent = tipo === 'sangria' ? 'Sangria' : 'Reforço';
        document.getElementById('mov-hint').textContent = tipo === 'sangria'
            ? 'Retirada de dinheiro do caixa (envio para malote, despesas, etc).'
            : 'Entrada de dinheiro no caixa (suprimento, troco extra, etc).';
        document.getElementById('mov-valor').value = '';
        document.getElementById('mov-motivo').value = '';
        modal.abrir('modal-movimento');
    }

    async function confirmarMovimento() {
        const valor  = Number(document.getElementById('mov-valor').value) || 0;
        const motivo = document.getElementById('mov-motivo').value.trim();
        if (valor <= 0) return toast('Informe um valor válido', 'aviso');
        const stop = loading('Registrando…');
        try {
            await api.caixaMovimento({ tipo: movTipoAtual, valor, motivo });
            toast(`${movTipoAtual === 'sangria' ? 'Sangria' : 'Reforço'} registrado`, 'sucesso');
            modal.fechar('modal-movimento');
        } catch (err) {
            toast(err.message, 'erro');
        } finally { stop(); }
    }
    document.getElementById('mov-confirmar')?.addEventListener('click', confirmarMovimento);
    // Enter no valor avança para o motivo; Enter no motivo confirma.
    document.getElementById('mov-valor')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('mov-motivo')?.focus();
        }
    });
    document.getElementById('mov-motivo')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmarMovimento();
        }
    });

    // ----- F9 Pagamento (com suporte a pagamento misto) -----
    // Estado local da finalização: lista de pagamentos sendo montada e a forma
    // atualmente sendo informada (entrada parcial). Cada item:
    //   { forma: 'dinheiro'|'debito'|'credito'|'pix'|'outros', valor: Number }
    let pagamentosEmAndamento = [];
    let formaAtual = null;

    const LABEL_FORMA = {
        dinheiro: '💵 Dinheiro',
        debito:   '💳 Débito',
        credito:  '💳 Crédito',
        pix:      '📱 PIX',
        outros:   '⋯ Outros',
    };

    function pagTotal() { return carrinho.total(); }
    function pagRecebido() {
        return pagamentosEmAndamento.reduce((s, p) => s + (p.valor || 0), 0);
    }
    function pagFalta() {
        return Math.max(0, pagTotal() - pagRecebido());
    }
    function pagTroco() {
        return Math.max(0, pagRecebido() - pagTotal());
    }

    function repintarPagamento() {
        const total = pagTotal();
        const recebido = pagRecebido();
        const falta = pagFalta();
        const troco = pagTroco();

        document.getElementById('pag-total').textContent          = fmt.moeda(total);
        document.getElementById('pag-recebido-total').textContent = fmt.moeda(recebido);
        document.getElementById('pag-falta').textContent          = fmt.moeda(falta);
        document.getElementById('pag-troco').textContent          = fmt.moeda(troco);

        document.getElementById('pag-falta-wrap').classList.toggle('zerada', falta <= 0.005);
        document.getElementById('pag-troco-wrap').hidden = troco <= 0.005;

        // Lista
        const ul = document.getElementById('pag-lista');
        ul.hidden = pagamentosEmAndamento.length === 0;
        ul.innerHTML = pagamentosEmAndamento.map((p, i) => `
            <li data-idx="${i}">
                <span>${LABEL_FORMA[p.forma] || p.forma}</span>
                <span class="pag-valor">${fmt.moeda(p.valor)}</span>
                <button type="button" class="pag-remover" aria-label="Remover" title="Remover">×</button>
            </li>
        `).join('');
        ul.querySelectorAll('.pag-remover').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = Number(btn.closest('li').dataset.idx);
                pagamentosEmAndamento.splice(idx, 1);
                repintarPagamento();
            });
        });

        // Finalizar liberado quando não falta nada (excesso só ok se houve
        // pelo menos um pagamento em dinheiro — única forma que admite troco).
        const temDinheiro = pagamentosEmAndamento.some(p => p.forma === 'dinheiro');
        const podeFinalizar = falta <= 0.005 && (troco <= 0.005 || temDinheiro);
        document.getElementById('btn-finalizar-pedido').disabled = !podeFinalizar;
    }

    function abrirPagamento() {
        if (carrinho.vazio()) return toast('Adicione itens antes de finalizar', 'aviso');
        pagamentosEmAndamento = [];
        formaAtual = null;
        document.getElementById('pag-entrada').hidden = true;
        document.querySelectorAll('.forma').forEach(b => b.classList.remove('selecionada'));
        repintarPagamento();
        modal.abrir('modal-pagamento');
    }

    function selecionarForma(forma) {
        formaAtual = forma;
        document.querySelectorAll('.forma').forEach(x => x.classList.remove('selecionada'));
        document.querySelector(`.forma[data-forma="${forma}"]`)?.classList.add('selecionada');

        const falta = pagFalta();
        const valorPadrao = falta > 0 ? falta : pagTotal();
        const entrada = document.getElementById('pag-entrada');
        document.getElementById('pag-entrada-label').textContent =
            `${LABEL_FORMA[forma] || forma} — Valor (R$)`;
        const inp = document.getElementById('pag-valor');
        inp.value = valorPadrao.toFixed(2);
        // Dinheiro pode passar do total (troco); outras formas só admitem
        // valor menor ou igual ao que falta — usamos o max só como dica visual
        // (a validação real é no Adicionar).
        inp.max = forma === 'dinheiro' ? '' : valorPadrao.toFixed(2);
        entrada.hidden = false;
        setTimeout(() => { inp.focus(); inp.select(); }, 30);
    }

    document.querySelectorAll('.forma').forEach(b => {
        b.addEventListener('click', () => selecionarForma(b.dataset.forma));
    });

    function adicionarPagamentoAtual() {
        if (!formaAtual) return toast('Escolha a forma de pagamento', 'aviso');
        const valor = Number(document.getElementById('pag-valor').value) || 0;
        if (valor <= 0) return toast('Informe um valor maior que zero', 'aviso');

        const falta = pagFalta();
        // Excesso só vale para dinheiro (vira troco). Outras formas precisam
        // ser exatas ou menores que o que falta.
        if (formaAtual !== 'dinheiro' && valor > falta + 0.005) {
            return toast(`Excesso só em dinheiro. Falta ${fmt.moeda(falta)}.`, 'erro', 5000);
        }

        pagamentosEmAndamento.push({ forma: formaAtual, valor });
        formaAtual = null;
        document.querySelectorAll('.forma').forEach(x => x.classList.remove('selecionada'));
        document.getElementById('pag-entrada').hidden = true;
        repintarPagamento();

        // Se ainda falta, devolve foco aos botões de forma; se já cobriu,
        // foca no Finalizar para Enter já finalizar.
        const btnFinalizar = document.getElementById('btn-finalizar-pedido');
        if (pagFalta() <= 0.005) {
            btnFinalizar.focus();
        } else {
            document.querySelector('.forma')?.focus();
        }
    }

    document.getElementById('pag-adicionar')?.addEventListener('click', adicionarPagamentoAtual);
    document.getElementById('pag-cancelar')?.addEventListener('click', () => {
        formaAtual = null;
        document.querySelectorAll('.forma').forEach(x => x.classList.remove('selecionada'));
        document.getElementById('pag-entrada').hidden = true;
    });
    // Enter no valor adiciona o pagamento; Esc cancela.
    document.getElementById('pag-valor')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter')  { e.preventDefault(); adicionarPagamentoAtual(); }
        if (e.key === 'Escape') {
            // Cancela só a entrada parcial; impede o Esc global de fechar o
            // modal inteiro de pagamento (stopPropagation segura o bubble).
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('pag-cancelar').click();
        }
    });

    async function finalizarPedido() {
        if (pagFalta() > 0.005) return toast('Ainda falta cobrir o total', 'aviso');
        if (pagamentosEmAndamento.length === 0) return toast('Nenhum pagamento informado', 'aviso');

        const snap = carrinho.snapshot();
        const payload = {
            ...snap,
            pagamentos:      pagamentosEmAndamento.map(p => ({ forma: p.forma, valor: p.valor })),
            valor_recebido:  pagRecebido(),
            valor_troco:     pagTroco(),
            comanda_codigo:  comandaAtiva?.codigo || null,
        };

        const stop = loading('Finalizando venda…');
        try {
            const r = await api.finalizarVenda(payload);
            ultimaVendaId = r.venda_id;
            atualizarBotaoCupom();
            modal.fechar('modal-pagamento');
            carrinho.limpar();
            if (comandaAtiva) {
                toast(`Comanda ${comandaAtiva.codigo} baixada`, 'sucesso', 3500);
                comandaAtiva = null;
                pintarComandaAtiva();
            }

            const msg = r.offline
                ? `Venda salva offline (#${r.venda_id}). Será sincronizada.`
                : `Pedido ${r.numero_pedido ?? '#' + r.venda_id} finalizado!`;
            toast(msg, r.offline ? 'aviso' : 'sucesso', 5000);
            window.__pdvSync?.atualizar();
            buscaInput?.focus();
        } catch (err) {
            toast('Erro ao finalizar: ' + err.message, 'erro', 6000);
        } finally { stop(); }
    }
    document.getElementById('btn-finalizar-pedido')?.addEventListener('click', finalizarPedido);

    // ----- F10 Cupom -----
    function atualizarBotaoCupom() {
        const btn = document.querySelector('.atalho[data-acao="cupom"]');
        if (btn) btn.disabled = !ultimaVendaId;
    }

    async function transmitirCupom() {
        if (!ultimaVendaId) return toast('Finalize o pedido (F9) antes de transmitir o cupom', 'aviso');
        const stop = loading('Transmitindo cupom fiscal…');
        try {
            const r = await api.transmitirCupom(ultimaVendaId);
            toast(r.mensagem || 'Cupom transmitido', 'sucesso');
            ultimaVendaId = null;
            atualizarBotaoCupom();
        } catch (err) {
            toast('Falha: ' + err.message, 'erro', 6000);
        } finally { stop(); }
    }

    // ----- F11 Fechar caixa -----
    async function abrirFechamento() {
        if (!carrinho.vazio()) {
            return toast('Há itens no carrinho. Finalize ou cancele a venda antes de fechar o caixa.', 'aviso');
        }
        const stop = loading('Carregando totais do caixa…');
        try {
            const r = await api.caixaStatus();
            if (!r.aberta) return toast('Caixa já está fechado', 'aviso');
            preencherFechamento(r.totais);
            modal.abrir('modal-fechar-caixa');
        } catch (err) {
            toast(err.message, 'erro');
        } finally { stop(); }
    }

    function preencherFechamento(t) {
        const v = (k) => fmt.moeda((t.por_forma?.[k]?.total) ?? 0);
        document.getElementById('fc-abertura').textContent   = fmt.moeda(t.abertura);
        document.getElementById('fc-vd-dinheiro').textContent= v('dinheiro');
        document.getElementById('fc-reforco').textContent    = fmt.moeda(t.reforco);
        document.getElementById('fc-sangria').textContent    = fmt.moeda(t.sangria);
        document.getElementById('fc-esperado').textContent   = fmt.moeda(t.saldo_dinheiro);
        document.getElementById('fc-debito').textContent     = v('debito');
        document.getElementById('fc-credito').textContent    = v('credito');
        document.getElementById('fc-pix').textContent        = v('pix');
        document.getElementById('fc-outros').textContent     = v('outros');
        document.getElementById('fc-total').textContent      = fmt.moeda(t.vendas_total);

        const inp = document.getElementById('fc-informado');
        inp.value = t.saldo_dinheiro.toFixed(2);
        atualizarDiferenca(t.saldo_dinheiro);
        inp.oninput = () => atualizarDiferenca(t.saldo_dinheiro);
    }

    function atualizarDiferenca(esperado) {
        const informado = Number(document.getElementById('fc-informado').value) || 0;
        const dif = informado - esperado;
        const wrap = document.getElementById('fc-diferenca-wrap');
        const el   = document.getElementById('fc-diferenca');
        wrap.hidden = false;
        el.textContent = fmt.moeda(dif);
        wrap.classList.toggle('positiva', dif > 0.001);
        wrap.classList.toggle('negativa', dif < -0.001);
        wrap.classList.toggle('exata',    Math.abs(dif) <= 0.001);
    }

    async function confirmarFechamento() {
        const informado = Number(document.getElementById('fc-informado').value);
        const obs = document.getElementById('fc-obs').value.trim();
        if (!confirm('Confirmar o fechamento do caixa? Esta ação encerra a sessão.')) return;
        const stop = loading('Fechando caixa…');
        try {
            const r = await api.caixaFechar({ valor_informado: informado, observacao: obs });
            // Após fechar o caixa também encerramos a sessão do operador. Sem
            // isso a próxima abertura "pula" a tela de saldo inicial porque o
            // navegador ainda volta logado para a splash.
            try { await api.logout(); } catch {}

            // Feedback do envio ao ERP: o fechamento sempre é salvo localmente
            // (com snapshot completo). Quando o ERP está offline, fica em fila
            // e o badge da topbar aparece com pendência até o próximo sync.
            const envio = r?.envio_erp || {};
            if (envio.sucesso) {
                const numero = envio.numero_fechamento ? ` (nº ${envio.numero_fechamento})` : '';
                toast(`Caixa fechado e enviado ao ERP${numero}`, 'sucesso', 5000);
            } else {
                toast('Caixa fechado. ERP indisponível — fechamento ficou pendente para sync.', 'aviso', 6000);
            }
            setTimeout(() => window.location.reload(), 1000);
        } catch (err) {
            toast(err.message, 'erro');
        } finally { stop(); }
    }
    document.getElementById('fc-confirmar')?.addEventListener('click', confirmarFechamento);
    // Enter no valor informado avança pra observação; Enter na observação
    // confirma o fechamento.
    document.getElementById('fc-informado')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('fc-obs')?.focus();
        }
    });
    document.getElementById('fc-obs')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmarFechamento();
        }
    });
}

// ---------- Helper compartilhado ----------
function escapar(s) {
    return String(s ?? '').replace(/[<>&"']/g, c => ({
        '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;','\'':'&#39;'
    }[c]));
}

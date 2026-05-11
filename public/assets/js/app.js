// Controller principal do PDV.
// Dois modos:
//   - splash: ninguém logado OU caixa fechado → slideshow + login do operador + abertura de caixa
//   - app   : caixa aberto → frente de caixa (vendas)

import { api }            from './api.js';
import { fmt, toast, loading, modal } from './ui.js';
import * as carrinho      from './carrinho.js';

const body = document.body;
const emSplash    = body.dataset.emSplash === '1';
const caixaAberto = body.dataset.caixaAberto === '1';
const ehAdmin     = body.dataset.ehAdmin === '1';

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

    // Captura qualquer interação (tecla, clique ou toque) para abrir o login
    const abrirLogin = (e) => {
        // Não dispara se o evento veio de dentro de um modal já aberto
        if (modal.aberto()) return;
        if (e && e.type === 'keydown') {
            if (e.key === 'Escape') return;
            // Combos com Ctrl/Alt/Meta (ex.: Ctrl+, p/ config, Ctrl+H p/ ajuda)
            // têm tratamento próprio — não abrem o login.
            if (e.ctrlKey || e.altKey || e.metaKey) return;
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

    document.getElementById('btn-sair')?.addEventListener('click', sairApp);
    if (ehAdmin) {
        document.getElementById('btn-operadores')?.addEventListener('click', abrirOperadores);
    }

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
            return toast(syncEstado.online ? 'Nenhuma venda pendente.' : 'Sem conexão com o ERP.', 'aviso');
        }
        if (!syncEstado.online) {
            return toast('Sem conexão com o ERP — tentaremos novamente quando voltar.', 'aviso');
        }
        syncEnviando = true;
        syncEl?.classList.add('enviando');
        syncLabelEl.textContent = 'Reenviando…';
        try {
            const r = await api.syncVendas();
            toast(r.mensagem || 'Reenvio concluído', r.falhas > 0 ? 'aviso' : 'sucesso', 5000);
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

    async function sairApp() {
        if (!caixaAberto || carrinho.vazio() || confirm('Há itens no carrinho. Sair mesmo assim?')) {
            try { await api.logout(); } catch {}
            window.location.reload();
        }
    }

    // ---------- Operadores (admin) ----------
    function abrirOperadores() {
        if (!ehAdmin) return;
        carregarOperadores();
        const f = document.getElementById('op-form');
        if (f) f.hidden = true;
        modal.abrir('modal-operadores');
    }

    async function carregarOperadores() {
        try {
            const r = await api.operadoresListar();
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

    document.getElementById('op-sync')?.addEventListener('click', async () => {
        const btn = document.getElementById('op-sync');
        const txtOriginal = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Sincronizando…';
        try {
            const r = await api.operadoresSync();
            toast(r.mensagem || 'Sincronização concluída', 'sucesso', 5000);
            carregarOperadores();
        } catch (err) {
            toast('Falha na sincronização: ' + err.message, 'erro', 6000);
        } finally {
            btn.disabled = false;
            btn.textContent = txtOriginal;
        }
    });
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
            await api.operadorSalvar(dados);
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
            await api.operadorExcluir(id);
            toast('Operador desativado', 'sucesso');
            carregarOperadores();
        } catch (err) {
            toast(err.message, 'erro');
        }
    }

    // ---------- Frente de caixa ----------
    let ultimaVendaId = null;
    let formaPagamento = null;
    let formaPagamentoSelecionada = false;

    const buscaInput = document.getElementById('busca-produto');
    const qtdInput   = document.getElementById('qtd');

    buscaInput?.addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const valor = buscaInput.value.trim();
        if (!valor) return;
        const qtd = Number(qtdInput.value) || 1;
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
        sair:            () => document.getElementById('btn-sair').click(),
    };

    document.querySelectorAll('.atalho').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            ACOES[btn.dataset.acao]?.();
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (modal.aberto()) { modal.fecharTodos(); buscaInput?.focus(); }
            return;
        }

        // ----- Combos com Ctrl ------------------------------------------
        if (e.ctrlKey && !e.altKey) {
            const k = e.key.toLowerCase();
            if (k === 'r') {                // Reiniciar caixa (troca de operador)
                e.preventDefault();
                reiniciarCaixa();
                return;
            }
            if (k === 'p') {                // Sincronizar produtos (= F3)
                e.preventDefault();
                cargaProdutos();
                return;
            }
            if (k === 'o' && ehAdmin) {     // Cadastro de operadores
                e.preventDefault();
                abrirOperadores();
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
            'F9':'finalizar','F10':'cupom','F11':'fechar-caixa','F12':'sair',
        };
        const acao = teclas[e.key];
        if (!acao) return;
        e.preventDefault();
        const btn = document.querySelector(`.atalho[data-acao="${acao}"]`);
        if (btn?.disabled) return;
        ACOES[acao]?.();
    });

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
        atualizarBotaoCupom();
        toast('Venda cancelada', 'aviso');
        buscaInput?.focus();
    }

    // ----- F6 Desconto -----
    document.getElementById('desc-aplicar')?.addEventListener('click', () => {
        const tipo = document.querySelector('input[name="desc-tipo"]:checked').value;
        const valor = Number(document.getElementById('desc-valor').value) || 0;
        carrinho.aplicarDesconto(valor, tipo);
        modal.fechar('modal-desconto');
        toast('Desconto aplicado', 'sucesso');
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

    document.getElementById('mov-confirmar')?.addEventListener('click', async () => {
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
    });

    // ----- F9 Pagamento -----
    function abrirPagamento() {
        if (carrinho.vazio()) return toast('Adicione itens antes de finalizar', 'aviso');
        formaPagamento = null;
        formaPagamentoSelecionada = false;
        document.getElementById('pag-total').textContent = fmt.moeda(carrinho.total());
        document.getElementById('pag-recebido').value = carrinho.total().toFixed(2);
        document.getElementById('pag-troco').textContent = fmt.moeda(0);
        document.getElementById('dinheiro-area').hidden = true;
        document.querySelectorAll('.forma').forEach(b => b.classList.remove('selecionada'));
        modal.abrir('modal-pagamento');
    }

    document.querySelectorAll('.forma').forEach(b => {
        b.addEventListener('click', () => {
            document.querySelectorAll('.forma').forEach(x => x.classList.remove('selecionada'));
            b.classList.add('selecionada');
            formaPagamento = b.dataset.forma;
            formaPagamentoSelecionada = true;
            document.getElementById('dinheiro-area').hidden = (formaPagamento !== 'dinheiro');
            if (formaPagamento === 'dinheiro') {
                document.getElementById('pag-recebido').focus();
                document.getElementById('pag-recebido').select();
            }
        });
    });

    document.getElementById('pag-recebido')?.addEventListener('input', (e) => {
        const recebido = Number(e.target.value) || 0;
        const troco = Math.max(0, recebido - carrinho.total());
        document.getElementById('pag-troco').textContent = fmt.moeda(troco);
    });

    document.getElementById('btn-finalizar-pedido')?.addEventListener('click', async () => {
        if (!formaPagamentoSelecionada) return toast('Escolha a forma de pagamento', 'aviso');

        const total = carrinho.total();
        const recebido = formaPagamento === 'dinheiro'
            ? Number(document.getElementById('pag-recebido').value) || 0
            : null;

        if (formaPagamento === 'dinheiro' && recebido < total) {
            return toast('Valor recebido menor que o total', 'erro');
        }

        const snap = carrinho.snapshot();
        const payload = {
            ...snap,
            forma_pagamento: formaPagamento,
            valor_recebido:  recebido,
            valor_troco:     recebido !== null ? Math.max(0, recebido - total) : null,
        };

        const stop = loading('Finalizando venda…');
        try {
            const r = await api.finalizarVenda(payload);
            ultimaVendaId = r.venda_id;
            atualizarBotaoCupom();
            modal.fechar('modal-pagamento');
            carrinho.limpar();

            const msg = r.offline
                ? `Venda salva offline (#${r.venda_id}). Será sincronizada.`
                : `Pedido ${r.numero_pedido ?? '#' + r.venda_id} finalizado!`;
            toast(msg, r.offline ? 'aviso' : 'sucesso', 5000);
            window.__pdvSync?.atualizar();
            buscaInput?.focus();
        } catch (err) {
            toast('Erro ao finalizar: ' + err.message, 'erro', 6000);
        } finally { stop(); }
    });

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

    document.getElementById('fc-confirmar')?.addEventListener('click', async () => {
        const informado = Number(document.getElementById('fc-informado').value);
        const obs = document.getElementById('fc-obs').value.trim();
        if (!confirm('Confirmar o fechamento do caixa? Esta ação encerra a sessão.')) return;
        const stop = loading('Fechando caixa…');
        try {
            await api.caixaFechar({ valor_informado: informado, observacao: obs });
            toast('Caixa fechado com sucesso', 'sucesso');
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            toast(err.message, 'erro');
        } finally { stop(); }
    });
}

// ---------- Helper compartilhado ----------
function escapar(s) {
    return String(s ?? '').replace(/[<>&"']/g, c => ({
        '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;','\'':'&#39;'
    }[c]));
}

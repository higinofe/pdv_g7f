// Terminal de Comanda — sem login, só roda com caixa fechado.
// Fluxo:
//   1. Bipa a comanda → carrega itens existentes.
//   2. Bipa produtos para adicionar à comanda.
//   3. "Finalizar" só sai do terminal — a comanda permanece aberta no banco
//      até ser baixada por um caixa (ver venda_finalizar.php).

import { fmt, toast, loading } from './ui.js';
import { instalarSistema } from './sistema.js';

instalarSistema();

const body         = document.body;
const pdvId        = body.dataset.pdvId   || '001';
const pdvNome      = body.dataset.pdvNome || 'PDV';

const telaBip      = document.getElementById('tela-bip');
const telaItens    = document.getElementById('tela-itens');
const inpBipComanda= document.getElementById('bip-comanda');
const inpBipErro   = document.getElementById('bip-erro');
const inpBipProduto= document.getElementById('bip-produto');
const inpBipQtd    = document.getElementById('bip-qtd');
const elCodigo     = document.getElementById('comanda-codigo');
const elDescricao  = document.getElementById('comanda-descricao');
const elTotal      = document.getElementById('comanda-total');
const elGrid       = document.getElementById('comanda-grid-body');

let comandaAtual = null;   // { id, codigo, descricao, ... }
let itensAtuais  = [];     // array de itens vindo do backend

// ---------- API local ----------
async function req(url, opts = {}) {
    const o = { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, ...opts };
    if (o.body && typeof o.body !== 'string') {
        o.headers['Content-Type'] = 'application/json; charset=utf-8';
        o.body = JSON.stringify(o.body);
    }
    let r, j;
    try {
        r = await fetch(url, o);
        j = await r.json();
    } catch {
        throw new Error('Falha de comunicação com o servidor');
    }
    if (!r.ok || j.sucesso === false) {
        const e = new Error(j.erro || 'Erro');
        e.status = r.status;
        throw e;
    }
    return j;
}

const api = {
    sync:          ()                 => req('/api/comandas_sync.php', { method: 'POST', body: {} }),
    buscarComanda: (codigo)           => req(`/api/comanda_buscar.php?codigo=${encodeURIComponent(codigo)}`),
    buscarProduto: (valor)            => req(`/api/comanda_buscar_produto.php?valor=${encodeURIComponent(valor)}`),
    lancarItem:    (codigo, prod, q)  => req('/api/comanda_lancar_item.php',  {
        method: 'POST',
        body: { comanda_codigo: codigo, produto_codigo: prod, quantidade: q },
    }),
    removerItem:   (codigo, itemId)   => req('/api/comanda_remover_item.php', {
        method: 'POST',
        body: { comanda_codigo: codigo, item_id: itemId },
    }),
};

// ---------- Relógio ----------
function tickRelogio() {
    const el = document.getElementById('relogio');
    if (el) el.textContent = new Date().toLocaleTimeString('pt-BR');
}
setInterval(tickRelogio, 1000);
tickRelogio();

// ---------- Voltar / sair ----------
document.getElementById('btn-voltar')?.addEventListener('click', voltar);
function voltar() {
    window.location.href = '/';
}

// ---------- Sync com ERP ----------
async function sincronizar({ silencioso = false } = {}) {
    const stop = silencioso ? () => {} : loading('Sincronizando comandas com o ERP…');
    try {
        const r = await api.sync();
        if (!silencioso) toast(r.mensagem || 'Sincronização concluída', 'sucesso', 4500);
        return r;
    } catch (err) {
        if (!silencioso) toast('Falha: ' + err.message, 'erro', 5000);
        throw err;
    } finally { stop(); }
}

document.getElementById('btn-sync')?.addEventListener('click', () => sincronizar());

// ---------- Estado 1: bipar comanda ----------
function focarBipComanda() {
    inpBipErro.textContent = '';
    inpBipComanda.value = '';
    setTimeout(() => inpBipComanda.focus(), 50);
}

inpBipComanda?.addEventListener('keydown', async (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const codigo = inpBipComanda.value.trim();
    if (!codigo) return;
    inpBipErro.textContent = '';
    const stop = loading('Carregando comanda…');
    try {
        let r;
        try {
            r = await api.buscarComanda(codigo);
        } catch (err) {
            // Não achou local? Tenta um sync rápido com o ERP antes de desistir.
            // Cobre o caso comum de comanda recém-criada no ERP que o PDV ainda
            // não puxou.
            if (err.status === 404) {
                try { await sincronizar({ silencioso: true }); } catch {}
                r = await api.buscarComanda(codigo);
            } else {
                throw err;
            }
        }
        if (r.comanda.status !== 'aberta') {
            throw new Error('Comanda já finalizada');
        }
        abrirComanda(r.comanda, r.itens, r.total);
    } catch (err) {
        inpBipErro.textContent = err.status === 404
            ? `Comanda "${codigo}" não encontrada. Pressione F5 para atualizar do ERP.`
            : (err.message || 'Erro ao buscar comanda');
        inpBipComanda.select();
    } finally { stop(); }
});

// ---------- Estado 2: lançar itens ----------
function abrirComanda(comanda, itens, total) {
    comandaAtual = comanda;
    itensAtuais  = itens || [];
    elCodigo.textContent    = comanda.codigo;
    elDescricao.textContent = comanda.descricao ? ' — ' + comanda.descricao : '';
    renderItens(total);
    telaBip.hidden = true;
    telaItens.hidden = false;
    inpBipProduto.value = '';
    inpBipQtd.value = 1;
    setTimeout(() => inpBipProduto.focus(), 50);
}

function fecharComanda() {
    comandaAtual = null;
    itensAtuais  = [];
    telaItens.hidden = true;
    telaBip.hidden = false;
    focarBipComanda();
}

document.getElementById('btn-trocar')?.addEventListener('click', fecharComanda);

document.getElementById('btn-finalizar')?.addEventListener('click', finalizar);
function finalizar() {
    if (!comandaAtual) return;
    if (itensAtuais.length === 0) {
        if (!confirm('Nenhum item lançado. Voltar para a tela inicial?')) return;
    } else {
        const msg = `Encerrar lançamento da comanda ${comandaAtual.codigo}?\n` +
                    `Os ${itensAtuais.length} item(ns) ficam salvos — o cliente paga no caixa.`;
        if (!confirm(msg)) return;
    }
    fecharComanda();
}

inpBipProduto?.addEventListener('keydown', async (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const codigo = inpBipProduto.value.trim();
    if (!codigo || !comandaAtual) return;
    const qtd = Number(inpBipQtd.value) || 1;
    if (qtd <= 0) return toast('Quantidade inválida', 'aviso');

    const stop = loading('Lançando item…');
    try {
        // Confere o produto existe antes de pedir o lançamento (mensagem melhor).
        await api.buscarProduto(codigo);
        const r = await api.lancarItem(comandaAtual.codigo, codigo, qtd);
        itensAtuais = r.itens;
        renderItens(r.total);
        inpBipProduto.value = '';
        inpBipQtd.value = 1;
        inpBipProduto.focus();
    } catch (err) {
        toast(err.message || 'Erro ao lançar item', 'erro');
        inpBipProduto.select();
    } finally { stop(); }
});

function renderItens(totalServidor) {
    if (itensAtuais.length === 0) {
        elGrid.innerHTML = `<tr class="vazio"><td colspan="6">Nenhum item lançado — bipe um produto</td></tr>`;
        elTotal.textContent = fmt.moeda(0);
        return;
    }
    elGrid.innerHTML = itensAtuais.map((it, idx) => `
        <tr data-id="${it.id}">
            <td class="col-seq">${idx + 1}</td>
            <td>${escapar(it.descricao)}</td>
            <td class="col-num">${fmt.qtd(it.quantidade)}</td>
            <td class="col-num">${fmt.moeda(it.preco_unitario)}</td>
            <td class="col-num"><strong>${fmt.moeda(it.subtotal)}</strong></td>
            <td class="col-acao"><button class="btn-mini danger" data-acao="remover">×</button></td>
        </tr>`).join('');

    elGrid.querySelectorAll('button[data-acao="remover"]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = Number(btn.closest('tr').dataset.id);
            if (!confirm('Remover este item?')) return;
            const stop = loading('Removendo…');
            try {
                const r = await api.removerItem(comandaAtual.codigo, id);
                itensAtuais = r.itens;
                renderItens(r.total);
            } catch (err) {
                toast(err.message, 'erro');
            } finally { stop(); }
        });
    });

    const total = typeof totalServidor === 'number'
        ? totalServidor
        : itensAtuais.reduce((s, i) => s + Number(i.subtotal || 0), 0);
    elTotal.textContent = fmt.moeda(total);
}

function escapar(s) {
    return String(s ?? '').replace(/[<>&"']/g, c => ({
        '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;','\'':'&#39;'
    }[c]));
}

// ---------- Atalhos globais ----------
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        // Esc: trocar comanda; se já estiver na tela de bip, volta pro index
        if (comandaAtual) fecharComanda();
        else voltar();
        return;
    }
    if (e.key === 'F9') {
        e.preventDefault();
        finalizar();
        return;
    }
    if (e.key === 'F5') {
        // F5 = atualizar lista de comandas a partir do ERP. Sobrescreve o reload
        // do navegador (que no terminal kiosk não tem efeito útil).
        e.preventDefault();
        sincronizar();
        return;
    }
});

// Inicializa focado no campo da comanda
focarBipComanda();

// Sincronização automática ao abrir o terminal — garante que o operador
// possa bipar comandas recém-criadas no ERP sem precisar clicar em ⟳.
// Em segundo plano: se falhar, o operador ainda consegue digitar e o
// fallback no Enter tentará sincronizar de novo.
sincronizar({ silencioso: true }).catch(() => {});

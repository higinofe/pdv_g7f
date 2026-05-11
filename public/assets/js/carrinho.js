// Estado do carrinho de venda (em memória) + render da tabela.

import { fmt } from './ui.js';

const itens = [];
let descontoVenda = 0;     // desconto aplicado ao total da venda (R$)
let indiceSelecionado = -1;

export function adicionar(produto, quantidade = 1) {
    quantidade = Number(quantidade) || 1;
    if (quantidade <= 0) return;

    // Se o mesmo produto já está no carrinho, incrementa
    const existente = itens.find(i => i.produto_codigo === produto.codigo);
    if (existente) {
        existente.quantidade += quantidade;
        recalcularItem(existente);
    } else {
        itens.push({
            produto_erp_id: produto.erp_id ?? null,
            produto_codigo: produto.codigo,
            descricao:      produto.descricao,
            quantidade,
            preco_unitario: Number(produto.preco) || 0,
            desconto:       0,
            subtotal:       quantidade * (Number(produto.preco) || 0),
        });
        indiceSelecionado = itens.length - 1;
    }
    render();
}

export function removerSelecionado() {
    if (indiceSelecionado < 0 || indiceSelecionado >= itens.length) return false;
    itens.splice(indiceSelecionado, 1);
    if (indiceSelecionado >= itens.length) indiceSelecionado = itens.length - 1;
    render();
    return true;
}

export function selecionar(indice) {
    indiceSelecionado = indice;
    render();
}

export function limpar() {
    itens.length = 0;
    descontoVenda = 0;
    indiceSelecionado = -1;
    render();
}

export function aplicarDesconto(valor, tipo) {
    valor = Number(valor) || 0;
    const sub = subtotalBruto();
    if (tipo === 'percentual') {
        descontoVenda = sub * (valor / 100);
    } else {
        descontoVenda = valor;
    }
    if (descontoVenda > sub) descontoVenda = sub;
    if (descontoVenda < 0) descontoVenda = 0;
    render();
}

function recalcularItem(item) {
    item.subtotal = (item.quantidade * item.preco_unitario) - (item.desconto || 0);
}

function subtotalBruto() {
    return itens.reduce((s, i) => s + i.subtotal, 0);
}

export function total() {
    return Math.max(0, subtotalBruto() - descontoVenda);
}

export function snapshot() {
    return {
        itens: itens.map(i => ({ ...i })),
        valor_total:    total(),
        valor_desconto: descontoVenda,
    };
}

export function vazio() { return itens.length === 0; }
export function ultimo() { return itens[itens.length - 1] || null; }

function render() {
    const tbody = document.getElementById('carrinho-body');
    if (!tbody) return;

    if (itens.length === 0) {
        tbody.innerHTML = `<tr class="vazio"><td colspan="6">Carrinho vazio — bipe um produto para começar</td></tr>`;
    } else {
        tbody.innerHTML = itens.map((it, idx) => `
            <tr data-idx="${idx}" class="${idx === indiceSelecionado ? 'selecionado' : ''}">
                <td class="col-item">${idx + 1}</td>
                <td class="col-cod">${escapar(it.produto_codigo)}</td>
                <td class="col-desc">${escapar(it.descricao)}</td>
                <td class="col-num">${fmt.qtd(it.quantidade)}</td>
                <td class="col-num">${fmt.moeda(it.preco_unitario)}</td>
                <td class="col-num"><strong>${fmt.moeda(it.subtotal)}</strong></td>
            </tr>
        `).join('');

        tbody.querySelectorAll('tr').forEach(tr => {
            tr.addEventListener('click', () => {
                indiceSelecionado = Number(tr.dataset.idx);
                render();
            });
        });
    }

    // Totalização
    document.getElementById('total-valor').textContent    = fmt.moeda(total());
    document.getElementById('total-itens').textContent    = itens.length;
    document.getElementById('total-desconto').textContent = fmt.moeda(descontoVenda);

    const ult = ultimo();
    const ultEl = document.getElementById('ultimo-item-desc');
    if (ultEl) ultEl.textContent = ult ? `${ult.descricao} — ${fmt.moeda(ult.subtotal)}` : '—';
}

function escapar(s) {
    return String(s ?? '').replace(/[<>&"']/g, c => ({
        '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;','\'':'&#39;'
    }[c]));
}

// Render inicial vazio
document.addEventListener('DOMContentLoaded', render);

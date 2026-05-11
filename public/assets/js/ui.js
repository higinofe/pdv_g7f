// Helpers de UI: formatação, toasts, loading, abrir/fechar modais.

export const fmt = {
    moeda(v) {
        const n = Number(v) || 0;
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    },
    qtd(v) {
        const n = Number(v) || 0;
        return n.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
    },
};

export function toast(mensagem, tipo = 'sucesso', duracao = 3500) {
    const wrap = document.getElementById('toasts');
    if (!wrap) return alert(mensagem);
    const el = document.createElement('div');
    el.className = `toast ${tipo}`;
    el.textContent = mensagem;
    wrap.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(40px)';
        el.style.transition = 'all .25s';
        setTimeout(() => el.remove(), 260);
    }, duracao);
}

let loadingTimer = null;
export function loading(mensagem = 'Processando…') {
    const el = document.getElementById('loading');
    document.getElementById('loading-msg').textContent = mensagem;
    el.hidden = false;
    return () => { el.hidden = true; };
}

export const modal = {
    abrir(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.hidden = false;
        const primeiro = el.querySelector('input, button');
        if (primeiro) setTimeout(() => primeiro.focus(), 50);
    },
    fechar(id) {
        const el = document.getElementById(id);
        if (el) el.hidden = true;
    },
    fecharTodos() {
        document.querySelectorAll('.modal').forEach(m => m.hidden = true);
    },
    aberto() {
        return document.querySelector('.modal:not([hidden])');
    },
};

// Fecha qualquer modal ao clicar no botão × ou no overlay
document.addEventListener('click', (e) => {
    if (e.target.matches('[data-fechar]')) {
        const m = e.target.closest('.modal');
        if (m) m.hidden = true;
    }
    if (e.target.classList.contains('modal')) {
        e.target.hidden = true;
    }
});

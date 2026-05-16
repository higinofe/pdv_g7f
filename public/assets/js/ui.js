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
        // Foca o primeiro campo editável visível; só recorre a botão (e nunca
        // ao × do cabeçalho) se o modal não tiver inputs. Com a busca em ordem
        // de documento o × vinha primeiro e roubava o Enter → fechava o modal.
        setTimeout(() => {
            const visivel = (n) => n.offsetParent !== null;
            // Tipos editáveis primeiro (text/number/password/etc.) — assim
            // não focamos um radio/checkbox quando há um campo de texto logo
            // depois (caso típico: modal de desconto, com radios e o input
            // de valor lado a lado).
            const editaveis = [...el.querySelectorAll(
                'input[type="text"],input[type="number"],input[type="password"],' +
                'input[type="email"],input[type="tel"],input[type="search"],' +
                'input[type="url"],input:not([type]),textarea,select')];
            const outros = [...el.querySelectorAll(
                'input:not([type="hidden"]):not([disabled]),select,textarea')];
            const botoes = [...el.querySelectorAll(
                'button:not(.btn-fechar):not([disabled])')];
            const alvo = editaveis.find(visivel) || outros.find(visivel) || botoes.find(visivel);
            if (!alvo) return;
            alvo.focus();
            if (typeof alvo.select === 'function') {
                try { alvo.select(); } catch { /* number/date podem rejeitar */ }
            }
        }, 50);
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

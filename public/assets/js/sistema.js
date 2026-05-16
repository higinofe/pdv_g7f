// Reiniciar / Desligar o computador do PDV pela interface.
// Usado tanto pela frente de caixa quanto pelo terminal de comanda — por
// isso é um módulo isolado, sem depender de api.js (comanda.js não importa
// api.js para evitar pegar a sessão).

import { toast, loading, modal } from './ui.js';

const acoes = {
    reboot: {
        rotulo: 'Reiniciar',
        verbo:  'reiniciar',
        msg:    'O computador será reiniciado em instantes.',
    },
    shutdown: {
        rotulo: 'Desligar',
        verbo:  'desligar',
        msg:    'O computador será desligado em instantes.',
    },
};

async function executar(acao) {
    const def = acoes[acao];
    if (!def) return;
    // Confirmação dupla — evita acionamento acidental em meio a uma venda.
    if (!confirm(`Tem certeza que deseja ${def.verbo.toUpperCase()} o computador?`)) return;

    const stop = loading(`${def.rotulo} o computador…`);
    try {
        const r = await fetch('/api/sistema_acao.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json; charset=utf-8', 'Accept': 'application/json' },
            body: JSON.stringify({ acao }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.sucesso === false) {
            throw new Error(j.erro || 'Falha ao acionar comando do sistema');
        }
        toast(def.msg, 'sucesso', 8000);
        // Mantém o overlay de loading: a máquina vai cair junto.
    } catch (err) {
        stop();
        toast(err.message || 'Erro inesperado', 'erro', 6000);
    }
}

/**
 * Conecta o botão #btn-sistema, o modal #modal-sistema e o atalho Ctrl+Shift+P.
 *
 * @param {{caixaAberto?: boolean}} opts
 *        Quando caixaAberto=true, mostra um aviso no modal antes das ações.
 */
export function instalarSistema(opts = {}) {
    const md = document.getElementById('modal-sistema');
    if (!md) return; // tela sem o modal — nada a fazer

    const aviso = document.getElementById('sistema-aviso-caixa');
    if (aviso) aviso.hidden = !opts.caixaAberto;

    const abrir = () => modal.abrir('modal-sistema');

    document.querySelectorAll('#btn-sistema').forEach(b => {
        b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); abrir(); });
    });

    document.getElementById('btn-sistema-reiniciar')?.addEventListener('click', () => executar('reboot'));
    document.getElementById('btn-sistema-desligar')?.addEventListener('click', () => executar('shutdown'));

    // Atalho global: Ctrl+Shift+P abre o modal de Sistema em qualquer tela.
    // Captura na fase de captura para passar à frente de qualquer input.
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.shiftKey && !e.altKey && e.key.toLowerCase() === 'p') {
            e.preventDefault();
            e.stopPropagation();
            abrir();
        }
    }, true);
}

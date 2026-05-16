<?php
require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\Env;
use App\Models\Caixa;

$pdvNome = Env::get('PDV_NOME', 'PDV');
$pdvId   = Env::get('PDV_ID',   '001');

// Regra do briefing: o terminal de comanda só existe com o caixa fechado.
// Se alguém abrir a URL com caixa aberto, devolve pro index (que mostra
// a frente de caixa).
if (Caixa::sessaoAberta((string) $pdvId)) {
    header('Location: /', true, 302);
    exit;
}

$cssVer = @filemtime(__DIR__ . '/assets/css/app.css') ?: time();
$jsVer  = max(
    @filemtime(__DIR__ . '/assets/js/comanda.js') ?: 0,
    @filemtime(__DIR__ . '/assets/js/ui.js')      ?: 0,
    @filemtime(__DIR__ . '/assets/js/sistema.js') ?: 0,
) ?: time();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <title>Terminal de Comanda — <?= htmlspecialchars($pdvNome) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVer ?>">
</head>
<body class="comanda-screen"
      data-pdv-id="<?= htmlspecialchars($pdvId) ?>"
      data-pdv-nome="<?= htmlspecialchars($pdvNome) ?>">

    <header class="comanda-topbar">
        <div class="comanda-titulo">
            <strong>Terminal de Comanda</strong>
            <span class="comanda-pdv">Caixa <?= htmlspecialchars($pdvId) ?> · <?= htmlspecialchars($pdvNome) ?></span>
        </div>
        <div class="comanda-info">
            <span class="comanda-relogio" id="relogio">--:--:--</span>
            <button type="button" class="btn-sair" id="btn-sistema" title="Reiniciar / Desligar (Ctrl+Shift+P)">⏻ Sistema</button>
            <button type="button" class="btn-sair" id="btn-voltar" title="Voltar à tela inicial (Esc)">⎋ Sair</button>
        </div>
    </header>

    <main class="comanda-main">
        <!-- Estado 1: nenhuma comanda aberta — pede para bipar -->
        <section class="comanda-bip" id="tela-bip">
            <h1>Bipe a comanda</h1>
            <p class="hint">Aponte o leitor ou digite o código da comanda e pressione <kbd>Enter</kbd>.</p>
            <input type="text" id="bip-comanda" class="bip-input"
                   placeholder="Código da comanda" autocomplete="off"
                   autofocus inputmode="numeric">
            <p class="msg-erro" id="bip-erro"></p>
            <div class="comanda-bip-rodape">
                <button type="button" class="btn-secundario" id="btn-sync" title="Atualizar lista de comandas (F5)">⟳ Sincronizar com ERP <span class="kbd-inline">F5</span></button>
            </div>
        </section>

        <!-- Estado 2: comanda aberta — bipa produtos -->
        <section class="comanda-aberta" id="tela-itens" hidden>
            <div class="comanda-cabecalho">
                <div>
                    <span class="comanda-label">Comanda</span>
                    <strong class="comanda-codigo" id="comanda-codigo">—</strong>
                    <span class="comanda-descricao" id="comanda-descricao"></span>
                </div>
                <button type="button" class="btn-secundario" id="btn-trocar">Trocar comanda</button>
            </div>

            <div class="comanda-bip-produto">
                <label for="bip-produto">Bipe o produto</label>
                <input type="text" id="bip-produto" class="bip-input"
                       placeholder="Código ou código de barras" autocomplete="off"
                       inputmode="numeric">
                <div class="comanda-qtd">
                    <label for="bip-qtd">Qtde</label>
                    <input type="number" id="bip-qtd" value="1" min="0.001" step="0.001">
                </div>
            </div>

            <div class="comanda-grid-wrap">
                <table class="comanda-grid">
                    <thead>
                        <tr>
                            <th class="col-seq">SEQ</th>
                            <th>Descrição</th>
                            <th class="col-num">Qtde</th>
                            <th class="col-num">Preço</th>
                            <th class="col-num">Subtotal</th>
                            <th class="col-acao"></th>
                        </tr>
                    </thead>
                    <tbody id="comanda-grid-body">
                        <tr class="vazio"><td colspan="6">Nenhum item lançado — bipe um produto</td></tr>
                    </tbody>
                </table>
            </div>

            <footer class="comanda-rodape">
                <div class="comanda-total">
                    <span>Total parcial</span>
                    <strong id="comanda-total">R$ 0,00</strong>
                </div>
                <button type="button" class="btn-primario grande" id="btn-finalizar">
                    Finalizar (cliente paga no caixa) — <kbd>F9</kbd>
                </button>
            </footer>
        </section>
    </main>

    <div class="toast-wrap" id="toasts"></div>
    <div class="loading" id="loading" hidden><div class="spinner"></div><p id="loading-msg">Processando…</p></div>

    <!-- Modal compartilhado: Reiniciar / Desligar — comanda.js também aciona via Ctrl+Shift+P / botão. -->
    <div class="modal" id="modal-sistema" hidden>
        <div class="modal-card glass sistema">
            <header><h2>Sistema</h2><button class="btn-fechar" data-fechar>×</button></header>
            <div class="modal-body">
                <div class="sistema-acoes">
                    <button type="button" class="btn-secundario grande" id="btn-sistema-reiniciar">
                        <span class="sistema-icone">⟳</span>
                        <span>Reiniciar</span>
                        <small>desliga e religa o computador</small>
                    </button>
                    <button type="button" class="btn-secundario grande" id="btn-sistema-desligar">
                        <span class="sistema-icone">⏻</span>
                        <span>Desligar</span>
                        <small>desliga o computador</small>
                    </button>
                </div>
                <p class="hint">Atalho: <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>P</kbd></p>
            </div>
        </div>
    </div>

    <script type="importmap">
    {
      "imports": {
        "/assets/js/ui.js":      "/assets/js/ui.js?v=<?= $jsVer ?>",
        "/assets/js/sistema.js": "/assets/js/sistema.js?v=<?= $jsVer ?>"
      }
    }
    </script>
    <script src="/assets/js/comanda.js?v=<?= $jsVer ?>" type="module"></script>
</body>
</html>

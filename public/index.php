<?php
require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\Auth;
use App\Helpers\Env;
use App\Models\Caixa;

$logado   = Auth::logado();
$operador = Auth::operador();
$pdvNome  = Env::get('PDV_NOME', 'PDV');
$pdvId    = Env::get('PDV_ID', '001');
$ehAdmin  = $logado && (($operador['perfil'] ?? '') === 'admin');

// Estado do caixa SEMPRE consultado no BD, independente da sessão do operador.
// Quem fecha o caixa é só caixa_fechar.php — qualquer outra situação (sessão
// PHP expirada, hard reload, troca de operador via Ctrl+R) NÃO pode fazer um
// caixa que continua "aberta" no banco parecer fechado pra quem está olhando.
$sessaoCaixa = Caixa::sessaoAberta((string) $pdvId);
$caixaAberto = $sessaoCaixa !== null;

// Estado do app:
//  - splash: ninguém logado OU caixa fechado → slideshow com login do operador
//  - venda : logado e caixa aberto → frente de caixa
$emSplash = !$logado || !$caixaAberto;
$bodyClass = $emSplash ? 'splash-screen' : 'app';

// Cache-busting: usa o maior mtime entre os JS (muda ao redeploy ou quando
// qualquer módulo é editado — evita servir api.js/ui.js/carrinho.js antigos
// do cache enquanto app.js já foi rebuscado).
$cssVer = @filemtime(__DIR__ . '/assets/css/app.css') ?: time();
$jsVer  = max(
    @filemtime(__DIR__ . '/assets/js/app.js')      ?: 0,
    @filemtime(__DIR__ . '/assets/js/api.js')      ?: 0,
    @filemtime(__DIR__ . '/assets/js/ui.js')       ?: 0,
    @filemtime(__DIR__ . '/assets/js/carrinho.js') ?: 0,
    @filemtime(__DIR__ . '/assets/js/sistema.js')  ?: 0,
) ?: time();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <title>PDV — <?= htmlspecialchars($pdvNome) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVer ?>">
</head>
<body class="<?= $bodyClass ?>"
      data-pdv-id="<?= htmlspecialchars($pdvId) ?>"
      data-pdv-nome="<?= htmlspecialchars($pdvNome) ?>"
      data-logado="<?= $logado ? '1' : '0' ?>"
      data-caixa-aberto="<?= $caixaAberto ? '1' : '0' ?>"
      data-eh-admin="<?= $ehAdmin ? '1' : '0' ?>"
      data-em-splash="<?= $emSplash ? '1' : '0' ?>"
      data-operador='<?= $operador ? json_encode($operador, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) : "null" ?>'>

    <?php if ($emSplash): ?>
        <!-- ============== SPLASH / SLIDESHOW (caixa fechado ou sem operador) ============== -->
        <main class="splash" id="splash">
            <div class="splash-stage">
                <div class="splash-slides" id="splash-slides">
                    <!-- slides serão injetados pelo JS -->
                </div>
                <div class="splash-progress" id="splash-progress">
                    <!-- barras de progresso (uma por slide) -->
                </div>
                <div class="splash-fallback" id="splash-fallback" hidden>
                    <h1><?= htmlspecialchars($pdvNome) ?></h1>
                    <p>Caixa <?= htmlspecialchars($pdvId) ?></p>
                </div>
            </div>

            <div class="splash-cta">
                <span class="splash-relogio" id="relogio">--:--:--</span>
                <span class="splash-msg">
                    <?= $caixaAberto
                        ? 'Caixa aberto — pressione qualquer tecla para identificar o operador'
                        : 'Pressione qualquer tecla ou toque na tela' ?>
                </span>
                <span class="splash-pdv">
                    <span class="splash-pdv-rotulo">Caixa <?= htmlspecialchars($pdvId) ?> · <?= htmlspecialchars($pdvNome) ?></span>
                    <span class="splash-estado-caixa <?= $caixaAberto ? 'aberto' : 'fechado' ?>"
                          title="<?= $caixaAberto
                              ? 'Caixa aberto em ' . htmlspecialchars($sessaoCaixa['aberto_em'] ?? '') . ' por ' . htmlspecialchars($sessaoCaixa['operador_nome'] ?? '')
                              : 'Caixa fechado — abertura requer identificação do operador' ?>">
                        <span class="splash-estado-bolinha"></span>
                        Caixa <?= $caixaAberto ? 'ABERTO' : 'FECHADO' ?>
                    </span>
                    <button type="button" class="splash-comanda" id="btn-comanda"
                            title="<?= $caixaAberto
                                ? 'Modo comanda só está disponível com o caixa fechado'
                                : 'Terminal de Comanda (F4)' ?>">Comanda <span class="kbd-inline">F4</span></button>
                    <button type="button" class="splash-help" id="btn-ajuda" title="Ajuda (Ctrl+H)">Ajuda <span class="kbd-inline">Ctrl+H</span></button>
                    <button type="button" class="splash-sistema" id="btn-sistema" title="Reiniciar / Desligar (Ctrl+Shift+P)">⏻ Sistema <span class="kbd-inline">Ctrl+Shift+P</span></button>
                </span>
            </div>
        </main>

        <!-- Modal: identificação do operador -->
        <div class="modal" id="modal-operador-login" hidden>
            <div class="modal-card glass">
                <header><h2>Identificação do Operador</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <form id="form-operador-login" autocomplete="off">
                        <label>Usuário
                            <input type="text" id="op-login-email" autocomplete="off"
                                   autocapitalize="off" spellcheck="false"
                                   data-lpignore="true" data-form-type="other" required>
                        </label>
                        <label>PIN (4 dígitos)
                            <input type="password" id="op-login-senha" inputmode="numeric" pattern="[0-9]*" maxlength="4"
                                   autocomplete="off" data-lpignore="true" data-form-type="other" required>
                        </label>
                        <button type="submit" class="btn-primario grande">Entrar</button>
                        <p class="msg-erro" id="op-login-erro"></p>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal: abrir caixa (após login com sucesso) -->
        <div class="modal" id="modal-abrir-caixa" hidden>
            <div class="modal-card glass">
                <header><h2>Abertura de Caixa</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <p class="hint">Olá, <strong id="ac-nome-operador">operador</strong>. Informe o valor inicial em dinheiro (fundo de troco).</p>
                    <label>Valor de abertura (R$)
                        <input type="number" id="ac-valor" step="0.01" min="0" value="0" autocomplete="off">
                    </label>
                    <label>Observação (opcional)
                        <input type="text" id="ac-obs" placeholder="Ex.: troco inicial" autocomplete="off">
                    </label>
                    <button class="btn-primario grande" id="ac-confirmar">Confirmar Abertura</button>
                </div>
            </div>
        </div>

        <?php include __DIR__ . '/_partials/modal_ajuda.php'; ?>

        <div class="toast-wrap" id="toasts"></div>
        <div class="loading" id="loading" hidden><div class="spinner"></div><p id="loading-msg">Processando…</p></div>

    <?php else: ?>
        <!-- ============== TOPBAR ============== -->
        <header class="topbar">
            <div class="esquerda">
                <span class="op-rotulo">Operador:</span>
                <strong><?= htmlspecialchars($operador['nome'] ?? '') ?></strong>
                <span class="op-perfil"><?= htmlspecialchars($operador['perfil'] ?? '') ?></span>
                <span class="status-caixa aberto" title="Aberto em <?= htmlspecialchars($sessaoCaixa['aberto_em']) ?>">Caixa aberto</span>
            </div>
            <div class="direita">
                <button class="sync-status" id="sync-status" type="button"
                        title="Status da conexão com o ERP — clique para reenviar pendentes">
                    <span class="sync-dot"></span>
                    <span class="sync-label" id="sync-label">Verificando…</span>
                    <span class="sync-pendentes" id="sync-pendentes" hidden>0</span>
                </button>
                <span class="relogio" id="relogio">--:--:--</span>
                <button class="btn-icone" id="btn-operadores" title="Cadastro de operadores (requer admin)">👤</button>
                <button class="btn-icone" id="btn-sistema" title="Reiniciar / Desligar (Ctrl+Shift+P)">⏻</button>
                <button class="btn-icone" id="btn-sair" title="Fechar caixa e sair (F12 ou Ctrl+E)">⎋</button>
            </div>
        </header>

        <!-- ============== TELA DE FRENTE DE CAIXA ============== -->
        <main class="layout-venda">
            <section class="descricao-area">
                <label for="busca-produto">Descrição</label>
                <input type="text" id="busca-produto" class="campo-display"
                       placeholder="Bipe o código de barras ou digite o código do produto e pressione ENTER" autofocus
                       autocomplete="off" inputmode="numeric">
            </section>

            <section class="equacao">
                <div class="grupo">
                    <label for="qtd">Quantidade / Kg</label>
                    <input type="number" id="qtd" class="campo-display" value="1" min="0.001" step="0.001">
                </div>
                <span class="op-mat">X</span>
                <div class="grupo">
                    <label for="preco-unit">Preço Unitário / Kg</label>
                    <output id="preco-unit" class="campo-display">0,00</output>
                </div>
                <span class="op-mat">=</span>
                <div class="grupo">
                    <label for="valor-item">Valor / R$</label>
                    <output id="valor-item" class="campo-display">0,00</output>
                </div>
            </section>

            <section class="grid-area">
                <div class="grid-itens-wrap">
                    <table class="grid-itens">
                        <thead>
                            <tr>
                                <th class="col-seq">SEQ</th>
                                <th>Descrição</th>
                                <th class="col-num">Qtde / Kg</th>
                                <th class="col-num">Preço R$/Kg</th>
                                <th class="col-num">Subtotal R$</th>
                            </tr>
                        </thead>
                        <tbody id="carrinho-body">
                            <tr class="vazio"><td colspan="5">Nenhum item registrado</td></tr>
                        </tbody>
                    </table>
                </div>

                <aside class="total-cupom">
                    <label for="total-valor">Total Cupom R$</label>
                    <output id="total-valor" class="campo-display campo-total">0,00</output>
                </aside>
            </section>

            <span id="total-itens" hidden>0</span>
            <span id="total-desconto" hidden>R$ 0,00</span>
            <span id="ultimo-item-desc" hidden>—</span>

            <div hidden>
                <button class="atalho" data-acao="consulta"></button>
                <button class="atalho" data-acao="calculadora"></button>
                <button class="atalho" data-acao="carga"></button>
                <button class="atalho" data-acao="cancelar-item"></button>
                <button class="atalho" data-acao="cancelar-venda"></button>
                <button class="atalho" data-acao="desconto"></button>
                <button class="atalho" data-acao="sangria"></button>
                <button class="atalho" data-acao="reforco"></button>
                <button class="atalho" data-acao="finalizar"></button>
                <button class="atalho" data-acao="cupom" disabled></button>
                <button class="atalho" data-acao="fechar-caixa"></button>
            </div>
        </main>

        <footer class="rodape">
            <button type="button" class="rodape-botao" id="btn-ajuda" title="Ajuda (Ctrl+H)">
                <span class="kbd-inline">Ctrl+H</span> Ajuda
            </button>
            <button type="button" class="rodape-botao" id="btn-reiniciar" title="Reiniciar caixa — troca de operador (Ctrl+R)">
                <span class="kbd-inline">Ctrl+R</span> Reiniciar
            </button>
            <span class="rodape-spacer"></span>
            <span>ECF: <strong id="status-ecf">DESATIVADO</strong></span>
            <span>STATUS: <strong id="status-sistema">VERIFICANDO</strong></span>
        </footer>

        <!-- ============== MODAIS ============== -->
        <div class="modal" id="modal-fechar-caixa" hidden>
            <div class="modal-card glass fechar-caixa">
                <header><h2>Fechamento de Caixa</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <div class="resumo-caixa" id="fc-resumo">
                        <div class="linha"><span>Abertura</span><strong id="fc-abertura">R$ 0,00</strong></div>
                        <div class="linha"><span>Vendas em dinheiro</span><strong id="fc-vd-dinheiro">R$ 0,00</strong></div>
                        <div class="linha"><span>Reforços (+)</span><strong id="fc-reforco">R$ 0,00</strong></div>
                        <div class="linha"><span>Sangrias (−)</span><strong id="fc-sangria">R$ 0,00</strong></div>
                        <div class="linha destaque"><span>Esperado em dinheiro</span><strong id="fc-esperado">R$ 0,00</strong></div>
                        <hr>
                        <div class="linha"><span>Vendas débito</span><strong id="fc-debito">R$ 0,00</strong></div>
                        <div class="linha"><span>Vendas crédito</span><strong id="fc-credito">R$ 0,00</strong></div>
                        <div class="linha"><span>Vendas PIX</span><strong id="fc-pix">R$ 0,00</strong></div>
                        <div class="linha"><span>Outras formas</span><strong id="fc-outros">R$ 0,00</strong></div>
                        <div class="linha total-fc"><span>Total faturado</span><strong id="fc-total">R$ 0,00</strong></div>
                    </div>
                    <label>Valor contado em dinheiro (R$)
                        <input type="number" id="fc-informado" step="0.01" min="0">
                    </label>
                    <div class="diferenca" id="fc-diferenca-wrap" hidden>
                        Diferença: <strong id="fc-diferenca">R$ 0,00</strong>
                    </div>
                    <label>Observação (opcional)
                        <input type="text" id="fc-obs" placeholder="Ex.: pequena diferença">
                    </label>
                    <button class="btn-primario grande" id="fc-confirmar">Confirmar Fechamento</button>
                </div>
            </div>
        </div>

        <div class="modal" id="modal-movimento" hidden>
            <div class="modal-card glass">
                <header><h2 id="mov-titulo">Sangria</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <p class="hint" id="mov-hint"></p>
                    <label>Valor (R$)<input type="number" id="mov-valor" step="0.01" min="0.01" autocomplete="off"></label>
                    <label>Motivo<input type="text" id="mov-motivo" placeholder="Ex.: retirada do malote" autocomplete="off"></label>
                    <button class="btn-primario grande" id="mov-confirmar">Confirmar</button>
                </div>
            </div>
        </div>

        <div class="modal" id="modal-operadores" hidden>
            <div class="modal-card glass operadores">
                <header><h2>Cadastro de Operadores</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <div class="operadores-layout">
                        <div class="op-lista">
                            <div class="op-toolbar">
                                <button class="btn-secundario" id="op-novo">+ Novo Operador</button>
                                <button class="btn-secundario" id="op-sync" title="Buscar operadores no ERP">⟳ Sincronizar com ERP</button>
                            </div>
                            <div class="op-tabela-wrap">
                                <table class="op-tabela">
                                    <thead><tr><th>Usuário</th><th>Nome</th><th>Perfil</th><th>Status</th><th></th></tr></thead>
                                    <tbody id="op-tabela-body"></tbody>
                                </table>
                            </div>
                        </div>
                        <form class="op-form" id="op-form" hidden>
                            <h3 id="op-form-titulo">Novo operador</h3>
                            <input type="hidden" id="op-id">
                            <label>Usuário<input type="text" id="op-usuario" required></label>
                            <label>Nome<input type="text" id="op-nome" required></label>
                            <label>Perfil
                                <select id="op-perfil">
                                    <option value="operador">Operador</option>
                                    <option value="admin">Administrador</option>
                                </select>
                            </label>
                            <label>Senha <small id="op-senha-hint">(mínimo 4 caracteres)</small>
                                <input type="password" id="op-senha" autocomplete="new-password">
                            </label>
                            <label class="check"><input type="checkbox" id="op-ativo" checked> Ativo</label>
                            <div class="row">
                                <button type="button" class="btn-secundario" id="op-cancelar">Cancelar</button>
                                <button type="submit" class="btn-primario">Salvar</button>
                            </div>
                            <p class="msg" id="op-msg"></p>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal" id="modal-consulta" hidden>
            <div class="modal-card glass">
                <header><h2>Consulta de Produto</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <input type="text" id="consulta-termo" placeholder="Buscar por código, EAN ou descrição..." autocomplete="off">
                    <div class="resultados" id="consulta-resultados"></div>
                </div>
            </div>
        </div>

        <div class="modal" id="modal-calc" hidden>
            <div class="modal-card glass calc">
                <header><h2>Calculadora</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <div class="calc-display" id="calc-display">0</div>
                    <div class="calc-keys">
                        <button data-k="C" class="op">C</button>
                        <button data-k="±" class="op">±</button>
                        <button data-k="%" class="op">%</button>
                        <button data-k="/" class="op">÷</button>
                        <button data-k="7">7</button><button data-k="8">8</button><button data-k="9">9</button>
                        <button data-k="*" class="op">×</button>
                        <button data-k="4">4</button><button data-k="5">5</button><button data-k="6">6</button>
                        <button data-k="-" class="op">−</button>
                        <button data-k="1">1</button><button data-k="2">2</button><button data-k="3">3</button>
                        <button data-k="+" class="op">+</button>
                        <button data-k="0" class="zero">0</button><button data-k=",">,</button>
                        <button data-k="=" class="igual">=</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal" id="modal-desconto" hidden>
            <div class="modal-card glass">
                <header><h2>Aplicar Desconto</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <div class="row">
                        <label><input type="radio" name="desc-tipo" value="valor" checked> Valor (R$)</label>
                        <label><input type="radio" name="desc-tipo" value="percentual"> Percentual (%)</label>
                    </div>
                    <input type="number" id="desc-valor" min="0" step="0.01" value="0">
                    <button class="btn-primario" id="desc-aplicar">Aplicar</button>
                </div>
            </div>
        </div>

        <div class="modal" id="modal-pagamento" hidden>
            <div class="modal-card glass pagamento">
                <header><h2>Finalizar Venda</h2><button class="btn-fechar" data-fechar>×</button></header>
                <div class="modal-body">
                    <div class="pag-resumo">
                        <div class="pag-resumo-linha">
                            <span>Total a pagar</span>
                            <strong id="pag-total">R$ 0,00</strong>
                        </div>
                        <div class="pag-resumo-linha">
                            <span>Recebido</span>
                            <strong id="pag-recebido-total">R$ 0,00</strong>
                        </div>
                        <div class="pag-resumo-linha falta" id="pag-falta-wrap">
                            <span>Falta</span>
                            <strong id="pag-falta">R$ 0,00</strong>
                        </div>
                        <div class="pag-resumo-linha troco" id="pag-troco-wrap" hidden>
                            <span>Troco</span>
                            <strong id="pag-troco">R$ 0,00</strong>
                        </div>
                    </div>

                    <p class="hint">Para pagamento misto, vá adicionando cada forma. <kbd>D</kbd>/<kbd>C</kbd>/<kbd>B</kbd>/<kbd>P</kbd>/<kbd>O</kbd> seleciona rapidamente.</p>

                    <div class="formas">
                        <button class="forma" data-forma="dinheiro">💵 Dinheiro</button>
                        <button class="forma" data-forma="debito">💳 Débito</button>
                        <button class="forma" data-forma="credito">💳 Crédito</button>
                        <button class="forma" data-forma="pix">📱 PIX</button>
                        <button class="forma" data-forma="outros">⋯ Outros</button>
                    </div>

                    <div class="pag-entrada" id="pag-entrada" hidden>
                        <label id="pag-entrada-label">Valor (R$)</label>
                        <input type="number" id="pag-valor" step="0.01" min="0.01" value="0" autocomplete="off">
                        <button type="button" class="btn-secundario" id="pag-adicionar">Adicionar</button>
                        <button type="button" class="btn-secundario" id="pag-cancelar">Cancelar</button>
                    </div>

                    <ul class="pag-lista" id="pag-lista" hidden></ul>

                    <button class="btn-primario grande" id="btn-finalizar-pedido" disabled>FINALIZAR PEDIDO</button>
                </div>
            </div>
        </div>

        <?php include __DIR__ . '/_partials/modal_ajuda.php'; ?>

        <div class="toast-wrap" id="toasts"></div>
        <div class="loading" id="loading" hidden><div class="spinner"></div><p id="loading-msg">Processando…</p></div>
    <?php endif; ?>

    <!-- ============== MODAIS GLOBAIS (splash + venda) ============== -->
    <!-- Elevação de admin sob demanda: qualquer ação que exija perfil admin
         passa por aqui quando o operador logado não é admin. A senha é uma
         única (gerenciada por App\Services\AdminSenha) — não há campo de
         usuário, qualquer pessoa com a senha admin pode autorizar. -->
    <div class="modal" id="modal-admin-auth" hidden>
        <div class="modal-card glass">
            <header><h2 id="adm-titulo">Autorização do Administrador</h2><button class="btn-fechar" data-fechar>×</button></header>
            <div class="modal-body">
                <p class="hint" id="adm-mensagem">Esta ação exige autorização de um administrador.</p>
                <form id="form-admin-auth" autocomplete="off">
                    <label>Senha do administrador
                        <input type="password" id="adm-senha" autocomplete="off"
                               data-lpignore="true" data-form-type="other" required autofocus>
                    </label>
                    <button type="submit" class="btn-primario grande">Autorizar</button>
                    <p class="msg-erro" id="adm-erro"></p>
                </form>
                <p class="hint adm-rodape">
                    <button type="button" class="link-acao" id="adm-trocar-senha">Alterar senha do administrador…</button>
                </p>
            </div>
        </div>
    </div>

    <!-- Trocar a senha de admin. Exige a senha atual + nova senha +
         confirmação. Não passa pela elevação (a senha atual já basta). -->
    <div class="modal" id="modal-admin-trocar-senha" hidden>
        <div class="modal-card glass">
            <header><h2>Alterar senha do administrador</h2><button class="btn-fechar" data-fechar>×</button></header>
            <div class="modal-body">
                <p class="hint">Informe a senha atual e a nova senha (mínimo 4 caracteres). Após a troca, qualquer elevação ativa é encerrada.</p>
                <form id="form-admin-trocar-senha" autocomplete="off">
                    <label>Senha atual
                        <input type="password" id="adm-ts-atual" autocomplete="off"
                               data-lpignore="true" data-form-type="other" required>
                    </label>
                    <label>Nova senha
                        <input type="password" id="adm-ts-nova" autocomplete="off"
                               data-lpignore="true" data-form-type="other" required minlength="4">
                    </label>
                    <label>Confirmar nova senha
                        <input type="password" id="adm-ts-conf" autocomplete="off"
                               data-lpignore="true" data-form-type="other" required minlength="4">
                    </label>
                    <button type="submit" class="btn-primario grande">Salvar nova senha</button>
                    <p class="msg-erro" id="adm-ts-erro"></p>
                </form>
            </div>
        </div>
    </div>

    <!-- Configurações da integração (Ctrl+,). Edita API_URL/TOKEN, dados do
         PDV e endpoints. Persistência vai pra tabela `configuracoes` com
         prefixo `env.` — overrides vencem o .env em runtime. -->
    <div class="modal" id="modal-config-integracao" hidden>
        <div class="modal-card glass operadores">
            <header><h2>Configurações da Integração</h2><button class="btn-fechar" data-fechar>×</button></header>
            <div class="modal-body">
                <p class="hint">Alterações sobrescrevem o <code>.env</code> em runtime e ficam salvas no banco do PDV.</p>
                <form id="form-config-integracao" autocomplete="off" class="op-form">
                    <h3>ERP</h3>
                    <label>API_URL <small>(base da API do ERP)</small>
                        <input type="url" id="cfg-API_URL" autocomplete="off" data-lpignore="true">
                    </label>
                    <label>API_TOKEN <small>(em branco mantém o atual)</small>
                        <input type="text" id="cfg-API_TOKEN" autocomplete="off" data-lpignore="true" placeholder="cole o novo token aqui se quiser regravar">
                    </label>
                    <h3>PDV</h3>
                    <label>PDV_ID
                        <input type="text" id="cfg-PDV_ID" autocomplete="off" data-lpignore="true">
                    </label>
                    <label>PDV_NOME <small>(usado como ?pdv= nos endpoints)</small>
                        <input type="text" id="cfg-PDV_NOME" autocomplete="off" data-lpignore="true">
                    </label>
                    <h3>Tempos</h3>
                    <label>HTTP_TIMEOUT <small>(segundos)</small>
                        <input type="number" id="cfg-HTTP_TIMEOUT" min="1" step="1" autocomplete="off">
                    </label>
                    <label>SLIDES_INTERVALO_MS <small>(slideshow do splash)</small>
                        <input type="number" id="cfg-SLIDES_INTERVALO_MS" min="1000" step="500" autocomplete="off">
                    </label>
                    <h3>Endpoints</h3>
                    <label>ENDPOINT_PRODUTOS<input type="text" id="cfg-ENDPOINT_PRODUTOS" autocomplete="off"></label>
                    <label>ENDPOINT_OPERADORES<input type="text" id="cfg-ENDPOINT_OPERADORES" autocomplete="off"></label>
                    <label>ENDPOINT_OPERADOR_LOGIN<input type="text" id="cfg-ENDPOINT_OPERADOR_LOGIN" autocomplete="off"></label>
                    <label>ENDPOINT_VENDAS<input type="text" id="cfg-ENDPOINT_VENDAS" autocomplete="off"></label>
                    <label>ENDPOINT_CUPOM<input type="text" id="cfg-ENDPOINT_CUPOM" autocomplete="off"></label>
                    <label>ENDPOINT_COMANDAS<input type="text" id="cfg-ENDPOINT_COMANDAS" autocomplete="off"></label>
                    <label>ENDPOINT_FECHAMENTOS<input type="text" id="cfg-ENDPOINT_FECHAMENTOS" autocomplete="off"></label>

                    <div class="row" style="margin-top:14px; gap:10px;">
                        <button type="button" class="btn-secundario" id="cfg-testar">Testar conexão</button>
                        <span class="flex-spacer" style="flex:1"></span>
                        <button type="submit" class="btn-primario">Salvar configurações</button>
                    </div>
                    <p class="msg" id="cfg-msg"></p>
                </form>
            </div>
        </div>
    </div>

    <div class="modal" id="modal-sistema" hidden>
        <div class="modal-card glass sistema">
            <header><h2>Sistema</h2><button class="btn-fechar" data-fechar>×</button></header>
            <div class="modal-body">
                <p class="hint" id="sistema-aviso-caixa" hidden>
                    <strong>Atenção:</strong> o caixa está aberto. Feche o caixa antes de reiniciar ou desligar — caso contrário a venda em andamento será perdida.
                </p>
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
        "/assets/js/api.js":      "/assets/js/api.js?v=<?= $jsVer ?>",
        "/assets/js/ui.js":       "/assets/js/ui.js?v=<?= $jsVer ?>",
        "/assets/js/carrinho.js": "/assets/js/carrinho.js?v=<?= $jsVer ?>",
        "/assets/js/sistema.js":  "/assets/js/sistema.js?v=<?= $jsVer ?>"
      }
    }
    </script>
    <script src="/assets/js/app.js?v=<?= $jsVer ?>" type="module"></script>
</body>
</html>

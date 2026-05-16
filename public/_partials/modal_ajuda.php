<?php
/**
 * Modal de Ajuda — atalhos de teclado.
 * Incluído tanto no modo splash quanto no modo app.
 */
?>
<div class="modal" id="modal-ajuda" hidden>
    <div class="modal-card glass ajuda">
        <header>
            <h2>Atalhos de Teclado</h2>
            <button class="btn-fechar" data-fechar aria-label="Fechar">×</button>
        </header>
        <div class="modal-body">
            <div class="ajuda-grid">
                <section>
                    <h3>Frente de Caixa</h3>
                    <dl>
                        <dt><kbd>F1</kbd></dt>           <dd>Consulta de produto</dd>
                        <dt><kbd>F2</kbd></dt>           <dd>Calculadora</dd>
                        <dt><kbd>F3</kbd></dt>           <dd>Carga de produtos do ERP</dd>
                        <dt><kbd>F4</kbd></dt>           <dd>Cancelar item selecionado</dd>
                        <dt><kbd>F5</kbd></dt>           <dd>Cancelar venda inteira</dd>
                        <dt><kbd>F6</kbd></dt>           <dd>Aplicar desconto</dd>
                        <dt><kbd>F7</kbd></dt>           <dd>Sangria (retirada de dinheiro)</dd>
                        <dt><kbd>F8</kbd></dt>           <dd>Reforço (entrada de dinheiro)</dd>
                        <dt><kbd>F9</kbd></dt>           <dd>Finalizar venda / pagamento</dd>
                        <dt><kbd>F10</kbd></dt>          <dd>Transmitir cupom fiscal</dd>
                        <dt><kbd>F11</kbd></dt>          <dd>Fechar caixa <small>(pode ser capturado pelo SO em alguns Linux — se não responder use F12 ou Ctrl+E)</small></dd>
                        <dt><kbd>F12</kbd></dt>          <dd>Encerrar (fecha caixa + logout)</dd>
                        <dt><kbd>Ctrl</kbd>+<kbd>E</kbd></dt> <dd>Encerrar caixa <small>(alternativa ao F11/F12)</small></dd>
                        <dt><kbd>Enter</kbd></dt>        <dd>Adicionar item bipado / digitado</dd>
                    </dl>
                </section>

                <section>
                    <h3>Globais</h3>
                    <dl>
                        <dt><kbd>Ctrl</kbd>+<kbd>H</kbd></dt>  <dd>Abrir esta ajuda</dd>
                        <dt><kbd>Ctrl</kbd>+<kbd>R</kbd></dt>  <dd>Reiniciar caixa (troca de operador, mantém o caixa aberto)</dd>
                        <dt><kbd>Ctrl</kbd>+<kbd>O</kbd></dt>          <dd>Sincronizar operadores com o ERP <small>(senha admin)</small></dd>
                        <dt><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>A</kbd></dt> <dd>Alterar a senha do administrador</dd>
                        <dt><kbd>Ctrl</kbd>+<kbd>,</kbd></dt>  <dd>Configurações da integração <small>(senha admin)</small></dd>
                        <dt><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>P</kbd></dt>  <dd>Reiniciar / Desligar o computador</dd>
                        <dt><kbd>Esc</kbd></dt>                <dd>Fechar modal aberto</dd>
                    </dl>

                    <h3>Modo Comanda</h3>
                    <dl>
                        <dt><kbd>F4</kbd></dt>                 <dd>Abrir terminal de comanda <small>(só disponível com o caixa fechado, sem login)</small></dd>
                    </dl>

                    <h3>Tela inicial (caixa fechado)</h3>
                    <dl>
                        <dt><kbd>Qualquer tecla</kbd></dt>     <dd>Abre o login do operador</dd>
                        <dt><kbd>Toque na tela</kbd></dt>      <dd>Idem — também abre o login</dd>
                        <dt><kbd>F4</kbd></dt>                 <dd>Atalho direto pro modo comanda</dd>
                    </dl>

                    <h3>Pagamento (modal F9)</h3>
                    <dl>
                        <dt><kbd>D</kbd></dt> <dd>Dinheiro</dd>
                        <dt><kbd>C</kbd></dt> <dd>Cartão de crédito</dd>
                        <dt><kbd>B</kbd></dt> <dd>Cartão de débito</dd>
                        <dt><kbd>P</kbd></dt> <dd>PIX</dd>
                    </dl>
                </section>
            </div>

            <p class="ajuda-rodape">
                Em caso de dúvida, consulte o supervisor ou pressione <kbd>F12</kbd> para sair com segurança.
            </p>
        </div>
    </div>
</div>

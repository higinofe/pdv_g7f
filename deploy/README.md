# Deploy — PDV

Ubuntu Server 22.04 + Nginx + PHP-FPM + SQLite + Chromium kiosk.

## Instalação automatizada

```bash
sudo bash /var/www/pdv/deploy/setup.sh
```

O script instala todas as dependências, configura Nginx, cria usuário
`pdv` com auto-login no tty1 que abre o Chromium em modo kiosk
apontando para `http://localhost`, e agenda o `sync/sync.php` no cron.

## Instalação manual (resumo)

1. **Pacotes**
   ```bash
   sudo apt install nginx php-fpm php-sqlite3 php-curl php-mbstring \
                    sqlite3 chromium-browser \
                    xserver-xorg xinit openbox unclutter
   ```

2. **Nginx** — copiar `deploy/nginx.conf` para
   `/etc/nginx/sites-available/pdv`, ajustar a versão do socket PHP-FPM,
   habilitar e recarregar.

3. **Permissões** — `www-data` precisa escrever em
   `database/`, `logs/` e `config/.env`.

4. **Cron de sync**
   ```cron
   */2 * * * * php /var/www/pdv/sync/sync.php >> /var/www/pdv/logs/sync.log 2>&1
   ```

5. **Kiosk** — auto-login no tty1 → `startx` → `openbox` →
   `chromium-browser --kiosk http://localhost`.

## Personalização por cliente

Antes de clonar a imagem para um novo mini PC, ajustar:

| Arquivo                       | O quê alterar                       |
|-------------------------------|-------------------------------------|
| `config/.env`                 | `API_URL`, `API_TOKEN`, `PDV_ID`, `PDV_NOME` |
| `public/bg.jpg`               | Imagem de fundo do cliente (≥ 1920×1080) |

A senha admin padrão (`admin/admin`) deve ser trocada no primeiro login
real (a tabela `operadores` aceita atualização via SQL ou nova tela).

## Clonezilla

Após validar o PDV em uma máquina-modelo:

1. Limpar logs: `sudo truncate -s 0 /var/www/pdv/logs/*.log`
2. Limpar banco de testes: `rm /var/www/pdv/database/pdv.db` (será
   recriado vazio com admin/admin no primeiro acesso).
3. Clonar a partição com Clonezilla.
4. Em cada máquina nova, ajustar `config/.env` e `bg.jpg` antes de
   liberar para uso.

## Troubleshooting rápido

- **Tela em branco no kiosk** → verificar `journalctl -u getty@tty1`
  e logs do `~pdv/.xsession-errors`.
- **API do ERP retorna erro** → testar via tela de Configurações
  (botão "Testar Conexão") ou checar `logs/app.log`.
- **Vendas pendentes não somem** → conferir `logs/sync.log` (cron) e
  rodar manualmente `php /var/www/pdv/sync/sync.php`.

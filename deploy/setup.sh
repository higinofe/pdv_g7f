#!/usr/bin/env bash
# ====================================================================
# setup.sh — instalação completa do PDV em Ubuntu Server 22.04
# Para mini PCs Intel N100 destinados a clonagem via Clonezilla.
#
# Uso:
#   sudo bash /var/www/pdv/deploy/setup.sh
#
# O que faz:
#   1. Instala Nginx, PHP-FPM, SQLite, Chromium e dependências de kiosk
#   2. Configura Nginx apontando para /var/www/pdv/public
#   3. Ajusta permissões de pastas de escrita (database, logs, config)
#   4. Cria serviço systemd que inicia Chromium em modo kiosk no boot
#   5. Agenda o sync.php no cron a cada 2 minutos
# ====================================================================
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Execute como root: sudo bash $0"
    exit 1
fi

PDV_DIR="/var/www/pdv"
KIOSK_USER="pdv"

echo "==> [1/6] Atualizando pacotes..."
apt-get update -y

echo "==> [2/6] Instalando Nginx, PHP, SQLite, Chromium..."
apt-get install -y \
    nginx \
    php-fpm php-sqlite3 php-curl php-mbstring php-xml \
    sqlite3 \
    chromium-browser \
    xserver-xorg xinit openbox unclutter

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"

echo "==> [3/6] Configurando Nginx (PHP ${PHP_VERSION})..."
sed "s|/run/php/php8.1-fpm.sock|${PHP_SOCK}|g" \
    "${PDV_DIR}/deploy/nginx.conf" > /etc/nginx/sites-available/pdv
ln -sf /etc/nginx/sites-available/pdv /etc/nginx/sites-enabled/pdv
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "==> [4/6] Permissões de escrita..."
mkdir -p "${PDV_DIR}/database" "${PDV_DIR}/logs"
chown -R www-data:www-data "${PDV_DIR}/database" "${PDV_DIR}/logs" "${PDV_DIR}/config"
chmod -R 0775 "${PDV_DIR}/database" "${PDV_DIR}/logs"
chmod 0664 "${PDV_DIR}/config/.env" || true

echo "==> [5/6] Criando usuário de kiosk e serviço systemd..."
id "${KIOSK_USER}" &>/dev/null || useradd -m -s /bin/bash "${KIOSK_USER}"
adduser "${KIOSK_USER}" tty || true
adduser "${KIOSK_USER}" video || true

# Openbox config + autostart
mkdir -p "/home/${KIOSK_USER}/.config/openbox"
cat > "/home/${KIOSK_USER}/.config/openbox/autostart" <<'EOF'
# Esconde o cursor após inatividade
unclutter -idle 0.5 -root &
# Desabilita screensaver/DPMS
xset s off
xset s noblank
xset -dpms
# Inicia Chromium em modo kiosk apontando para o PDV local
chromium-browser \
    --kiosk \
    --no-first-run \
    --noerrdialogs \
    --disable-translate \
    --disable-infobars \
    --disable-pinch \
    --overscroll-history-navigation=0 \
    --check-for-update-interval=31536000 \
    --autoplay-policy=no-user-gesture-required \
    http://localhost
EOF
chown -R "${KIOSK_USER}:${KIOSK_USER}" "/home/${KIOSK_USER}/.config"

# .xinitrc que sobe o openbox
cat > "/home/${KIOSK_USER}/.xinitrc" <<'EOF'
exec openbox-session
EOF
chown "${KIOSK_USER}:${KIOSK_USER}" "/home/${KIOSK_USER}/.xinitrc"

# Auto-login no tty1 (getty)
mkdir -p /etc/systemd/system/getty@tty1.service.d
cat > /etc/systemd/system/getty@tty1.service.d/autologin.conf <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin ${KIOSK_USER} --noclear %I \$TERM
EOF

# .bash_profile que dispara o startx no login
cat > "/home/${KIOSK_USER}/.bash_profile" <<'EOF'
if [[ -z "$DISPLAY" && "$(tty)" = "/dev/tty1" ]]; then
    startx -- -nocursor
fi
EOF
chown "${KIOSK_USER}:${KIOSK_USER}" "/home/${KIOSK_USER}/.bash_profile"

systemctl daemon-reload
systemctl enable getty@tty1.service

echo "==> [6/6] Cron de sincronização (a cada 2 min)..."
CRON_LINE="*/2 * * * * php ${PDV_DIR}/sync/sync.php >> ${PDV_DIR}/logs/sync.log 2>&1"
# `crontab -l` retorna 1 quando o usuário ainda não tem crontab — não pode
# derrubar o script (set -e). Capturamos o crontab atual em uma variável e
# regravamos com a nova linha de sync.
CURRENT_CRON="$(crontab -u www-data -l 2>/dev/null | grep -v 'sync/sync.php' || true)"
printf '%s\n%s\n' "$CURRENT_CRON" "$CRON_LINE" | crontab -u www-data -

echo ""
echo "================================================================"
echo " Instalação concluída."
echo " Acesse via navegador local:  http://localhost"
echo " IP da máquina (rede):        $(hostname -I | awk '{print $1}')"
echo ""
echo " Próximos passos:"
echo "   1. Editar ${PDV_DIR}/config/.env (URL da API, token, PDV_ID)"
echo "   2. Substituir ${PDV_DIR}/public/bg.jpg pela imagem do cliente"
echo "   3. Reiniciar:    sudo reboot"
echo "   Login padrão do PDV:  admin / admin"
echo "================================================================"

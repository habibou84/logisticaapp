#!/usr/bin/env bash
#
# Installation complète sur un VPS NU (sans panneau d'administration) :
# Ubuntu 22.04 / 24.04 ou Debian 12, à lancer en root.
# Serveur avec ISPConfig : utilisez deploy/ispconfig.sh à la place.
#
#   DOMAIN=test.mondomaine.ci EMAIL=moi@mondomaine.ci bash deploy/install.sh
#
# Variables :
#   DOMAIN   (obligatoire) nom de domaine qui pointe vers l'adresse IP du VPS
#   EMAIL    (obligatoire) adresse pour le certificat HTTPS (Let's Encrypt)
#   REPO     dépôt Git (défaut : git@github.com:habibou84/deliverynew.git)
#   BRANCH   branche à déployer (défaut : main)
#   APP_DIR  dossier de l'application (défaut : /var/www/livraison)
#   DEMO     1 = données de démonstration (mot de passe « password »), 0 = base vide
#
# Relançable sans risque : base de données, .env et clés sont conservés.

set -euo pipefail

DOMAIN="${DOMAIN:-}"
EMAIL="${EMAIL:-}"
REPO="${REPO:-git@github.com:habibou84/deliverynew.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/var/www/livraison}"
DEMO="${DEMO:-1}"
APP_USER=www-data
TOOLS_HOME=/var/www
REVERB_PORT=8080
NODE_MAJOR=22
PHP_V=8.3

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root (sudo -i)."
[[ -n "$DOMAIN" ]] || fail "indiquez le domaine : DOMAIN=test.mondomaine.ci EMAIL=… bash deploy/install.sh"
[[ -n "$EMAIL" ]] || fail "indiquez une adresse e-mail pour le certificat HTTPS : EMAIL=moi@mondomaine.ci"
[[ ! -d /usr/local/ispconfig ]] || fail "ISPConfig est installé sur ce serveur : utilisez deploy/ispconfig.sh."

. /etc/os-release
case "$ID:$VERSION_ID" in
    ubuntu:22.04|ubuntu:24.04|debian:12) ;;
    *) fail "système non prévu ($PRETTY_NAME). Utilisez Ubuntu 22.04/24.04 ou Debian 12." ;;
esac

export DEBIAN_FRONTEND=noninteractive

step "Paquets système (Nginx, PostgreSQL, Redis, Supervisor, Certbot)"
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg git unzip ufw openssl sudo \
    nginx postgresql redis-server supervisor certbot python3-certbot-nginx

step "PHP $PHP_V"
if [[ "$ID:$VERSION_ID" == "ubuntu:22.04" ]]; then
    apt-get install -y -q software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
elif [[ "$ID" == "debian" ]]; then
    curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
    echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $VERSION_CODENAME main" > /etc/apt/sources.list.d/sury-php.list
    apt-get update -q
fi
apt-get install -y -q php$PHP_V-fpm php$PHP_V-cli php$PHP_V-pgsql php$PHP_V-redis php$PHP_V-mbstring \
    php$PHP_V-xml php$PHP_V-curl php$PHP_V-zip php$PHP_V-bcmath php$PHP_V-intl php$PHP_V-gd
PHP_BIN=/usr/bin/php$PHP_V
PHP_FPM_SOCK=/run/php/php$PHP_V-fpm.sock

# Photos et imports jusqu'à 20 Mo
for ini in /etc/php/$PHP_V/fpm/php.ini /etc/php/$PHP_V/cli/php.ini; do
    sed -i -e 's/^upload_max_filesize.*/upload_max_filesize = 20M/' -e 's/^post_max_size.*/post_max_size = 25M/' "$ini"
done
systemctl restart php$PHP_V-fpm

step "Composer"
if ! command -v composer >/dev/null; then
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    [[ "$expected" == "$(sha384sum /tmp/composer-setup.php | cut -d' ' -f1)" ]] || fail "signature de l'installateur Composer invalide."
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

step "Node.js $NODE_MAJOR (compilation de l'interface)"
if ! command -v node >/dev/null || [[ "$(node -v | cut -d. -f1 | tr -d v)" -lt 20 ]]; then
    curl -fsSL https://deb.nodesource.com/setup_$NODE_MAJOR.x | bash -
    apt-get install -y -q nodejs
fi

systemctl enable --now redis-server
setup_postgres
fetch_code
write_env
build_app
chown -R "$APP_USER:" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

step "Nginx"
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHP_FPM_SOCK__|$PHP_FPM_SOCK|g" \
    -e "s|__REVERB_PORT__|$REVERB_PORT|g" "$APP_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/livraison
ln -sf /etc/nginx/sites-available/livraison /etc/nginx/sites-enabled/livraison
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

install_supervisor
install_cron
save_deploy_conf

step "Pare-feu"
if ufw allow OpenSSH >/dev/null && ufw allow 'Nginx Full' >/dev/null && ufw --force enable >/dev/null; then
    info "Ouverts : SSH, HTTP, HTTPS. Reverb, PostgreSQL et Redis restent internes."
else
    warn "Pare-feu non activé (ufw indisponible) : vérifiez le pare-feu dans l'espace client de l'hébergeur."
fi

step "HTTPS (Let's Encrypt)"
if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect; then
    info "Certificat installé, renouvellement automatique."
else
    warn "Certificat non obtenu : vérifiez que $DOMAIN pointe vers l'adresse IP du VPS (enregistrement A),"
    warn "puis lancez : certbot --nginx -d $DOMAIN -m $EMAIL --agree-tos --redirect"
fi

summary

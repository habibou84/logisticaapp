#!/usr/bin/env bash
#
# Installation sur un serveur géré par ISPConfig 3 (Debian 13 « Trixie », Apache), à lancer en root
# APRÈS avoir créé le site dans ISPConfig (voir docs/DEPLOIEMENT-LWS.md) :
#
#   DOMAIN=test.mondomaine.ci EMAIL=moi@mondomaine.ci bash ispconfig.sh
#
# ISPConfig garde la main sur Apache, PHP-FPM, le certificat HTTPS et le pare-feu :
# ce script n'y touche pas. Il ajoute PostgreSQL, Redis, Supervisor et Node.js, installe
# l'application dans le dossier web du site et affiche les directives Apache à coller dans
# ISPConfig.
#
# Variables :
#   DOMAIN   (obligatoire) domaine du site créé dans ISPConfig
#   EMAIL    (obligatoire) adresse de contact (notifications push)
#   REPO     dépôt Git (défaut : git@github.com:habibou84/deliverynew.git)
#   BRANCH   branche à déployer (défaut : main)
#   SITE_ROOT dossier du site affiché par ISPConfig (défaut : lien /var/www/<domaine>)
#   DEMO     1 = données de démonstration (mot de passe « password »), 0 = base vide
#
# Relançable sans risque : base de données, .env et clés sont conservés.

set -euo pipefail

DOMAIN="${DOMAIN:-}"
EMAIL="${EMAIL:-}"
REPO="${REPO:-git@github.com:habibou84/deliverynew.git}"
BRANCH="${BRANCH:-main}"
DEMO="${DEMO:-1}"
# 8080 et 8081 sont pris par le panneau ISPConfig : Reverb écoute en local sur 6001
REVERB_PORT=6001
# Redis peut déjà servir à Rspamd (antispam ISPConfig) : bases séparées
REDIS_DB=2
REDIS_CACHE_DB=3

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ ! -f "$SCRIPT_DIR/lib.sh" ]]; then
    # Script téléchargé seul : récupérer la bibliothèque à côté
    echo "Placez lib.sh, supervisor.conf et ispconfig.sh dans le même dossier." >&2
    exit 1
fi
. "$SCRIPT_DIR/lib.sh"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root (sudo -i)."
[[ -n "$DOMAIN" ]] || fail "indiquez le domaine : DOMAIN=test.mondomaine.ci EMAIL=… bash ispconfig.sh"
[[ -n "$EMAIL" ]] || fail "indiquez une adresse e-mail : EMAIL=moi@mondomaine.ci"
[[ -d /usr/local/ispconfig ]] || warn "ISPConfig non détecté : sur un VPS nu, préférez deploy/install.sh."

. /etc/os-release
[[ "$ID" == "debian" ]] || warn "Prévu pour Debian 13 ; système détecté : $PRETTY_NAME."

# ─────────────────────────── Site ISPConfig ───────────────────────────

step "Site ISPConfig de $DOMAIN"
# Dossier du site : celui affiché par ISPConfig (SITE_ROOT=/var/www/clients/clientX/webY), sinon le lien /var/www/<domaine>
SITE_ROOT="${SITE_ROOT:-$(readlink -f "/var/www/$DOMAIN" 2>/dev/null || true)}"
SITE_ROOT="${SITE_ROOT%/}"
[[ -n "$SITE_ROOT" && -d "$SITE_ROOT/web" ]] || fail "site introuvable ($SITE_ROOT). Indiquez le dossier affiché par ISPConfig : SITE_ROOT=/var/www/clients/clientX/webY"
APP_USER="$(stat -c %U "$SITE_ROOT/web")"
APP_GROUP="$(stat -c %G "$SITE_ROOT/web")"
TOOLS_HOME="$SITE_ROOT/private"
APP_DIR="$SITE_ROOT/web/livraison"
[[ -d "$TOOLS_HOME" ]] || fail "dossier $TOOLS_HOME absent."
info "Dossier du site : $SITE_ROOT (utilisateur $APP_USER, groupe $APP_GROUP)"
info "Application     : $APP_DIR"

if systemctl is-active --quiet apache2; then
    info "Serveur web : Apache"
elif systemctl is-active --quiet nginx; then
    fail "ISPConfig utilise Nginx sur ce serveur : ce script est prévu pour Apache. Envoyez-moi ce message, j'adapterai la configuration."
else
    fail "aucun serveur web actif (apache2) trouvé."
fi

# ─────────────────────────── Paquets ───────────────────────────

export DEBIAN_FRONTEND=noninteractive

step "Paquets (PostgreSQL, Redis, Supervisor, Node.js, Composer, extensions PHP)"
apt-get update -q
apt-get install -y -q git unzip openssl sudo postgresql redis-server supervisor nodejs npm composer \
    php-cli php-pgsql php-redis php-intl php-bcmath php-gd php-zip php-mbstring php-xml php-curl

PHP_BIN="$(command -v php)"
PHP_V="$($PHP_BIN -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
info "PHP $PHP_V ($PHP_BIN), Node.js $(node -v), Composer $(composer --version 2>/dev/null | awk '{print $3}')"
[[ "$(node -v | tr -d v | awk -F. '{print ($1*100)+$2}')" -ge 2019 ]] || fail "Node.js 20.19 ou plus requis (installé : $(node -v))."
systemctl restart "php$PHP_V-fpm" 2>/dev/null || true

step "Modules Apache (proxy WebSocket pour le temps réel)"
a2enmod -q proxy proxy_http proxy_wstunnel rewrite headers
systemctl reload apache2

systemctl enable --now redis-server

# ─────────────────────────── Application ───────────────────────────

setup_postgres
fetch_code
write_env
build_app
chown -R "$APP_USER:$APP_GROUP" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
install_supervisor
install_cron
save_deploy_conf

# ─────────────────────────── Directives ISPConfig ───────────────────────────

step "À faire dans ISPConfig : Sites > $DOMAIN"
cat <<EOF

    1. Onglet « Domaine » : PHP = « PHP-FPM », version PHP $PHP_V (par défaut) ;
       cochez « SSL » et « Let's Encrypt SSL », enregistrez.

    2. Onglet « Options », champ « Apache Directives », collez :

────────────────────────────────────────────────────────────────────────
DocumentRoot {DOCROOT}/livraison/public
<Directory {DOCROOT}/livraison/public>
    Options +FollowSymLinks -Indexes
    AllowOverride All
    Require all granted
    CGIPassAuth On
</Directory>
ProxyPass /app/ ws://127.0.0.1:$REVERB_PORT/app/
ProxyPassReverse /app/ ws://127.0.0.1:$REVERB_PORT/app/
────────────────────────────────────────────────────────────────────────

    3. Même onglet, « PHP open_basedir » : vérifiez qu'il contient {DOCROOT} (c'est le cas par défaut).

    Enregistrez, attendez une minute (ISPConfig régénère Apache), puis ouvrez https://$DOMAIN
EOF

summary

#!/usr/bin/env bash
#
# Préproduction de Jibiat (preprod.jibiat.com) sur le serveur de production (LWS, ISPConfig, Apache),
# à lancer en root. Guide complet : docs/PREPRODUCTION.md
#
#   DOMAIN=preprod.jibiat.com EMAIL=moi@exemple.ci CLOUDFLARE_API_TOKEN=… bash deploy/preprod.sh
#
# Chaque entreprise de test a son sous-domaine ({adresse}.preprod.jibiat.com) : il faut un certificat
# « wildcard » de second niveau, que seule une validation DNS permet d'obtenir (Let's Encrypt, jeton
# Cloudflare). ISPConfig ne sait pas le faire : la préproduction est installée À CÔTÉ d'ISPConfig (son
# hôte virtuel Apache, son PHP-FPM, son utilisateur système, sa base, ses files d'attente). Le site de
# production et les autres sites d'ISPConfig ne sont pas modifiés.
#
# Variables :
#   DOMAIN                (obligatoire) adresse de la préproduction, ex. preprod.jibiat.com
#   EMAIL                 (obligatoire) adresse de contact (certificat Let's Encrypt)
#   CLOUDFLARE_API_TOKEN  jeton Cloudflare « Modifier la zone DNS » de jibiat.com (premier lancement)
#   ACCESS_CODE           code d'accès de l'équipe (défaut : tiré au hasard, affiché à la fin)
#   DEMO                  1 (défaut) = entreprises de démonstration, 0 = base vide
#   REPO, BRANCH          dépôt et branche suivie (défaut : dépôt deliverynew, branche main)
#   INSTANCE              nom de l'installation sur le serveur (défaut : jibiat-preprod)
#
# Relançable sans risque : base de données, .env et certificat sont conservés.

set -euo pipefail

DOMAIN="$(echo "${DOMAIN:-}" | tr '[:upper:]' '[:lower:]' | sed -e 's|^https\?://||' -e 's|/.*$||')"
EMAIL="${EMAIL:-}"
CLOUDFLARE_API_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
ACCESS_CODE="${ACCESS_CODE:-}"
DEMO="${DEMO:-1}"
REPO="${REPO:-https://github.com/habibou84/deliverynew.git}"
BRANCH="${BRANCH:-main}"
export INSTANCE="${INSTANCE:-jibiat-preprod}"
# Ports et bases Redis distincts de la production (Reverb 6001, Redis 2 et 3 ; 8080/8081 : ISPConfig)
REVERB_PORT="${REVERB_PORT:-6002}"
REDIS_DB="${REDIS_DB:-4}"
REDIS_CACHE_DB="${REDIS_CACHE_DB:-5}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"

APP_USER="${INSTANCE//-/_}"
TOOLS_HOME="/var/lib/$APP_USER"
APP_DIR="/var/www/$INSTANCE"
CLOUDFLARE_INI="/etc/letsencrypt/cloudflare-$INSTANCE.ini"
VHOST="000-$INSTANCE"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root (sudo -i)."
[[ -n "$DOMAIN" ]] || fail "indiquez l'adresse : DOMAIN=preprod.jibiat.com EMAIL=… CLOUDFLARE_API_TOKEN=… bash $0"
[[ "$DOMAIN" =~ ^[a-z0-9-]+(\.[a-z0-9-]+)+$ ]] || fail "adresse invalide : $DOMAIN"
[[ -n "$EMAIL" ]] || fail "indiquez une adresse e-mail : EMAIL=moi@exemple.ci"
[[ "$INSTANCE" =~ ^[a-z][a-z0-9-]{2,30}$ ]] || fail "INSTANCE : lettres minuscules, chiffres et tirets."
[[ "$INSTANCE" != "livraison" ]] || fail "INSTANCE=livraison est le nom de la production : choisissez-en un autre."
if [[ -f "$DEPLOY_CONF" ]] && grep -q '^DOMAIN=.' "$DEPLOY_CONF" && ! grep -qx "DOMAIN=$DOMAIN" "$DEPLOY_CONF"; then
    fail "l'installation « $INSTANCE » sert déjà une autre adresse ($(grep '^DOMAIN=' "$DEPLOY_CONF" | cut -d= -f2)). Choisissez un autre INSTANCE."
fi
systemctl is-active --quiet apache2 || fail "Apache (apache2) n'est pas actif : ce script est prévu pour le serveur ISPConfig (Apache)."
[[ ! -d /usr/local/ispconfig ]] || info "ISPConfig détecté : il n'est pas modifié."

# ─────────────────────────── Paquets ───────────────────────────

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1

step "Paquets (Certbot, PHP-FPM, PostgreSQL, Redis, Supervisor, Node.js, Composer)"
apt-get update -q
apt-get install -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
    git unzip openssl sudo curl postgresql redis-server supervisor nodejs npm composer \
    certbot python3-certbot-dns-cloudflare \
    php-fpm php-cli php-pgsql php-redis php-intl php-bcmath php-gd php-zip php-mbstring php-xml php-curl

PHP_BIN="$(command -v php)"
PHP_V="$($PHP_BIN -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
info "PHP $PHP_V ($PHP_BIN), Node.js $(node -v)"
[[ "$(node -v | tr -d v | awk -F. '{print ($1*100)+$2}')" -ge 2019 ]] || fail "Node.js 20.19 ou plus requis (installé : $(node -v))."
systemctl enable --now redis-server

# ─────────────────────────── Certificat wildcard (DNS Cloudflare) ───────────────────────────

step "Certificat HTTPS pour $DOMAIN et *.$DOMAIN"
if [[ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
    info "Déjà obtenu (renouvelé automatiquement par certbot)."
else
    if [[ -n "$CLOUDFLARE_API_TOKEN" ]]; then
        install -m 600 /dev/null "$CLOUDFLARE_INI"
        echo "dns_cloudflare_api_token = $CLOUDFLARE_API_TOKEN" > "$CLOUDFLARE_INI"
    fi
    [[ -s "$CLOUDFLARE_INI" ]] || fail "premier lancement : indiquez le jeton Cloudflare, CLOUDFLARE_API_TOKEN=… (docs/PREPRODUCTION.md, étape 2)."
    certbot certonly --non-interactive --agree-tos -m "$EMAIL" \
        --dns-cloudflare --dns-cloudflare-credentials "$CLOUDFLARE_INI" --dns-cloudflare-propagation-seconds 30 \
        --cert-name "$DOMAIN" -d "$DOMAIN" -d "*.$DOMAIN"
fi
# Apache relu après chaque renouvellement
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload apache2\n' > "/etc/letsencrypt/renewal-hooks/deploy/$INSTANCE-apache.sh"
chmod 755 "/etc/letsencrypt/renewal-hooks/deploy/$INSTANCE-apache.sh"

# ─────────────────────────── Application ───────────────────────────

step "Utilisateur système « $APP_USER »"
if ! id "$APP_USER" >/dev/null 2>&1; then
    useradd --system --create-home --home-dir "$TOOLS_HOME" --shell /usr/sbin/nologin "$APP_USER"
    info "Créé ($TOOLS_HOME)."
else
    info "Déjà présent."
fi
# Apache (www-data) lit les fichiers publics (interface compilée, images)
usermod -a -G "$APP_USER" www-data
mkdir -p "$(dirname "$APP_DIR")"

setup_postgres
fetch_code

new_env=0
[[ -f "$APP_DIR/.env" ]] || new_env=1
write_env
if [[ "$new_env" == 1 ]]; then
    step "Réglages de la préproduction"
    env_set APP_ENV staging
    env_set APP_URL "https://admin.$DOMAIN"
    env_set PLATFORM_DOMAIN "$DOMAIN"
    env_set PLATFORM_SCHEME https
    # Temps réel : chaque adresse relaie /app/ vers Reverb
    env_set VITE_REVERB_HOST ""
    # Aucun vrai message : WhatsApp, SMS et e-mails simulés (journal des messages dans l'application)
    env_set WHATSAPP_DRIVER log
    env_set SMS_DRIVER log
    env_set MAIL_MAILER log
    # Cookies distincts de ceux de la production
    env_set SESSION_COOKIE "$INSTANCE-session"
    env_set PREPROD_ACCESS_CODE "${ACCESS_CODE:-$(random 4)}"
    info "APP_ENV=staging, adresse https://$DOMAIN, envois simulés, code d'accès demandé."
elif [[ -n "$ACCESS_CODE" ]]; then
    env_set PREPROD_ACCESS_CODE "$ACCESS_CODE"
fi

build_app
chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R g+rX "$APP_DIR/public"
chmod g+x "$APP_DIR"

# ─────────────────────────── PHP-FPM et Apache ───────────────────────────

step "PHP-FPM (groupe dédié « $INSTANCE »)"
sed -e "s|__USER__|$APP_USER|g" -e "s|__PHP_V__|$PHP_V|g" -e "s|__INSTANCE__|$INSTANCE|g" \
    "$APP_DIR/deploy/php-fpm.conf" > "/etc/php/$PHP_V/fpm/pool.d/$INSTANCE.conf"
systemctl enable "php$PHP_V-fpm" >/dev/null 2>&1 || true
# Rechargement : les sites d'ISPConfig ne sont pas interrompus
systemctl reload "php$PHP_V-fpm" || systemctl restart "php$PHP_V-fpm"

step "Apache (hôte virtuel $DOMAIN et *.$DOMAIN, lu avant les sites d'ISPConfig)"
a2enmod -q ssl rewrite headers proxy proxy_fcgi proxy_http proxy_wstunnel setenvif >/dev/null
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__USER__|$APP_USER|g" -e "s|__PHP_V__|$PHP_V|g" \
    -e "s|__INSTANCE__|$INSTANCE|g" -e "s|__REVERB_PORT__|$REVERB_PORT|g" \
    "$APP_DIR/deploy/apache-preprod.conf" > "/etc/apache2/sites-available/$VHOST.conf"
a2ensite -q "$VHOST" >/dev/null
if ! apache2ctl configtest; then
    a2dissite -q "$VHOST" >/dev/null || true
    fail "configuration Apache refusée (ci-dessus) : la préproduction est désactivée, rien d'autre n'a changé."
fi
systemctl reload apache2

install_supervisor
install_cron
save_deploy_conf

# ─────────────────────────── Vérification ───────────────────────────

step "Vérification"
sleep 2
hosts=("$DOMAIN" "admin.$DOMAIN")
[[ "$DEMO" != "1" ]] || hosts+=("livraison-express-ci.$DOMAIN")
for host in "${hosts[@]}"; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --resolve "$host:443:127.0.0.1" "https://$host/up" || true)"
    info "https://$host/up → $code (attendu : 200)"
done
# La production répond toujours
prod="${DOMAIN#*.}"
code="$(curl -s -o /dev/null -w '%{http_code}' --resolve "admin.$prod:443:127.0.0.1" -k "https://admin.$prod/up" || true)"
info "https://admin.$prod/up (production) → $code"

step "Terminé"
cat <<EOF
    Présentation      : https://$DOMAIN
    Console           : https://admin.$DOMAIN
    Entreprise démo   : https://livraison-express-ci.$DOMAIN   (équipe : /admin, livreurs : /livreur)
    Code d'accès      : $(env_get PREPROD_ACCESS_CODE)   (à donner à l'équipe ; à changer : PREPROD_ACCESS_CODE dans $APP_DIR/.env)
    Dossier           : $APP_DIR (journaux : storage/logs)
    Mise à jour       : bash $APP_DIR/deploy/update.sh

    WhatsApp, SMS et e-mails sont simulés : aucun vrai message ne part de la préproduction.
EOF
[[ "$DEMO" != "1" ]] || info "Comptes de démonstration : mot de passe « password » (super administrateur : 07 00 00 00 00)."

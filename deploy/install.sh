#!/usr/bin/env bash
#
# Installation sur un serveur Ubuntu/Debian qui a déjà Apache (et d'autres sites) et Docker.
# À lancer en root, depuis le dossier de l'application (git clone déjà fait) :
#
#   DOMAIN=app.logisticadelivery.com EMAIL=vous@exemple.com bash deploy/install.sh
#
# Étapes (chacune est sautée si elle est déjà faite ; le script peut être relancé sans risque) :
#   1. .env de production (mots de passe et clés générés une seule fois) ;
#   2. construction des images et démarrage des conteneurs (site, PostgreSQL, Redis, files d'attente,
#      temps réel, tâches planifiées) ; rien n'est ouvert sur Internet, seulement 127.0.0.1:APP_PORT ;
#   3. entreprise et premier administrateur (questions posées à l'écran) ;
#   4. certificat HTTPS (Let's Encrypt) et site Apache pour DOMAIN ;
#   5. sauvegarde de la base chaque nuit (deploy/backup.sh).
#
# Ne touche ni aux autres sites Apache, ni à PHP, ni à MySQL du serveur.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOMAIN="${DOMAIN:-app.logisticadelivery.com}"
EMAIL="${EMAIL:-}"
APP_PORT="${APP_PORT:-8090}"
PROJECT="${COMPOSE_PROJECT_NAME:-logistica}"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
fail() { printf '\033[1;31mErreur : %s\033[0m\n' "$*" >&2; exit 1; }
random() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"; }

env_get() { grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
env_set() {
    local value="$2"
    [[ "$value" =~ [[:space:]\#\"\$] ]] && value="\"${value//\"/\\\"}\""
    if grep -qE "^$1=" "$APP_DIR/.env"; then
        local escaped
        escaped="$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')"
        sed -i -E "s|^$1=.*|$1=$escaped|" "$APP_DIR/.env"
    else
        printf '%s=%s\n' "$1" "$value" >> "$APP_DIR/.env"
    fi
}

compose() { docker compose --project-directory "$APP_DIR" "$@"; }

[[ $EUID -eq 0 ]] || fail "lancez ce script en root."
command -v docker >/dev/null || fail "Docker n'est pas installé."
docker compose version >/dev/null 2>&1 || fail "Docker Compose (docker compose) n'est pas installé."
command -v apache2ctl >/dev/null || fail "Apache n'est pas installé."
cd "$APP_DIR"

# ───────────────────────────── 1. Configuration (.env) ─────────────────────────────
step "Configuration (.env)"
if [[ -f .env ]]; then
    info ".env déjà présent : conservé."
else
    cp .env.example .env
    env_set COMPOSE_PROJECT_NAME "$PROJECT"
    env_set APP_PORT "$APP_PORT"
    env_set APP_ENV production
    env_set APP_DEBUG false
    env_set APP_URL "https://$DOMAIN"
    env_set APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
    env_set LOG_STACK daily
    env_set LOG_LEVEL info
    # Services des conteneurs (noms de docker-compose.yml)
    env_set DB_CONNECTION pgsql
    env_set DB_HOST postgres
    env_set DB_PORT 5432
    env_set DB_DATABASE logistica
    env_set DB_USERNAME logistica
    env_set DB_PASSWORD "$(random 32)"
    env_set REDIS_HOST redis
    # Temps réel : Reverb dans son conteneur ; le navigateur passe par https://DOMAIN/app/
    env_set REVERB_APP_ID "$((RANDOM * RANDOM))"
    env_set REVERB_APP_KEY "$(random 20)"
    env_set REVERB_APP_SECRET "$(random 32)"
    env_set REVERB_HOST reverb
    env_set REVERB_PORT 8080
    env_set REVERB_SCHEME http
    env_set VITE_REVERB_HOST "$DOMAIN"
    env_set VITE_REVERB_PORT 443
    env_set VITE_REVERB_SCHEME https
    # Apache, puis Nginx du conteneur, devant l'application : adresse réelle des visiteurs et HTTPS
    env_set TRUSTED_PROXIES '*'
    [[ -n "$EMAIL" ]] && env_set VAPID_SUBJECT "mailto:$EMAIL"
    env_set WHATSAPP_VERIFY_TOKEN "$(random 16)"
    info ".env créé pour https://$DOMAIN."
fi
# Lisible par root et par le groupe www-data des conteneurs (gid 33) : l'application y lit sa configuration
chown root:33 .env
chmod 640 .env
APP_PORT="$(env_get APP_PORT)"
APP_PORT="${APP_PORT:-8090}"

# ───────────────────────────── 2. Conteneurs ─────────────────────────────
step "Construction des images (quelques minutes la première fois)"
compose build

# Clés des notifications push : une seule fois (les changer désabonne les appareils)
if [[ -z "$(env_get VAPID_PUBLIC_KEY)" ]]; then
    step "Clés des notifications push"
    vapid="$(compose run --rm --no-deps -T --entrypoint php app artisan webpush:vapid)"
    env_set VAPID_PUBLIC_KEY "$(echo "$vapid" | grep '^VAPID_PUBLIC_KEY=' | cut -d= -f2- | tr -d '\r')"
    env_set VAPID_PRIVATE_KEY "$(echo "$vapid" | grep '^VAPID_PRIVATE_KEY=' | cut -d= -f2- | tr -d '\r')"
fi

step "Démarrage des conteneurs"
compose up -d --remove-orphans --wait
compose ps

# ───────────────────────────── 3. Entreprise ─────────────────────────────
step "Entreprise et premier administrateur"
compose exec -u www-data app php artisan app:install --skip-if-installed

# ───────────────────────────── 4. HTTPS et Apache ─────────────────────────────
step "Modules Apache (relais vers les conteneurs, temps réel)"
a2enmod -q proxy proxy_http proxy_wstunnel headers rewrite ssl

step "Certificat HTTPS pour $DOMAIN"
if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
    info "Certificat déjà présent."
else
    command -v certbot >/dev/null || fail "certbot n'est pas installé (apt-get install certbot)."
    [[ -n "$EMAIL" ]] || fail "indiquez EMAIL=… (adresse de contact Let's Encrypt)."
    if ss -ltnH 'sport = :80' | grep -q .; then
        # Port 80 servi par Apache : validation par Apache (comme les autres sites)
        certbot certonly --apache -d "$DOMAIN" -m "$EMAIL" --agree-tos -n
    else
        # Port 80 libre : Certbot l'ouvre lui-même quelques secondes (et à chaque renouvellement)
        certbot certonly --standalone -d "$DOMAIN" -m "$EMAIL" --agree-tos -n
    fi
fi

step "Site Apache $DOMAIN"
# Même adresse d'écoute que les sites HTTPS déjà présents (« *:443 » ou l'adresse IP du serveur)
ADDR="$(grep -rhoE '<VirtualHost [^>]*:443>' /etc/apache2/sites-enabled/ 2>/dev/null | grep -v "$DOMAIN" | head -n1 | sed -E 's/<VirtualHost ([^>]*)>/\1/' || true)"
ADDR="${ADDR:-*:443}"
sed -e "s|__ADDR__|$ADDR|g" -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__PORT__|$APP_PORT|g" \
    deploy/apache-site.conf > "/etc/apache2/sites-available/$DOMAIN.conf"
a2ensite -q "$DOMAIN"
apache2ctl configtest
systemctl reload apache2
info "Écoute : $ADDR"

# ───────────────────────────── 5. Sauvegardes ─────────────────────────────
step "Sauvegarde de la base chaque nuit (/var/backups/$PROJECT, 14 jours)"
cat > "/etc/cron.d/$PROJECT-backup" <<CRON
# Sauvegarde de la base de l'application (deploy/install.sh)
30 2 * * * root bash $APP_DIR/deploy/backup.sh >> /var/log/$PROJECT-backup.log 2>&1
CRON

step "Terminé"
cat <<TXT
    Back-office : https://$DOMAIN/admin
    Livreurs    : https://$DOMAIN/livreur
    E-commerçants : https://$DOMAIN
    Mise à jour : bash $APP_DIR/deploy/update.sh
    Commandes   : cd $APP_DIR && docker compose exec -u www-data app php artisan …
TXT

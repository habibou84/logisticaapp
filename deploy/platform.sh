#!/usr/bin/env bash
#
# Passage en plateforme multi-entreprises (étape 4), à lancer en root sur un serveur déjà
# installé avec ispconfig.sh ou install.sh. Guide complet : docs/PLATEFORME.md
#
#   PLATFORM_DOMAIN=jibiat.com bash deploy/platform.sh
#
# Chaque entreprise est ensuite servie sur {adresse}.jibiat.com, la console du super
# administrateur sur admin.jibiat.com et la présentation sur jibiat.com.
#
# Variables :
#   PLATFORM_DOMAIN (obligatoire) domaine de la plateforme, sans « www »
#   CLOUDFLARE      1 = DNS en mode proxy Cloudflare (nuage orange, défaut), 0 = DNS seul
#   Serveur Nginx (install.sh) uniquement, certificat couvrant jibiat.com et *.jibiat.com :
#     ORIGIN_CERT et ORIGIN_KEY  fichiers du certificat d'origine Cloudflare (recommandé), ou
#     CF_API_TOKEN et EMAIL      Let's Encrypt par validation DNS (jeton Cloudflare « Zone.DNS:Edit »)
#   Avec ISPConfig, le certificat se colle dans ISPConfig (le script affiche la marche à suivre).
#
# Relançable sans risque.

set -euo pipefail

PLATFORM_DOMAIN="$(echo "${PLATFORM_DOMAIN:-}" | tr '[:upper:]' '[:lower:]' | sed -e 's|^https\?://||' -e 's|^www\.||' -e 's|/.*$||')"
CLOUDFLARE="${CLOUDFLARE:-1}"
ORIGIN_CERT="${ORIGIN_CERT:-}"
ORIGIN_KEY="${ORIGIN_KEY:-}"
CF_API_TOKEN="${CF_API_TOKEN:-}"
EMAIL="${EMAIL:-}"

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root (sudo -i)."
[[ -n "$PLATFORM_DOMAIN" ]] || fail "indiquez le domaine : PLATFORM_DOMAIN=jibiat.com bash deploy/platform.sh"
[[ "$PLATFORM_DOMAIN" =~ ^[a-z0-9-]+(\.[a-z0-9-]+)+$ ]] || fail "domaine invalide : $PLATFORM_DOMAIN"
[[ -f "$DEPLOY_CONF" ]] || fail "$DEPLOY_CONF absent : installez d'abord l'application (ispconfig.sh ou install.sh)."
. "$DEPLOY_CONF"

D="$PLATFORM_DOMAIN"
ADMIN="$(env_get PLATFORM_ADMIN_SUBDOMAIN)"
ADMIN="${ADMIN:-admin}"

# ─────────────────────────── DNS ───────────────────────────

step "DNS de $D"
dns_ok=1
for host in "$D" "$ADMIN.$D" "essai-$(random 3).$D"; do
    if ip="$(getent ahosts "$host" | awk 'NR==1 {print $1}')" && [[ -n "$ip" ]]; then
        info "$host → $ip"
    else
        dns_ok=0
        warn "$host ne répond pas encore."
    fi
done
[[ "$dns_ok" == 1 ]] || warn "Ajoutez dans Cloudflare les enregistrements A « $D » et « * » vers l'adresse IP du serveur (docs/PLATEFORME.md, étape 1). La suite est préparée quand même."

# ─────────────────────────── Serveur web ───────────────────────────

configure_nginx() {
    local conf=/etc/nginx/sites-available/livraison cert key sock port
    [[ -f "$conf" ]] || fail "$conf absent : site Nginx non installé par install.sh."

    if [[ -n "$ORIGIN_CERT" || -n "$ORIGIN_KEY" ]]; then
        [[ -f "$ORIGIN_CERT" && -f "$ORIGIN_KEY" ]] || fail "ORIGIN_CERT et ORIGIN_KEY doivent désigner les deux fichiers du certificat d'origine."
        step "Certificat d'origine Cloudflare"
        mkdir -p "/etc/ssl/$D"
        install -m 644 "$ORIGIN_CERT" "/etc/ssl/$D/cert.pem"
        install -m 600 "$ORIGIN_KEY" "/etc/ssl/$D/key.pem"
        cert="/etc/ssl/$D/cert.pem"
        key="/etc/ssl/$D/key.pem"
    elif [[ -n "$CF_API_TOKEN" ]]; then
        [[ -n "$EMAIL" ]] || fail "indiquez EMAIL (avis d'expiration Let's Encrypt)."
        step "Certificat Let's Encrypt pour $D et *.$D (validation DNS Cloudflare)"
        apt-get install -y -q certbot python3-certbot-dns-cloudflare
        install -d -m 700 /root/.secrets
        printf 'dns_cloudflare_api_token = %s\n' "$CF_API_TOKEN" > "/root/.secrets/cloudflare-$D.ini"
        chmod 600 "/root/.secrets/cloudflare-$D.ini"
        certbot certonly --non-interactive --agree-tos -m "$EMAIL" --keep-until-expiring \
            --dns-cloudflare --dns-cloudflare-credentials "/root/.secrets/cloudflare-$D.ini" \
            --dns-cloudflare-propagation-seconds 30 \
            --cert-name "$D" -d "$D" -d "*.$D" \
            --deploy-hook "systemctl reload nginx"
        cert="/etc/letsencrypt/live/$D/fullchain.pem"
        key="/etc/letsencrypt/live/$D/privkey.pem"
    elif [[ -f "/etc/ssl/$D/cert.pem" ]]; then
        cert="/etc/ssl/$D/cert.pem"
        key="/etc/ssl/$D/key.pem"
    elif [[ -f "/etc/letsencrypt/live/$D/fullchain.pem" ]]; then
        cert="/etc/letsencrypt/live/$D/fullchain.pem"
        key="/etc/letsencrypt/live/$D/privkey.pem"
    else
        fail "certificat requis pour $D et *.$D : ORIGIN_CERT=… ORIGIN_KEY=… (Cloudflare) ou CF_API_TOKEN=… EMAIL=… (Let's Encrypt)."
    fi

    step "Nginx : $D et *.$D"
    sock="$({ grep -oP 'fastcgi_pass unix:\K[^;]+' "$conf" || true; } | head -n1)"
    port="$(env_get REVERB_SERVER_PORT)"
    [[ -n "$sock" ]] || fail "socket PHP-FPM introuvable dans $conf."
    cp "$conf" "$conf.avant-plateforme"
    {
        sed -e "s|server_name __DOMAIN__;|server_name $D *.$D;|" \
            -e "s|listen 80;|listen 443 ssl;\n    ssl_certificate $cert;\n    ssl_certificate_key $key;|" \
            -e "s|listen \[::\]:80;|listen [::]:443 ssl;|" \
            -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHP_FPM_SOCK__|$sock|g" -e "s|__REVERB_PORT__|${port:-8080}|g" \
            "$APP_DIR/deploy/nginx.conf"
        cat <<EOF

server {
    listen 80;
    listen [::]:80;
    server_name $D *.$D;
    return 301 https://\$host\$request_uri;
}
EOF
    } > "$conf"
    if ! nginx -t; then
        cp "$conf.avant-plateforme" "$conf"
        fail "configuration Nginx refusée : l'ancienne est rétablie."
    fi
    systemctl reload nginx
}

ispconfig_steps() {
    step "À faire dans ISPConfig (Sites > Website)"
    cat <<EOF

    Deux sites : « $D » pour votre site de présentation (www.$D compris), et le site de
    l'application, sous son nom actuel (ne le renommez pas), qui reçoit toutes les autres
    adresses (*.$D : $ADMIN.$D et les entreprises).

    A. Site de l'application
       1. Onglet « Domain » : SSL coché, Let's Encrypt SSL DÉCOCHÉ (il ne couvre pas *.$D).
       2. Onglet « SSL » : collez le certificat d'origine Cloudflare (docs/PLATEFORME.md, étape 2)
            SSL Key : la clé privée · SSL Certificate : le certificat · SSL Action : « Save Certificate »
       3. Onglet « Options », champ « Apache Directives » : ajoutez en première ligne
            ServerAlias *.$D

    B. Site « $D » (présentation)
       Onglet « Domain » : Auto-Subdomain « www. », SSL coché ; certificat : Let's Encrypt
       ou le même certificat d'origine que l'application (onglet « SSL »).

    Enregistrez puis attendez une minute qu'ISPConfig régénère Apache.
EOF
}

if [[ -d /usr/local/ispconfig ]] && systemctl is-active --quiet apache2; then
    ispconfig_steps
elif systemctl is-active --quiet nginx; then
    configure_nginx
else
    warn "Serveur web non reconnu : il doit répondre pour $D et *.$D, en HTTPS, et relayer /app/ vers Reverb."
fi

# ─────────────────────────── Application ───────────────────────────

step "Configuration (.env)"
env_set PLATFORM_DOMAIN "$D"
env_set PLATFORM_SCHEME https
# Adresse de secours des liens : la console (jibiat.com peut être un site de présentation à part)
env_set APP_URL "https://$ADMIN.$D"
# Temps réel : chaque adresse relaie /app/ vers Reverb, l'interface s'y connecte directement
env_set VITE_REVERB_HOST ""
env_set VITE_REVERB_PORT 443
env_set VITE_REVERB_SCHEME https
if [[ "$CLOUDFLARE" == "1" ]]; then
    # Adresse IP réelle des visiteurs (limites de tentatives, journaux)
    env_set TRUSTED_PROXIES cloudflare
fi
info "PLATFORM_DOMAIN=$D, APP_URL=https://$ADMIN.$D$([[ "$CLOUDFLARE" == "1" ]] && echo ', TRUSTED_PROXIES=cloudflare')"

step "Interface et caches"
as_app "npm run build"
as_app "php artisan optimize"

step "Redémarrage des processus"
for unit in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' | awk '{print $1}'); do
    systemctl reload "$unit"
done
as_app "php artisan horizon:terminate"
supervisorctl restart "${INSTANCE:-livraison}-reverb"

step "Adresses"
as_app "php artisan platform:status" || true

cat <<EOF

    Console      : https://$ADMIN.$D   (super administrateur)
    Entreprises  : https://<adresse>.$D

    Changer l'adresse d'une entreprise :
      cd $APP_DIR && sudo -u $APP_USER php artisan platform:status --rename=ancienne:nouvelle
    (ou depuis la console, fiche de l'entreprise)

EOF

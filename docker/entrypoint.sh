#!/bin/sh
# Démarrage d'un conteneur de l'application (PostgreSQL est déjà prêt : voir docker-compose.yml).
#
# Rôles (variable CONTAINER_ROLE) :
#  - « migrate » : migrations et rôles/droits, puis s'arrête. Lancé par deploy/install.sh et deploy/update.sh
#    AVANT la bascule, pendant que l'ancienne version sert encore les visites (migrations compatibles avec
#    la version précédente : voir CLAUDE.md) ;
#  - « app » : PHP-FPM (site). Démarre le plus vite possible pour que la bascule ne coupe presque rien :
#    routes, événements et vues sont déjà en cache dans l'image (Dockerfile), il ne reste que la
#    configuration (lue dans .env) ; puis signale qu'il est prêt (fichier /tmp/ready) ;
#  - « worker » : Horizon, Reverb, tâches planifiées.
set -e
cd /var/www/html

if [ ! -f .env ]; then
    echo "Fichier .env absent : lancez deploy/install.sh." >&2
    exit 1
fi

role="${CONTAINER_ROLE:-app}"
as_www() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

[ "$role" = "app" ] && rm -f /tmp/ready

# Volume de stockage : dossiers attendus et propriétaire. Les fichiers envoyés (storage/app : photos de
# preuve, imports) peuvent être très nombreux : on ne les reparcourt que si le dossier n'appartient pas
# encore à www-data (premier démarrage, ancien volume). Journaux et fichiers internes, peu nombreux, sont
# remis à www-data à chaque démarrage (une commande lancée en root a pu en créer).
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
if [ "$(stat -c %U storage/app)" != "www-data" ]; then
    chown -R www-data:www-data storage
else
    chown www-data:www-data storage storage/app storage/app/public storage/framework
    chown -R www-data:www-data storage/logs storage/framework/cache storage/framework/sessions storage/framework/views
fi
chown -R www-data:www-data bootstrap/cache

# Migrations faites pour cette image ? Repère dans le volume partagé, propre à chaque construction
build_id="$(cat bootstrap/build-id 2>/dev/null || echo inconnu)"
migrated="storage/framework/migrated-$build_id"
migrate() {
    as_www php artisan migrate --force
    # Rôles, droits et motifs d'incident : complétés à chaque version (sans rien effacer)
    as_www php artisan db:seed --class=RolesAndPermissionsSeeder --force
    as_www php artisan db:seed --class=IncidentReasonsSeeder --force
    rm -f storage/framework/migrated-*
    as_www touch "$migrated"
}

if [ "$role" = "migrate" ]; then
    migrate
    exit 0
fi

# Filet de sécurité : image démarrée sans l'étape des migrations (ancien deploy/update.sh, « docker compose
# up » lancé à la main) : on les fait ici, comme avant, au prix d'un démarrage plus long
if [ "$role" = "app" ] && [ ! -f "$migrated" ]; then
    echo "Migrations pas encore faites pour cette version : lancées maintenant."
    migrate
fi

# Configuration lue dans .env (le reste est déjà en cache dans l'image)
as_www php artisan config:cache

if [ "$role" = "app" ]; then
    touch /tmp/ready
    # PHP-FPM : le processus principal reste root, ses processus de travail tournent en www-data
    exec "$@"
fi

exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"

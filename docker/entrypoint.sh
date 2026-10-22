#!/bin/sh
# Démarrage d'un conteneur de l'application (PostgreSQL est déjà prêt : voir docker-compose.yml).
# Le conteneur « app » applique les migrations puis signale qu'il est prêt (fichier /tmp/ready) ;
# Horizon, Reverb et les tâches planifiées attendent ce signal pour démarrer.
set -e
cd /var/www/html

if [ ! -f .env ]; then
    echo "Fichier .env absent : lancez deploy/install.sh." >&2
    exit 1
fi

as_www() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

# Volume de stockage : dossiers attendus et propriétaire (premier démarrage, ancienne image)
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    rm -f /tmp/ready
    as_www php artisan migrate --force
    # Rôles, droits et motifs d'incident : complétés à chaque version (sans rien effacer)
    as_www php artisan db:seed --class=RolesAndPermissionsSeeder --force
    as_www php artisan db:seed --class=IncidentReasonsSeeder --force
fi

as_www php artisan optimize

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    touch /tmp/ready
    # PHP-FPM : le processus principal reste root, ses processus de travail tournent en www-data
    exec "$@"
fi

exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"

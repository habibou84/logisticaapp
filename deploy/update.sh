#!/usr/bin/env bash
#
# Mise à jour : dernière version de la branche suivie (main), nouvelles images, sauvegarde de la base,
# redémarrage des conteneurs, contrôle du site. Les migrations s'appliquent au démarrage du conteneur « app ».
# À lancer en root :
#
#   bash /chemin/de/l/application/deploy/update.sh
#
# TARGET=<commit> : version précise (deploy/auto-update.sh) ; VERSION=<nom> : nom de la version (ex. v1.4.0),
# sinon le commit abrégé. Les images sont nommées d'après la version ; les 3 précédentes sont gardées pour revenir
# en arrière en quelques secondes (deploy/rollback.sh).
#
# Le site reste en ligne pendant la construction ; la bascule ne coupe que quelques secondes.

set -euo pipefail

main() {
    local app_dir branch previous version port project
    app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    cd "$app_dir"

    [[ $EUID -eq 0 ]] || { echo "Lancez ce script en root." >&2; exit 1; }
    [[ -f .env ]] || { echo "Pas encore installé : lancez deploy/install.sh." >&2; exit 1; }

    branch="${BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"
    echo "==> Code ($branch)"
    git fetch origin "+refs/heads/$branch:refs/remotes/origin/$branch"
    if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
        git status --short --untracked-files=no
        echo "Des fichiers du code ont été modifiés sur le serveur (ci-dessus) : annulez-les (git checkout -- .) puis relancez." >&2
        exit 1
    fi
    previous="$(env_get APP_VERSION)"
    previous="${previous:-$(git rev-parse --short HEAD)}"
    git checkout -q "$branch"
    # TARGET : version précise validée par GitHub (deploy/auto-update.sh), sinon la dernière de la branche
    git reset -q --hard "${TARGET:-origin/$branch}"

    version="${VERSION:-$(git rev-parse --short HEAD)}"
    [[ "$version" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] || { echo "Nom de version invalide : $version" >&2; exit 1; }
    project="$(env_get COMPOSE_PROJECT_NAME)"
    project="${project:-logistica}"
    chown root:33 .env && chmod 640 .env

    echo "==> Construction des images (le site reste en ligne)"
    # Numéro de version dans l'image : Sentry indique dans quelle version une erreur apparaît
    APP_VERSION="$version" docker compose build

    # Juste avant les migrations : de quoi revenir à l'état exact d'avant la mise à jour
    echo "==> Sauvegarde de la base (version en ligne : $previous)"
    if [[ -z "$(docker compose ps -q --status running postgres)" ]]; then
        echo "PostgreSQL ne tourne pas : pas de sauvegarde possible, mise à jour arrêtée (docker compose ps)." >&2
        exit 1
    fi
    BACKUP_LOCAL_ONLY=1 bash deploy/backup.sh "avant-$version"

    echo "==> Bascule (migrations comprises)"
    # Version en service notée dans .env : toute commande « docker compose … » lancée à la main la reprend
    env_set APP_VERSION "$version"
    APP_VERSION="$version" docker compose up -d --remove-orphans --wait
    keep_images "$project-app" "$version"
    keep_images "$project-web" "$version"
    docker image prune -f >/dev/null

    # Le site répond-il vraiment ? /up vérifie aussi la base et Redis
    echo "==> Contrôle du site"
    port="$(grep -E '^APP_PORT=' .env | tail -n1 | cut -d= -f2 | tr -d '"' || true)"
    if ! curl -fsS --retry 10 --retry-delay 3 --retry-all-errors -m 10 -o /dev/null "http://127.0.0.1:${port:-8090}/up"; then
        echo "Le site ne répond pas après la mise à jour vers $version (version précédente : $previous)." >&2
        echo "Journaux : docker compose logs --tail=100 app web — retour arrière : bash deploy/rollback.sh $previous" >&2
        exit 1
    fi

    echo "==> Terminé : version $version en ligne (précédente : $previous)"
}

env_get() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }

# Remplace ou ajoute NOM=valeur dans .env ; .env reste lisible par l'application des conteneurs (groupe www-data,
# gid 33) et fermé aux autres comptes
env_set() {
    if grep -qE "^$1=" .env; then
        sed -i -E "s|^$1=.*|$1=$2|" .env
    else
        printf '%s=%s\n' "$1" "$2" >> .env
    fi
    chown root:33 .env && chmod 640 .env
}

# Garde la version en service et les 3 plus récentes avant elle ; supprime les plus anciennes
keep_images() {
    local repo="$1" current="$2" tag kept=0
    while read -r tag; do
        [[ -z "$tag" || "$tag" == "<none>" ]] && continue
        if [[ "$tag" == "$current" ]]; then continue; fi
        if (( kept < 3 )); then kept=$((kept + 1)); continue; fi
        docker rmi "$repo:$tag" >/dev/null 2>&1 || true
    done < <(docker images "$repo" --format '{{.Tag}}')
}

# Tout le script est lu avant de commencer : la mise à jour peut remplacer ce fichier en cours de route
main "$@"

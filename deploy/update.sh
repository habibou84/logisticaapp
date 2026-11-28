#!/usr/bin/env bash
#
# Mise à jour : dernière version de la branche suivie, nouvelles images, migrations, bascule. À lancer en root :
#
#   bash /chemin/de/l/application/deploy/update.sh
#
# Le site reste en ligne pendant la construction et les migrations (lancées avec la nouvelle image pendant que
# l'ancienne version sert encore : elles doivent rester compatibles avec elle, voir CLAUDE.md). Seule la
# bascule coupe, environ une seconde : le nouveau conteneur « app » n'a presque plus rien à faire au démarrage,
# et l'interface réessaie seule les lectures qui tombent pendant ce temps.

set -euo pipefail

main() {
    local app_dir branch
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
    git checkout -q "$branch"
    # TARGET : version précise validée par GitHub (deploy/auto-update.sh), sinon la dernière de la branche
    git reset -q --hard "${TARGET:-origin/$branch}"

    # .env lisible par l'application des conteneurs (groupe www-data, gid 33), fermé aux autres comptes
    chown root:33 .env && chmod 640 .env

    echo "==> Construction des images (le site reste en ligne)"
    docker compose build

    echo "==> Migrations (le site reste en ligne)"
    docker compose run --rm -T -e CONTAINER_ROLE=migrate app

    # Bascule du site seul, un conteneur à la fois : « docker compose up » d'un bloc arrêterait d'abord TOUS les
    # conteneurs, Horizon compris (jusqu'à 60 s pour finir ses tâches), avant d'en redémarrer un seul : le site
    # serait coupé tout ce temps. Ici, PHP-FPM puis Nginx sont remplacés chacun en une fraction de seconde
    # (Nginx retrouve tout seul la nouvelle adresse de « app » : voir docker/nginx.conf).
    echo "==> Bascule du site"
    local started=$SECONDS
    docker compose up -d --no-deps --wait app
    docker compose up -d --no-deps --wait web
    echo "    nouvelle version en ligne en $((SECONDS - started)) s"

    echo "==> Files d'attente, temps réel, tâches planifiées (sans effet sur le site)"
    docker compose up -d --remove-orphans --wait
    docker image prune -f >/dev/null

    echo "==> Terminé : version $(git rev-parse --short HEAD)"
}

# Tout le script est lu avant de commencer : la mise à jour peut remplacer ce fichier en cours de route
main "$@"

#!/usr/bin/env bash
#
# Mise à jour : dernière version de la branche suivie, nouvelles images, redémarrage des conteneurs.
# Les migrations s'appliquent au démarrage du conteneur « app ». À lancer en root :
#
#   bash /chemin/de/l/application/deploy/update.sh
#
# Le site reste en ligne pendant la construction ; la bascule ne coupe que quelques secondes.

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

    echo "==> Bascule (migrations comprises)"
    docker compose up -d --remove-orphans --wait
    docker image prune -f >/dev/null

    echo "==> Terminé : version $(git rev-parse --short HEAD)"
}

# Tout le script est lu avant de commencer : la mise à jour peut remplacer ce fichier en cours de route
main "$@"

#!/usr/bin/env bash
#
# Mise à jour de l'application déjà installée (install.sh, ispconfig.sh ou preprod.sh), à lancer en root :
#
#   bash /chemin/de/l/application/deploy/update.sh
#
# Met à jour l'installation du dossier où se trouve ce script (production ou préproduction), sans
# couper le site :
#   1. la nouvelle version est préparée à côté (copie de travail dans storage/app/deploy-build) et son
#      interface compilée pendant que l'ancienne reste en ligne ; en cas d'erreur, rien n'est changé ;
#   2. bascule : code, dépendances PHP, nouvelle interface (remplacée d'un coup), caches ;
#      le mode maintenance n'est activé que s'il y a des migrations, le temps de les appliquer ;
#   3. redémarrage en douceur des processus.
#
# Forcer le mode maintenance pendant toute la bascule (migration délicate) :
#   MAINTENANCE=1 bash /chemin/de/l/application/deploy/update.sh
#
# Changer la branche suivie (une seule fois, ex. production sur la branche « production ») :
#   BRANCH=production bash /chemin/de/l/application/deploy/update.sh

set -euo pipefail

# Tout le script est lu avant de commencer : la mise à jour remplace ce fichier en cours de route
main() {
    . "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

    [[ $EUID -eq 0 ]] || fail "lancez ce script en root."
    load_deploy_conf "$(dirname "${BASH_SOURCE[0]}")/.."

    BRANCH="${BRANCH:-$(as_app "git rev-parse --abbrev-ref HEAD")}"
    local work="$APP_DIR/storage/app/deploy-build" next="$APP_DIR/public/build-next"

    step "Préparation de la nouvelle version (le site reste en ligne)"
    as_app "git fetch origin '+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH'"
    if [[ -n "$(as_app "git status --porcelain --untracked-files=no")" ]]; then
        as_app "git status --short --untracked-files=no"
        fail "des fichiers du code ont été modifiés sur le serveur (ci-dessus) : annulez-les (cd $APP_DIR && git checkout -- .) puis relancez."
    fi
    local target
    target="$(as_app "git rev-parse 'origin/$BRANCH'")"
    as_app "git worktree prune"
    if [[ -d "$work/.git" || -f "$work/.git" ]]; then
        as_user "cd '$work' && git checkout -q --force --detach '$target' && git clean -qfd -e node_modules -e vendor"
    else
        as_app "git worktree add -f --detach '$work' '$target'"
    fi
    # Réglages (VITE_REVERB_* lus à la compilation) et vues de pagination de Laravel : ceux de l'installation
    as_user "ln -sfn '$APP_DIR/.env' '$work/.env' && ln -sfn '$APP_DIR/vendor' '$work/vendor'"

    step "Interface (compilation à côté de la version en ligne)"
    # Dépendances npm réinstallées seulement si elles ont changé
    local lock_sum
    lock_sum="$(sha1sum "$work/package-lock.json" | cut -d' ' -f1)"
    if [[ "$(cat "$work/node_modules/.deploy-lock" 2>/dev/null || true)" != "$lock_sum" ]]; then
        as_user "cd '$work' && npm ci --no-audit --no-fund && echo '$lock_sum' > node_modules/.deploy-lock"
    fi
    as_app "rm -rf '$next' && mkdir -p '$next'"
    as_app "node '$work/deploy/carry-assets.mjs' public/build '$next'"
    as_user "cd '$work' && npx vite build --outDir '$next' --emptyOutDir false --logLevel warn"
    [[ -f "$next/manifest.json" ]] || fail "la compilation de l'interface n'a pas produit de manifest.json : rien n'a été changé."

    # Maintenance seulement s'il y a des migrations (nouvelles, ou restées en attente)
    local maintenance="${MAINTENANCE:-0}"
    if [[ -n "$(as_app "git diff --name-only --diff-filter=A HEAD '$target' -- database/migrations")" ]] \
        || as_app "php artisan migrate:status --pending --no-ansi 2>/dev/null || true" | grep -q 'Pending'; then
        maintenance=1
    fi

    if [[ "$maintenance" == "1" ]]; then
        step "Bascule vers la nouvelle version (maintenance le temps des migrations)"
        as_app "php artisan down --retry=15 || true"
        trap 'as_app "php artisan up"' EXIT
    else
        step "Bascule vers la nouvelle version (sans maintenance : aucune migration)"
    fi
    as_app "git checkout -q '$BRANCH' && git reset -q --hard '$target'"
    as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"
    # Toujours lancé : sans effet s'il n'y a rien à appliquer
    as_app "php artisan migrate --force"
    # Nouvelle interface d'un coup (deux renommages instantanés)
    as_app "rm -rf public/build-prev && { [[ ! -d public/build ]] || mv public/build public/build-prev; } && mv '$next' public/build && rm -rf public/build-prev"
    as_app "php artisan optimize"
    if [[ "$maintenance" == "1" ]]; then
        as_app "php artisan up"
        trap - EXIT
    fi

    step "Tâches de fond"
    # Rôles et permissions ajoutés par la nouvelle version
    as_app "php artisan db:seed --class=RolesAndPermissionsSeeder --force"
    # Positions des marchands d'après les ramassages déjà faits (sans effet si rien de nouveau)
    as_app "php artisan merchants:locate"

    step "Redémarrage en douceur des processus"
    # PHP-FPM : rechargement sans couper les requêtes en cours (vide le cache OPcache de l'ancien code)
    for unit in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' | awk '{print $1}'); do
        systemctl reload "$unit"
    done
    # Horizon finit ses tâches en cours puis Supervisor le relance sur le nouveau code
    as_app "php artisan horizon:terminate"
    supervisorctl restart "$INSTANCE-reverb"
    supervisorctl status

    step "Terminé ($INSTANCE, branche $BRANCH) : $(as_app "git log -1 --format='%h %s'")"
}

main "$@"
exit

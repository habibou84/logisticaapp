#!/usr/bin/env bash
#
# Retour arrière en quelques secondes : remet en service une version précédente dont les images sont encore sur
# le serveur (deploy/update.sh garde les 3 dernières). Rien n'est reconstruit, le code (git) ne change pas.
#
#   bash deploy/rollback.sh              # versions disponibles (la version en service est marquée)
#   bash deploy/rollback.sh v1.3.0       # remet v1.3.0 en service (ou un commit abrégé : 1a2b3c4)
#
# La base n'est PAS remise en arrière : les migrations de la version retirée restent (sans conséquence quand elles
# ne font qu'ajouter). Si elles ont abîmé des données : restaurer la sauvegarde …_avant-<version retirée>.sql.gz
# (docs/DEPLOIEMENT.md). La mise à jour automatique ne réinstalle pas la version retirée : elle attend la suivante.

set -euo pipefail

app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$app_dir"

[[ $EUID -eq 0 ]] || { echo "Lancez ce script en root." >&2; exit 1; }
[[ -f .env ]] || { echo "Pas encore installé : lancez deploy/install.sh." >&2; exit 1; }

env_get() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }
project="$(env_get COMPOSE_PROJECT_NAME)"
project="${project:-logistica}"
current="$(env_get APP_VERSION)"
log="/var/log/$project-update.log"

subject() { git log -1 --format='%s' "$1" 2>/dev/null || true; }

if [[ $# -eq 0 ]]; then
    echo "Versions disponibles sur le serveur (la plus récente en premier) :"
    docker images "$project-app" --format '{{.Tag}}\t{{.CreatedSince}}' | while IFS=$'\t' read -r tag since; do
        [[ "$tag" == "<none>" ]] && continue
        mark="  "; [[ "$tag" == "$current" ]] && mark="▶ "
        printf '  %s%-12s %-16s %s\n' "$mark" "$tag" "$since" "$(subject "$tag")"
    done
    echo
    echo "▶ = en service. Pour revenir à une version : bash deploy/rollback.sh <version>"
    exit 0
fi

target="$1"
[[ "$target" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] || { echo "Nom de version invalide : $target" >&2; exit 1; }
[[ "$target" != "$current" ]] || { echo "La version $target est déjà en service."; exit 0; }
for image in "$project-app:$target" "$project-web:$target"; do
    docker image inspect "$image" >/dev/null 2>&1 \
        || { echo "Image $image absente du serveur : bash deploy/rollback.sh pour la liste." >&2; exit 1; }
done

echo "==> Retour à la version $target (en service : ${current:-?})"
if grep -qE '^APP_VERSION=' .env; then
    sed -i -E "s|^APP_VERSION=.*|APP_VERSION=$target|" .env
else
    printf 'APP_VERSION=%s\n' "$target" >> .env
fi
chown root:33 .env && chmod 640 .env
docker compose up -d --no-build --remove-orphans --wait

echo "==> Contrôle du site"
port="$(env_get APP_PORT)"
if ! curl -fsS --retry 10 --retry-delay 3 --retry-all-errors -m 10 -o /dev/null "http://127.0.0.1:${port:-8090}/up"; then
    echo "Le site ne répond pas avec la version $target : docker compose logs --tail=100 app web" >&2
    exit 1
fi

# La mise à jour automatique ne réinstalle pas la version retirée (elle attend la suivante)
[[ -n "$current" ]] && echo "$current" > "/var/tmp/$project-auto-update-skip"
printf '%s Retour arrière : version %s remise en service (à la place de %s, qui ne sera pas réinstallée).\n' "$(date '+%F %T')" "$target" "${current:-?}" >> "$log"
echo "==> Version $target en service (à la place de ${current:-?})."
echo "    Base de données inchangée. Si la version retirée a abîmé des données :"
echo "    ls /var/backups/$project/*_avant-${current:-…}.sql.gz   puis restauration (docs/DEPLOIEMENT.md, section 5)."

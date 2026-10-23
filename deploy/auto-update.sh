#!/usr/bin/env bash
#
# Mise à jour automatique : toutes les 5 minutes (cron), le serveur regarde s'il y a une nouvelle version sur
# la branche suivie. Elle n'est installée (deploy/update.sh) que si les contrôles GitHub (CI : style, tests,
# build) sont passés sur cette version précise ; sinon elle attend, ou est écartée si un contrôle a échoué.
# Rien n'est ouvert sur le serveur : il va chercher le code, GitHub ne s'y connecte pas.
#
#   bash deploy/auto-update.sh --install   # active (cron toutes les 5 minutes)
#   bash deploy/auto-update.sh --remove    # désactive
#   bash deploy/auto-update.sh --status    # état : version installée, dernière version, contrôles
#   bash deploy/auto-update.sh --now       # installe tout de suite la dernière version si ses contrôles sont passés
#   touch .no-auto-update                  # pause (ex. pendant une intervention), rm pour reprendre
#
# Journal : /var/log/<projet>-update.log

set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR" || exit 1
PROJECT="$(grep -E '^COMPOSE_PROJECT_NAME=' .env 2>/dev/null | cut -d= -f2 | tr -d '"')"
PROJECT="${PROJECT:-logistica}"
LOG="/var/log/$PROJECT-update.log"
CRON="/etc/cron.d/$PROJECT-auto-update"
# Dernière version écartée (contrôle en échec) : signalée une seule fois
REFUSED="/var/tmp/$PROJECT-auto-update-refused"

log() { printf '%s %s\n' "$(date '+%F %T')" "$*" >> "$LOG"; }
say() { printf '%s\n' "$*"; }

[[ $EUID -eq 0 ]] || { say "Lancez ce script en root."; exit 1; }

branch="$(git rev-parse --abbrev-ref HEAD)"
repo="$(git remote get-url origin | sed -E 's#^(git@github\.com:|https://github\.com/)##; s#\.git$##')"

# Verdict des contrôles GitHub d'une version : success, pending, failure ou none (pas encore lancés)
checks() {
    curl -fsS --max-time 20 -H 'Accept: application/vnd.github+json' \
        "https://api.github.com/repos/$repo/commits/$1/check-runs?per_page=100" 2>/dev/null \
    | python3 -c '
import json, sys
try:
    runs = json.load(sys.stdin).get("check_runs", [])
except Exception:
    print("unknown"); sys.exit()
if not runs:
    print("none")
elif any(r["status"] != "completed" for r in runs):
    print("pending")
elif all(r["conclusion"] in ("success", "skipped", "neutral") for r in runs):
    print("success")
else:
    failed = [r["name"] for r in runs if r["conclusion"] not in ("success", "skipped", "neutral")]
    print("failure:" + ", ".join(failed))
' || echo unknown
}

case "${1:-}" in
    --install)
        cat > "$CRON" <<CRONTAB
# Mise à jour automatique de l'application (deploy/auto-update.sh) : nouvelle version validée par GitHub
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * root bash $APP_DIR/deploy/auto-update.sh >/dev/null 2>&1
CRONTAB
        touch "$LOG"
        say "Mise à jour automatique activée (toutes les 5 minutes, branche $branch)."
        say "Journal : $LOG — pause : touch $APP_DIR/.no-auto-update"
        exit 0
        ;;
    --remove)
        rm -f "$CRON"
        say "Mise à jour automatique désactivée."
        exit 0
        ;;
    --status)
        git fetch -q origin "+refs/heads/$branch:refs/remotes/origin/$branch" || say "Impossible de joindre GitHub."
        say "Branche suivie      : $branch"
        say "Version installée   : $(git log -1 --format='%h %ad %s' --date=format:'%d/%m %H:%M' HEAD)"
        say "Dernière version    : $(git log -1 --format='%h %ad %s' --date=format:'%d/%m %H:%M' "origin/$branch")"
        say "Contrôles GitHub    : $(checks "$(git rev-parse "origin/$branch")")"
        say "Automatique         : $([[ -f $CRON ]] && echo activé || echo désactivé)$([[ -f .no-auto-update ]] && echo ' (en pause)')"
        say "Journal (fin) :"
        tail -n 8 "$LOG" 2>/dev/null | sed 's/^/  /'
        exit 0
        ;;
    --now|'') ;;
    *) say "Option inconnue : $1"; exit 1 ;;
esac

[[ -f .no-auto-update && "${1:-}" != --now ]] && exit 0

# Une seule mise à jour à la fois
exec 9>"/var/lock/$PROJECT-auto-update.lock"
flock -n 9 || exit 0

# Journal : les 2 000 dernières lignes
if [[ -f $LOG && $(wc -l < "$LOG") -gt 4000 ]]; then
    tail -n 2000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi

git fetch -q origin "+refs/heads/$branch:refs/remotes/origin/$branch" || { log "GitHub injoignable (git fetch) : nouvel essai dans 5 minutes."; exit 1; }
current="$(git rev-parse HEAD)"
target="$(git rev-parse "origin/$branch")"
[[ "$current" == "$target" ]] && exit 0

short="${target:0:7}"
verdict="$(checks "$target")"
case "$verdict" in
    success)
        log "Nouvelle version $short ($(git log -1 --format=%s "$target")) : contrôles GitHub passés, mise à jour…"
        if TARGET="$target" bash deploy/update.sh >> "$LOG" 2>&1; then
            log "Version $short en ligne."
        else
            log "ÉCHEC de la mise à jour vers $short (détail ci-dessus) : la version précédente reste en ligne si les conteneurs n'ont pas redémarré ; nouvel essai dans 5 minutes."
            exit 1
        fi
        ;;
    failure:*)
        if [[ "$(cat "$REFUSED" 2>/dev/null)" != "$target" ]]; then
            log "Version $short écartée : contrôles GitHub en échec (${verdict#failure:}). On attend une version corrigée."
            echo "$target" > "$REFUSED"
        fi
        ;;
    pending|none) ;;   # contrôles en cours ou pas encore lancés : on repasse dans 5 minutes
    *) log "Contrôles GitHub illisibles pour $short (réseau ?) : nouvel essai dans 5 minutes." ;;
esac

#!/usr/bin/env bash
#
# Mise à jour automatique : toutes les 5 minutes (cron), le serveur regarde s'il y a une nouvelle version.
# Elle n'est installée (deploy/update.sh) que si les contrôles GitHub (CI : style, tests, build) sont passés sur
# cette version précise ; sinon elle attend, ou est écartée si un contrôle a échoué.
# Rien n'est ouvert sur le serveur : il va chercher le code, GitHub ne s'y connecte pas.
#
# Réglages dans .env :
#   AUTO_UPDATE_TRACK=branch           # (défaut) chaque nouveau commit de la branche suivie : main en préproduction,
#                                      # production en production (fusion d'une PR main → production)
#   AUTO_UPDATE_TRACK=tags             # variante : seulement les versions publiées v1.2.3 (Releases GitHub)
#   AUTO_UPDATE_WINDOW=20:00-07:00     # installation seulement dans cette plage horaire (vide : à toute heure),
#                                      # sauf version marquée « [immédiat] » (titre de la fusion) : tout de suite
#
#   bash deploy/auto-update.sh --install   # active (cron toutes les 5 minutes)
#   bash deploy/auto-update.sh --remove    # désactive
#   bash deploy/auto-update.sh --status    # état : version installée, dernière version, contrôles
#   bash deploy/auto-update.sh --now       # installe tout de suite la dernière version validée (même hors plage horaire)
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
# Version retirée par deploy/rollback.sh : jamais réinstallée automatiquement
SKIP="/var/tmp/$PROJECT-auto-update-skip"
# Échecs d'installation d'une même version : 3 essais au plus
FAILED="/var/tmp/$PROJECT-auto-update-failed"
# Version en attente de la plage horaire : signalée une seule fois
WAITING="/var/tmp/$PROJECT-auto-update-waiting"

log() { printf '%s %s\n' "$(date '+%F %T')" "$*" >> "$LOG"; }
say() { printf '%s\n' "$*"; }
env_get() { grep -E "^$1=" .env 2>/dev/null | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
TRACK="$(env_get AUTO_UPDATE_TRACK)"; TRACK="${TRACK:-branch}"
WINDOW="$(env_get AUTO_UPDATE_WINDOW)"

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

# Version en service : APP_VERSION de .env (écrit par deploy/update.sh et deploy/rollback.sh), sinon le commit
installed() { local v; v="$(env_get APP_VERSION)"; [[ -n "$v" ]] && echo "$v" || git rev-parse --short HEAD; }

# Dernière version à installer : $target (commit) et $label (nom : v1.2.3 ou commit abrégé) ; vide si aucune
find_target() {
    target=""; label=""
    git fetch -q origin "+refs/heads/$branch:refs/remotes/origin/$branch" || return 1
    if [[ "$TRACK" == tags ]]; then
        git fetch -q --prune --force origin '+refs/tags/v*:refs/tags/v*' '+refs/tags/V*:refs/tags/V*' || return 1
        # v1.2.3 ou V1.2.3 (majuscule acceptée)
        label="$(git tag -l 'v*' 'V*' | grep -E '^[vV][0-9]+\.[0-9]+\.[0-9]+$' | sort -t. -k1.2,1n -k2,2n -k3,3n | tail -n1 || true)"
        [[ -n "$label" ]] || return 0
        target="$(git rev-list -n1 "$label")"
        # Une version publiée doit avoir été fusionnée sur la branche suivie (main), donc passée par une PR
        if ! git merge-base --is-ancestor "$target" "origin/$branch"; then
            if [[ "$(cat "$REFUSED" 2>/dev/null)" != "$target" ]]; then
                log "Version $label ignorée : elle n'est pas sur $branch (publiez une version à partir de $branch)."
                echo "$target" > "$REFUSED"
            fi
            target=""; label=""
        fi
    else
        target="$(git rev-parse "origin/$branch")"
        label="$(git rev-parse --short "$target")"
    fi
}

# Dans la plage horaire AUTO_UPDATE_WINDOW (ex. 20:00-07:00, peut passer minuit) ? Toujours vrai si vide
in_window() {
    [[ -z "$WINDOW" ]] && return 0
    [[ "$WINDOW" =~ ^([0-9]{1,2}):([0-9]{2})-([0-9]{1,2}):([0-9]{2})$ ]] || { log "AUTO_UPDATE_WINDOW illisible ($WINDOW) : ignorée."; return 0; }
    local now start end
    now=$((10#$(date +%H) * 60 + 10#$(date +%M)))
    start=$((10#${BASH_REMATCH[1]} * 60 + 10#${BASH_REMATCH[2]}))
    end=$((10#${BASH_REMATCH[3]} * 60 + 10#${BASH_REMATCH[4]}))
    if (( start <= end )); then (( now >= start && now < end )); else (( now >= start || now < end )); fi
}

case "${1:-}" in
    --install)
        cat > "$CRON" <<CRONTAB
# Mise à jour automatique de l'application (deploy/auto-update.sh) : nouvelle version validée par GitHub
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * root bash $APP_DIR/deploy/auto-update.sh >/dev/null 2>&1
CRONTAB
        touch "$LOG"
        say "Mise à jour automatique activée (toutes les 5 minutes ; suivi : $([[ $TRACK == tags ]] && echo 'versions publiées v*' || echo "branche $branch"))."
        [[ -n "$WINDOW" ]] && say "Installation seulement entre ${WINDOW/-/ et }."
        say "Journal : $LOG — pause : touch $APP_DIR/.no-auto-update"
        exit 0
        ;;
    --remove)
        rm -f "$CRON"
        say "Mise à jour automatique désactivée."
        exit 0
        ;;
    --status)
        find_target || say "Impossible de joindre GitHub."
        say "Suivi               : $([[ $TRACK == tags ]] && echo "versions publiées v* (fusionnées sur $branch)" || echo "branche $branch")"
        say "Version en service  : $(installed) — $(git log -1 --format='%s' "$(installed)" 2>/dev/null)"
        if [[ -n "$target" ]]; then
            say "Dernière version    : $label — $(git log -1 --format='%ad %s' --date=format:'%d/%m %H:%M' "$target")"
            say "Contrôles GitHub    : $(checks "$target")"
        else
            say "Dernière version    : aucune$([[ $TRACK == tags ]] && echo ' (pas encore de version publiée v1.2.3)')"
        fi
        say "Plage horaire       : ${WINDOW:-à toute heure}$([[ -n $WINDOW ]] && ! in_window && echo ' (hors plage en ce moment)')"
        [[ -s $SKIP ]] && say "Retirée (retour arr.) : $(cat "$SKIP") — ne sera pas réinstallée"
        say "Automatique         : $([[ -f $CRON ]] && echo activé || echo désactivé)$([[ -f .no-auto-update ]] && echo ' (en pause)')"
        say "Versions sur le serveur (retour arrière) : bash deploy/rollback.sh"
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

find_target || { log "GitHub injoignable (git fetch) : nouvel essai dans 5 minutes."; exit 1; }
[[ -n "$target" ]] || exit 0
[[ "$label" == "$(installed)" ]] && exit 0
# Version retirée par un retour arrière : on attend la suivante
[[ "$label" == "$(cat "$SKIP" 2>/dev/null)" && "${1:-}" != --now ]] && exit 0

verdict="$(checks "$target")"
case "$verdict" in
    success)
        # Mise en production immédiate : « [immédiat] » (ou « [urgent] ») dans le message de la version
        # (titre de la fusion main → production) ; sinon, seulement dans la plage horaire
        urgent=""
        git log -1 --format=%B "$target" | grep -qiE '\[(immediat|immédiat|IMMÉDIAT|urgent)\]' && urgent=1
        if [[ "${1:-}" != --now && -z "$urgent" ]] && ! in_window; then
            if [[ "$(cat "$WAITING" 2>/dev/null)" != "$label" ]]; then
                log "Version $label validée : installation dans la plage horaire ($WINDOW)."
                echo "$label" > "$WAITING"
            fi
            exit 0
        fi
        failures="$(awk -v l="$label" '$1 == l {print $2}' "$FAILED" 2>/dev/null)"; failures="${failures:-0}"
        if [[ "${1:-}" != --now ]] && (( failures >= 3 )); then exit 0; fi
        log "Nouvelle version $label ($(git log -1 --format=%s "$target")) : contrôles GitHub passés, mise à jour$([[ -n $urgent ]] && ! in_window && echo ' immédiate (hors plage horaire, demandée)')…"
        if TARGET="$target" VERSION="$label" bash deploy/update.sh >> "$LOG" 2>&1; then
            rm -f "$FAILED" "$WAITING" "$SKIP"
            log "Version $label en ligne."
        else
            failures=$((failures + 1))
            echo "$label $failures" > "$FAILED"
            if (( failures >= 3 )); then
                log "ÉCHEC de la mise à jour vers $label (3e essai, détail ci-dessus) : abandon. Version en service : $(installed). Après correction : bash deploy/auto-update.sh --now"
            else
                log "ÉCHEC de la mise à jour vers $label (détail ci-dessus). Version en service : $(installed) ; nouvel essai dans 5 minutes."
            fi
            exit 1
        fi
        ;;
    failure:*)
        if [[ "$(cat "$REFUSED" 2>/dev/null)" != "$target" ]]; then
            log "Version $label écartée : contrôles GitHub en échec (${verdict#failure:}). On attend une version corrigée."
            echo "$target" > "$REFUSED"
        fi
        ;;
    pending|none) ;;   # contrôles en cours ou pas encore lancés : on repasse dans 5 minutes
    *) log "Contrôles GitHub illisibles pour $label (réseau ?) : nouvel essai dans 5 minutes." ;;
esac

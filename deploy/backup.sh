#!/usr/bin/env bash
#
# Sauvegarde de la base PostgreSQL : /var/backups/<projet>/AAAA-MM-JJ_HHMM[_étiquette].sql.gz
#
#   bash deploy/backup.sh                  # sauvegarde de la nuit (cron, voir deploy/install.sh) : gardée 14 jours
#   bash deploy/backup.sh avant-1a2b3c4    # avant une mise à jour (deploy/update.sh) : les 10 dernières gardées
#
# Copie hors du serveur (recommandé : si le serveur est perdu, ses sauvegardes le sont aussi) : fichier
# .env.offsite à la racine de l'application, voir deploy/offsite.env.example. La copie passe par rclone
# dans un conteneur Docker : rien n'est installé sur le serveur. Chaque nuit :
#   - la base, avec une rotation : jour/ (14 jours), semaine/ (12 semaines), mois/ (12 mois) ;
#   - les fichiers (photos, documents) : miroir dans fichiers/actuel, suppressions gardées 30 jours.
#
# Surveillance : BACKUP_PING_URL dans .env (ex. https://hc-ping.com/…, healthchecks.io) est appelée à chaque
# sauvegarde de la nuit réussie, et <adresse>/fail en cas d'échec ; le service prévient si rien n'arrive.
#
# BACKUP_LOCAL_ONLY=1 : ni copie hors du serveur, ni signal de surveillance (utilisé par deploy/update.sh).
#
# Restauration (attention : remplace la base actuelle) :
#   gunzip -c /var/backups/logistica/<fichier>.sql.gz | docker compose exec -T postgres psql -U <DB_USERNAME> -d <DB_DATABASE>

set -Eeuo pipefail

RCLONE_IMAGE="rclone/rclone:1.68.2"

app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$app_dir"

env_get() { grep -E "^$1=" "${2:-.env}" 2>/dev/null | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }
project="$(env_get COMPOSE_PROJECT_NAME)"
project="${project:-logistica}"
label="${1:-}"
local_only="${BACKUP_LOCAL_ONLY:-}"
ping_url="$(env_get BACKUP_PING_URL)"

ping() {
    [[ -z "$local_only" && -z "$label" && -n "$ping_url" ]] || return 0
    curl -fsS -m 10 --retry 3 -o /dev/null "$ping_url$1" || true
}
# Toute erreur (base injoignable, disque plein, copie hors du serveur refusée) : signalée à la surveillance
trap 'echo "$(date "+%F %T") ÉCHEC de la sauvegarde (ligne $LINENO)" >&2; ping /fail' ERR

[[ -z "$label" || "$label" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "Étiquette invalide : $label" >&2; exit 1; }

dir="/var/backups/$project"
mkdir -p "$dir"
chmod 700 "$dir"

name="$(date +%F_%H%M)${label:+_$label}.sql.gz"
file="$dir/$name"
# Fichier temporaire puis renommage : jamais de sauvegarde à moitié écrite sous un nom valide
docker compose exec -T postgres pg_dump -U "$(env_get DB_USERNAME)" -d "$(env_get DB_DATABASE)" --no-owner \
    | gzip > "$file.part"
gzip -t "$file.part"
mv "$file.part" "$file"
chmod 600 "$file"
echo "$(date '+%F %T') sauvegarde : $file ($(du -h "$file" | cut -f1))"

# Sur le serveur : 14 jours, et seulement les 10 dernières sauvegardes d'avant mise à jour
find "$dir" -name '*.sql.gz' -mtime +14 -delete
find "$dir" -name '*.part' -mmin +60 -delete
{ ls -1t "$dir"/*_avant-*.sql.gz 2>/dev/null || true; } | tail -n +11 | xargs -r rm -f

# ───────────────────────────── Hors du serveur ─────────────────────────────
# Seulement la sauvegarde de la nuit (sans étiquette) : celles d'avant mise à jour restent sur le serveur
offsite=".env.offsite"
offsite_copy() {
    local dest when files keep_daily keep_weekly keep_monthly keep_deleted max_delete app_container
    dest="$(env_get OFFSITE_DEST "$offsite")"
    [[ -n "$dest" ]] || { echo "OFFSITE_DEST manquant dans $offsite" >&2; false; }
    keep_daily="$(env_get OFFSITE_KEEP_DAILY "$offsite")"
    keep_weekly="$(env_get OFFSITE_KEEP_WEEKLY "$offsite")"
    keep_monthly="$(env_get OFFSITE_KEEP_MONTHLY "$offsite")"
    files="$(env_get OFFSITE_FILES "$offsite")"
    keep_deleted="$(env_get OFFSITE_FILES_KEEP_DELETED "$offsite")"
    max_delete="$(env_get OFFSITE_FILES_MAX_DELETE "$offsite")"

    # Une seule copie à la fois (la première copie des fichiers peut être longue)
    exec 8>"/var/lock/$project-backup-offsite.lock"
    if ! flock -n 8; then
        echo "$(date '+%F %T') copie hors du serveur déjà en cours : sautée cette fois"
        return 0
    fi

    # « chiffre: » ou « stockage:bucket/dossier » : pas de « / » ajouté après les deux-points
    at() { [[ "$dest" == *: ]] && printf '%s%s' "$dest" "$1" || printf '%s/%s' "${dest%/}" "$1"; }
    rclone() { docker run --rm --env-file "$app_dir/$offsite" -v "$dir:/data:ro" "$RCLONE_IMAGE" "$@"; }
    # Suppression ; un dossier pas encore créé (semaine/ avant le premier dimanche…) n'est pas une erreur (code 3)
    rclone_delete() { rclone delete "$@" || [[ $? == 3 ]]; }
    # Supprime d'un dossier les sauvegardes plus vieilles que N jours (0 : ne jamais supprimer)
    prune() { [[ "$2" == 0 ]] || rclone_delete "$(at "$1")" --min-age "${2}d" --include '*.sql.gz'; }

    # Base : rotation jour / semaine / mois (le dimanche et le 1er du mois, la même sauvegarde est aussi rangée
    # dans semaine/ et mois/). Environ 14 + 12 + 12 copies au lieu d'une par jour.
    rclone copyto "/data/$name" "$(at "jour/$name")"
    when="jour"
    if [[ "$(date +%u)" == 7 ]]; then rclone copyto "/data/$name" "$(at "semaine/$name")"; when+=", semaine"; fi
    if [[ "$(date +%d)" == 01 ]]; then rclone copyto "/data/$name" "$(at "mois/$name")"; when+=", mois"; fi
    prune jour "${keep_daily:-14}"
    prune semaine "$(( ${keep_weekly:-12} * 7 ))"
    prune mois "$(( ${keep_monthly:-12} * 31 ))"
    # Ancienne disposition (sauvegardes à la racine, avant la rotation) : effacées au bout de 14 jours
    rclone_delete "$dest" --max-depth 1 --min-age 14d --include '*.sql.gz'
    echo "$(date '+%F %T') base copiée hors du serveur ($when) : $(at "jour/$name")"

    # Fichiers (photos de preuve, pièces d'identité, documents, logos) : storage/app du conteneur « app ».
    # Miroir dans fichiers/actuel ; ce qui est supprimé ou remplacé sur le serveur part dans
    # fichiers/supprimes/<date> et y reste 30 jours (protège d'une suppression par erreur).
    # Garde-fou : au-delà de 500 suppressions d'un coup (volume vidé par erreur ?), la copie s'arrête en erreur et la
    # surveillance prévient ; ce qui a déjà été retiré reste récupérable dans fichiers/supprimes.
    # Une seule ligne de bilan dans le journal (nombre de fichiers et volume envoyés).
    [[ "$files" == 0 ]] && return 0
    app_container="$(docker compose ps -q app)"
    [[ -n "$app_container" ]] || { echo "Conteneur « app » arrêté : fichiers non copiés." >&2; false; }
    docker run --rm --env-file "$app_dir/$offsite" --volumes-from "$app_container:ro" "$RCLONE_IMAGE" \
        sync /var/www/html/storage/app "$(at fichiers/actuel)" \
        --backup-dir "$(at "fichiers/supprimes/$(date +%F_%H%M)")" \
        --exclude '.gitignore' --transfers 4 \
        --max-delete "${max_delete:-500}" \
        --stats 24h --stats-one-line --stats-log-level NOTICE
    [[ "${keep_deleted:-30}" == 0 ]] || rclone_delete "$(at fichiers/supprimes)" --min-age "${keep_deleted:-30}d"
    echo "$(date '+%F %T') fichiers copiés hors du serveur : $(at fichiers/actuel)"
}

if [[ -z "$local_only" && -z "$label" && -f "$offsite" ]]; then
    offsite_copy
fi

ping ""

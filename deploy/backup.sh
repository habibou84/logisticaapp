#!/usr/bin/env bash
#
# Sauvegarde de la base PostgreSQL : /var/backups/<projet>/AAAA-MM-JJ_HHMM[_étiquette].sql.gz
#
#   bash deploy/backup.sh                  # sauvegarde de la nuit (cron, voir deploy/install.sh) : gardée 14 jours
#   bash deploy/backup.sh avant-1a2b3c4    # avant une mise à jour (deploy/update.sh) : les 10 dernières gardées
#
# Copie hors du serveur (recommandé : si le serveur est perdu, ses sauvegardes le sont aussi) : fichier
# .env.offsite à la racine de l'application, voir deploy/offsite.env.example. La copie passe par rclone
# dans un conteneur Docker : rien n'est installé sur le serveur.
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

# Hors du serveur
offsite=".env.offsite"
if [[ -z "$local_only" && -f "$offsite" ]]; then
    dest="$(env_get OFFSITE_DEST "$offsite")"
    keep="$(env_get OFFSITE_KEEP_DAYS "$offsite")"
    keep="${keep:-90}"
    [[ -n "$dest" ]] || { echo "OFFSITE_DEST manquant dans $offsite" >&2; false; }
    rclone() { docker run --rm --env-file "$app_dir/$offsite" -v "$dir:/data:ro" "$RCLONE_IMAGE" "$@"; }
    # « chiffre: » ou « stockage:bucket/dossier » : pas de « / » ajouté après les deux-points
    [[ "$dest" == *: ]] && target="$dest$name" || target="${dest%/}/$name"
    rclone copyto "/data/$name" "$target"
    if [[ "$keep" != 0 ]]; then
        rclone delete "$dest" --min-age "${keep}d" --include '*.sql.gz'
    fi
    echo "$(date '+%F %T') copie hors du serveur : $target"
fi

ping ""

#!/usr/bin/env bash
#
# Sauvegarde de la base PostgreSQL (lancée chaque nuit par cron, voir deploy/install.sh) :
# /var/backups/<projet>/AAAA-MM-JJ_HHMM.sql.gz, gardées 14 jours.
#
# Restauration (attention : remplace la base actuelle) :
#   gunzip -c /var/backups/logistica/<fichier>.sql.gz | docker compose exec -T postgres psql -U <DB_USERNAME> -d <DB_DATABASE>

set -euo pipefail

app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$app_dir"

env_get() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
project="$(env_get COMPOSE_PROJECT_NAME)"
project="${project:-logistica}"
dir="/var/backups/$project"
mkdir -p "$dir"
chmod 700 "$dir"

file="$dir/$(date +%F_%H%M).sql.gz"
docker compose exec -T postgres pg_dump -U "$(env_get DB_USERNAME)" -d "$(env_get DB_DATABASE)" --no-owner | gzip > "$file"
find "$dir" -name '*.sql.gz' -mtime +14 -delete
echo "$(date '+%F %T') sauvegarde : $file ($(du -h "$file" | cut -f1))"

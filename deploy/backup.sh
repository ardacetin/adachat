#!/bin/sh
# Nightly backup of an Ada Chat installation (docs/deployment.md, "Backups").
#
#   ADA_DIR=/var/www/ada BACKUP_DIR=/var/backups/ada deploy/backup.sh
#
# With Docker set ADA_DOCKER=1 and run it from the directory that holds
# compose.production.yml. Keeps KEEP_DAYS days of backups. The .env file
# (APP_KEY) is NOT included: keep a copy of it somewhere else, offline.
set -eu

ADA_DIR=${ADA_DIR:-/var/www/ada}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/ada}
KEEP_DAYS=${KEEP_DAYS:-14}
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
TARGET="$BACKUP_DIR/$STAMP"

umask 077
mkdir -p "$TARGET"

if [ "${ADA_DOCKER:-0}" = "1" ]; then
    compose="docker compose -f compose.production.yml"
    # Credentials come from the container's own environment.
    $compose exec -T mysql sh -c 'exec mysqldump --single-transaction --quick --routines --triggers --no-tablespaces -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
        | gzip > "$TARGET/database.sql.gz"
    $compose exec -T app tar -C /app/storage -cf - app | gzip > "$TARGET/storage-app.tar.gz"
else
    # Reads the DB_* values from .env without exporting anything else.
    db_value() { grep -E "^$1=" "$ADA_DIR/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
    MYSQL_PWD=$(db_value DB_PASSWORD) mysqldump --single-transaction --quick --routines --triggers --no-tablespaces \
        -h "$(db_value DB_HOST)" -P "$(db_value DB_PORT)" -u "$(db_value DB_USERNAME)" "$(db_value DB_DATABASE)" \
        | gzip > "$TARGET/database.sql.gz"
    tar -C "$ADA_DIR/storage" -czf "$TARGET/storage-app.tar.gz" app
fi

# A dump that is cut short is worse than none: check that it is complete.
gzip -dc "$TARGET/database.sql.gz" | tail -1 | grep -q 'Dump completed' || {
    echo "Database dump in $TARGET is incomplete." >&2
    exit 1
}

find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf {} +

echo "Backup written to $TARGET"

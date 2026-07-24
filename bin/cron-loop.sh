#!/bin/sh

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting cron scheduler loop..."

# Run once on startup
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Initial run..."
php bin/console app:cron:run --no-interaction

while true; do
    # Calculate seconds until next minute
    SLEEP_SEC=$((60 - $(date +%s) % 60))
    sleep "$SLEEP_SEC"
    
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] Executing scheduled tasks..."
    php bin/console app:cron:run --no-interaction
done

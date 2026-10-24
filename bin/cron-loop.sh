#!/bin/sh

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting cron scheduler loop..."

# Fast lane: short, time-critical jobs (e.g. Wanderer route alerts) must not wait behind long syncs
(
    while true; do
        sleep "$((60 - $(date +%s) % 60))"
        php bin/console app:cron:run --lane=fast --no-interaction
    done
) &

# Market lane: order book pulls take up to a minute and must not delay the character syncs
# A full Jita pull peaks at ~90 MB, the higher limit leaves room for a growing order book
(
    while true; do
        sleep "$((60 - $(date +%s) % 60))"
        php -d memory_limit=256M bin/console app:cron:run --lane=market --no-interaction
    done
) &

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

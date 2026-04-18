#!/bin/bash
set -euo pipefail

# ============================================================
# WAIpress Single-Container Entrypoint
# Starts HeliosDB-Nano + cron, then hands off to WordPress
# ============================================================

echo "WAIpress: Starting HeliosDB-Nano..."

/usr/local/bin/heliosdb-nano start \
    --data-dir /data \
    --listen 127.0.0.1 \
    --port 5432 \
    --http-port 8080 \
    --mysql \
    --mysql-listen 127.0.0.1:3306 &

HELIOS_PID=$!

# Wait for HeliosDB-Nano to be ready
echo "WAIpress: Waiting for HeliosDB-Nano..."
max_attempts=30
attempt=0
while true; do
    if curl -sf http://127.0.0.1:8080/health > /dev/null 2>&1; then
        echo "WAIpress: HeliosDB-Nano is ready (PID: $HELIOS_PID)."
        break
    fi
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "WAIpress: ERROR - HeliosDB-Nano did not start after ${max_attempts} attempts."
        exit 1
    fi
    sleep 1
done

# Start system cron daemon for WP-Cron reliability
echo "WAIpress: Starting cron daemon..."
cron

# Background monitor: restart HeliosDB-Nano if it crashes
(
    while true; do
        if ! kill -0 $HELIOS_PID 2>/dev/null; then
            echo "WAIpress: HeliosDB-Nano crashed, restarting..."
            /usr/local/bin/heliosdb-nano start \
                --data-dir /data \
                --listen 127.0.0.1 \
                --port 5432 \
                --http-port 8080 \
                --mysql \
                --mysql-listen 127.0.0.1:3306 &
            HELIOS_PID=$!
            echo "WAIpress: HeliosDB-Nano restarted (PID: $HELIOS_PID)."
        fi
        sleep 10
    done
) &

echo "WAIpress: Starting WordPress..."

# Hand off to the original WordPress entrypoint
exec docker-entrypoint.sh "$@"

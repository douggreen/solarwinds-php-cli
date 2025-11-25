#!/bin/bash
# Test interrupt handling with --2d
# First clear the database to force a sync, then interrupt mid-sync

echo "Clearing recent data from database..."
sqlite3 ~/.solarwinds/logs.db "DELETE FROM logs WHERE time >= date('now', '-2 days')"

echo "Starting exploits --2d (will interrupt after 3 seconds)..."
php bin/solarwinds exploits --2d &
PID=$!

sleep 3

echo "Sending SIGINT..."
kill -INT $PID

# Wait for process to finish
wait $PID
EXIT_CODE=$?

echo ""
echo "Process exited with code: $EXIT_CODE"

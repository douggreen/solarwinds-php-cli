#!/bin/bash
# Test interrupt handling with longer sync
# Run the exploits command with --30d and send SIGINT after 8 seconds

php bin/solarwinds exploits --30d &
PID=$!

echo "Started process $PID with --30d, will interrupt in 8 seconds..."
sleep 8

echo "Sending SIGINT..."
kill -INT $PID

# Wait for process to finish
wait $PID
EXIT_CODE=$?

echo ""
echo "Process exited with code: $EXIT_CODE"

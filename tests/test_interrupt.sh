#!/bin/bash
# Test interrupt handling
# Run the exploits command and send SIGINT after 5 seconds

php bin/solarwinds exploits --2d &
PID=$!

echo "Started process $PID, will interrupt in 5 seconds..."
sleep 5

echo "Sending SIGINT..."
kill -INT $PID

# Wait for process to finish
wait $PID
EXIT_CODE=$?

echo ""
echo "Process exited with code: $EXIT_CODE"

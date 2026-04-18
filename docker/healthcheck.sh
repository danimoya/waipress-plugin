#!/bin/bash
# Check both HeliosDB-Nano and WordPress are running
curl -sf http://127.0.0.1:8080/health > /dev/null 2>&1 && \
curl -sf -o /dev/null -w '%{http_code}' http://127.0.0.1/ | grep -qE '^(200|301|302)$'

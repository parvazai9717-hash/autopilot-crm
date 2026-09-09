#!/bin/sh
set -e

BACKEND_URL="${BACKEND_URL:-https://lockout-gate-autopilot-api.qt5ga9.easypanel.host}"
BACKEND_URL="${BACKEND_URL%/}"

BACKEND_HOST=$(echo "$BACKEND_URL" | sed -e 's|^[^/]*//||' -e 's|/.*$||' -e 's|:.*$||')

echo "Starting Nginx with BACKEND_URL=${BACKEND_URL} and BACKEND_HOST=${BACKEND_HOST}"

sed -e "s|__BACKEND_URL__|$BACKEND_URL|g" \
    -e "s|__BACKEND_HOST__|$BACKEND_HOST|g" \
    /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf

exec nginx -g "daemon off;"

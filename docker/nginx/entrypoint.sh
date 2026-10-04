#!/bin/sh
set -e

# Render the domain into the nginx config.
: "${SSL_DOMAIN:?SSL_DOMAIN must be set (bare domain, e.g. example.com)}"
# envsubst/gettext is not in the base image; install once at boot.
apk add --no-cache gettext >/dev/null 2>&1 || true
export SSL_DOMAIN
envsubst '${SSL_DOMAIN}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf
nginx -t

# First boot has no Let's Encrypt cert yet; nginx refuses to start
# without one. Mint a short-lived self-signed cert so the stack
# boots and certbot can complete the HTTP-01 challenge. Replace
# with the real cert on first issuance (see DEPLOYMENT.md).
CERT_DIR="/etc/letsencrypt/live/${SSL_DOMAIN}"
if [ ! -f "${CERT_DIR}/fullchain.pem" ]; then
    echo "No certificate for ${SSL_DOMAIN}; minting temporary self-signed cert."
    apk add --no-cache openssl >/dev/null 2>&1 || true
    mkdir -p "${CERT_DIR}"
    openssl req -x509 -nodes -days 7 -newkey rsa:2048 \
        -keyout "${CERT_DIR}/privkey.pem" \
        -out "${CERT_DIR}/fullchain.pem" \
        -subj "/CN=${SSL_DOMAIN}" 2>/dev/null
fi

exec nginx -g 'daemon off;'

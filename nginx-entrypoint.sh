#!/bin/sh
set -e

CERT_DIR=/etc/nginx/certs
mkdir -p "$CERT_DIR"

if [ ! -f "$CERT_DIR/server.crt" ]; then
  apk add --no-cache openssl >/dev/null 2>&1

  # Pick first non-loopback IPv4 address
  IP=$(ip -4 addr show scope global | awk '/inet/{print $2}' | head -1 | cut -d'/' -f1)
  [ -z "$IP" ] && IP=127.0.0.1

  openssl req -x509 -newkey rsa:2048 -days 3650 -nodes \
    -keyout "$CERT_DIR/server.key" \
    -out    "$CERT_DIR/server.crt" \
    -subj   "/CN=dnd-atmo" \
    -addext "subjectAltName=IP:${IP},IP:127.0.0.1"

  echo "Generated TLS cert for IP: $IP"
fi

exec nginx -g 'daemon off;'

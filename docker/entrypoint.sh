#!/bin/sh

set -eu

mkdir -p /app/var/log /var/lib/php/sessions
chown -R www-data:www-data /app/var/log /var/lib/php/sessions

exec "$@"

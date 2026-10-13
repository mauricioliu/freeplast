#!/bin/sh
set -eu
# Refuse an incomplete checkout before Apache or the stock initializer runs.
php /opt/freeplast/verify-files.php
cp /opt/freeplast/apache.conf /etc/apache2/conf-enabled/freeplast-local.conf
a2enmod headers >/dev/null
mkdir -p /var/freeplast/mail
chmod 700 /var/freeplast /var/freeplast/mail
chown 33:33 /var/freeplast /var/freeplast/mail
exec /usr/local/bin/docker-entrypoint.sh "$@"

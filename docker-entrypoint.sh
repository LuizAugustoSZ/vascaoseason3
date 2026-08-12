#!/bin/sh
set -eu

# Railway can inject Apache startup configuration. PHP must run with prefork,
# so remove incompatible MPM links immediately before Apache starts.
rm -f /etc/apache2/mods-enabled/mpm_event.load \
      /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load \
      /etc/apache2/mods-enabled/mpm_worker.conf

exec docker-php-entrypoint "$@"

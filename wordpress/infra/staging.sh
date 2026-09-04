# Freeplast staging — single source of the infrastructure constants (issue #16).
#
# The approved hostname, the install root and the loopback origin port are
# declared HERE and nowhere else in infra/: every shell script sources this
# file, deploy.sh renders the Nginx vhost template
# (nginx/staging.conf.tmpl) and writes the stack .env from these values,
# and Compose reads the port back from that .env — so a staging host or
# port change is a one-place edit. DEPLOYMENT.md records the deployed
# values for the operator; .env.example declares the variable names only.
#
# This file is sourced, never executed: it must print nothing and stay
# loadable under `set -euo pipefail` in every script.

SITE_HOSTNAME='freeplast.mliu.site'   # approved staging hostname (DNS + TLS SAN)
STACK_DIR='/opt/freeplast-wordpress'  # install root on the OpenClaw host
LOOPBACK_PORT='8092'                  # loopback-only origin port the host Nginx proxies

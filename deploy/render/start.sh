#!/bin/sh
set -eu
# No installer, migration, or data reset runs on restart/redeploy.
exec apache2-foreground

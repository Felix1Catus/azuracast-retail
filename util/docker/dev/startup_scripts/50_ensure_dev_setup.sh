#!/bin/bash

# Set up permissions
cd /var/acacia/www

chown -R acacia:acacia .
chmod 777 ./vendor/ ./node_modules/ ./web/static/

# If the Composer directory is empty, run composer install at this point.
if [ $(find ./vendor/ -maxdepth 1 -printf x | wc -c) -lt 10 ]; then
  gosu acacia composer install
fi

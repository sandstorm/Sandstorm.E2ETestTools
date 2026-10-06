#!/bin/bash
set -ex

# database for the package's functional tests (FLOW_CONTEXT=Testing, see neos-root/app/Configuration/Testing)
mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" --execute="CREATE DATABASE IF NOT EXISTS flow_functional_testing;"
mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" --execute="GRANT ALL PRIVILEGES ON flow_functional_testing.* TO '$MARIADB_USER'@'%';"

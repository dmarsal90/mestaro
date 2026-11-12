#!/bin/sh
# Dev only: creates each service's central database and role. Tenant databases/roles
# are created at runtime by each service when a restaurant is provisioned.
set -eu
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" <<SQL
CREATE ROLE platform LOGIN PASSWORD '${PLATFORM_DB_PASSWORD}';
CREATE DATABASE mestaro_platform OWNER platform;
CREATE DATABASE mestaro_platform_test OWNER platform;
-- Services create tenant databases at runtime: they need CREATEDB/CREATEROLE.
ALTER ROLE platform CREATEDB CREATEROLE;

CREATE ROLE catalog LOGIN PASSWORD '${CATALOG_DB_PASSWORD}';
CREATE DATABASE mestaro_catalog OWNER catalog;
CREATE DATABASE mestaro_catalog_test OWNER catalog;
ALTER ROLE catalog CREATEDB CREATEROLE;
SQL

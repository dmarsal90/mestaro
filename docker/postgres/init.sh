#!/bin/sh
# Dev only: creates the central platform database and its role. Tenant databases/roles
# are created at runtime by each service when a restaurant is provisioned.
set -eu
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" <<SQL
CREATE ROLE platform LOGIN PASSWORD '${PLATFORM_DB_PASSWORD}';
CREATE DATABASE mestaro_platform OWNER platform;
CREATE DATABASE mestaro_platform_test OWNER platform;
-- Services create tenant databases at runtime: they need CREATEDB/CREATEROLE.
ALTER ROLE platform CREATEDB CREATEROLE;
SQL

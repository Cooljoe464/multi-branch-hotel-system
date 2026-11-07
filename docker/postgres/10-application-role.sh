#!/bin/sh
set -eu

if [ "$DB_USERNAME" = "$POSTGRES_USER" ]; then
    echo 'DB_USERNAME must be distinct from the PostgreSQL migration owner.' >&2
    exit 1
fi

psql \
    --set=ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --set=runtime_username="$DB_USERNAME" \
    --set=runtime_password="$DB_PASSWORD" \
    --set=migration_username="$POSTGRES_USER" <<'SQL'
SELECT format(
    'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS',
    :'runtime_username',
    :'runtime_password'
) \gexec

SELECT format(
    'GRANT CONNECT ON DATABASE %I TO %I',
    current_database(),
    :'runtime_username'
) \gexec

GRANT USAGE ON SCHEMA public TO :"runtime_username";

SELECT format(
    'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %I',
    :'runtime_username'
) \gexec

SELECT format(
    'GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO %I',
    :'runtime_username'
) \gexec

SELECT format(
    'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I',
    :'migration_username',
    :'runtime_username'
) \gexec

SELECT format(
    'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO %I',
    :'migration_username',
    :'runtime_username'
) \gexec
SQL

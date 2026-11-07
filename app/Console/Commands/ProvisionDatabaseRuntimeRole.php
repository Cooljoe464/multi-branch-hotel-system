<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('db:provision-runtime-role')]
#[Description('Provision a least-privilege PostgreSQL role for application traffic.')]
class ProvisionDatabaseRuntimeRole extends Command
{
    public function handle(): int
    {
        $connection = DB::connection('migration');

        if ($connection->getDriverName() !== 'pgsql') {
            $this->error('The runtime-role provisioner requires PostgreSQL.');

            return self::FAILURE;
        }

        $runtimeUsername = (string) config('database.connections.pgsql.username');
        $runtimePassword = (string) config('database.connections.pgsql.password');

        if (! $this->isValidIdentifier($runtimeUsername) || $runtimePassword === '') {
            $this->error('DB_USERNAME must be a valid PostgreSQL role name and DB_PASSWORD must be set.');

            return self::FAILURE;
        }

        $ownerRow = $connection->selectOne('select current_user as name');
        $migrationUsername = is_object($ownerRow) && is_string($ownerRow->name ?? null)
            ? $ownerRow->name
            : '';

        if (! $this->isValidIdentifier($migrationUsername) || $runtimeUsername === $migrationUsername) {
            $this->error('The application role must be distinct from the migration owner.');

            return self::FAILURE;
        }

        $runtimeRole = $this->quoteIdentifier($runtimeUsername);
        $migrationRole = $this->quoteIdentifier($migrationUsername);
        $runtimePasswordLiteral = $connection->getPdo()->quote($runtimePassword);

        if (! is_string($runtimePasswordLiteral)) {
            $this->error('The runtime database password could not be safely quoted.');

            return self::FAILURE;
        }

        $roleExists = $connection->selectOne(
            'select 1 as found from pg_roles where rolname = ?',
            [$runtimeUsername],
        ) !== null;

        if ($roleExists) {
            $this->reassignOwnedObjects($connection, $runtimeUsername, $runtimeRole, $migrationRole);
            $this->revokeMemberships($connection, $runtimeUsername, $runtimeRole);
            $connection->statement("ALTER ROLE {$runtimeRole} WITH LOGIN PASSWORD {$runtimePasswordLiteral} NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS");
        } else {
            $connection->statement("CREATE ROLE {$runtimeRole} WITH LOGIN PASSWORD {$runtimePasswordLiteral} NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS");
        }

        $databaseName = (string) config('database.connections.migration.database');
        if (! $this->isValidIdentifier($databaseName)) {
            $this->error('The configured PostgreSQL database name is invalid.');

            return self::FAILURE;
        }

        $databaseIdentifier = $this->quoteIdentifier($databaseName);
        $connection->statement("GRANT CONNECT ON DATABASE {$databaseIdentifier} TO {$runtimeRole}");
        $connection->statement("GRANT USAGE ON SCHEMA public TO {$runtimeRole}");
        $connection->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$runtimeRole}");
        $connection->statement("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO {$runtimeRole}");
        $connection->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$migrationRole} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$runtimeRole}");
        $connection->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$migrationRole} IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO {$runtimeRole}");

        $this->ensureRuntimeRoleIsNotOwner($connection, $runtimeUsername);

        $this->info("PostgreSQL runtime role [{$runtimeUsername}] is provisioned without superuser or BYPASSRLS privileges.");

        return self::SUCCESS;
    }

    private function isValidIdentifier(string $identifier): bool
    {
        return strlen($identifier) <= 63
            && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $identifier) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.$identifier.'"';
    }

    private function revokeMemberships(Connection $connection, string $runtimeUsername, string $runtimeRole): void
    {
        $memberships = $connection->select(
            'select granted.rolname from pg_auth_members membership join pg_roles granted on granted.oid = membership.roleid join pg_roles member on member.oid = membership.member where member.rolname = ?',
            [$runtimeUsername],
        );

        foreach ($memberships as $membership) {
            if (! is_string($membership->rolname ?? null) || ! $this->isValidIdentifier($membership->rolname)) {
                throw new RuntimeException('A runtime role membership could not be safely revoked.');
            }

            $connection->statement('REVOKE '.$this->quoteIdentifier($membership->rolname).' FROM '.$runtimeRole);
        }
    }

    private function reassignOwnedObjects(
        Connection $connection,
        string $runtimeUsername,
        string $runtimeRole,
        string $migrationRole,
    ): void {
        $ownsTables = $connection->selectOne(
            'select 1 as found from pg_class where relowner = (select oid from pg_roles where rolname = ?) limit 1',
            [$runtimeUsername],
        ) !== null;

        if ($ownsTables) {
            $connection->statement("REASSIGN OWNED BY {$runtimeRole} TO {$migrationRole}");
        }

        $publicSchemaOwner = $connection->selectOne(
            "select pg_get_userbyid(nspowner) as owner from pg_namespace where nspname = 'public'",
        );

        if (is_object($publicSchemaOwner) && ($publicSchemaOwner->owner ?? null) === $runtimeUsername) {
            $migrationOwner = $connection->selectOne('select current_user as name');
            $migrationUsername = is_object($migrationOwner) && is_string($migrationOwner->name ?? null)
                ? $migrationOwner->name
                : '';

            if (! $this->isValidIdentifier($migrationUsername)) {
                throw new RuntimeException('The PostgreSQL migration owner could not be verified.');
            }

            $connection->statement('ALTER SCHEMA public OWNER TO '.$this->quoteIdentifier($migrationUsername));
        }

        $databaseOwner = $connection->selectOne(
            'select pg_get_userbyid(datdba) as owner from pg_database where datname = current_database()',
        );

        if (is_object($databaseOwner) && ($databaseOwner->owner ?? null) === $runtimeUsername) {
            $migrationOwner = $connection->selectOne('select current_user as name');
            $migrationUsername = is_object($migrationOwner) && is_string($migrationOwner->name ?? null)
                ? $migrationOwner->name
                : '';

            if (! $this->isValidIdentifier($migrationUsername)) {
                throw new RuntimeException('The PostgreSQL migration owner could not be verified.');
            }

            $connection->statement('ALTER DATABASE '.$this->quoteIdentifier((string) config('database.connections.migration.database')).' OWNER TO '.$this->quoteIdentifier($migrationUsername));
        }
    }

    private function ensureRuntimeRoleIsNotOwner(Connection $connection, string $runtimeUsername): void
    {
        $ownedTable = $connection->selectOne(
            "select 1 as found from pg_class where relowner = (select oid from pg_roles where rolname = ?) and relkind in ('r', 'p', 'v', 'm', 'S', 'f') limit 1",
            [$runtimeUsername],
        );

        $role = $connection->selectOne(
            'select rolsuper, rolbypassrls, rolcreaterole, rolcreatedb from pg_roles where rolname = ?',
            [$runtimeUsername],
        );

        if ($ownedTable !== null
            || ! is_object($role)
            || $this->databaseBoolean($role->rolsuper ?? false)
            || $this->databaseBoolean($role->rolbypassrls ?? false)
            || $this->databaseBoolean($role->rolcreaterole ?? false)
            || $this->databaseBoolean($role->rolcreatedb ?? false)
        ) {
            throw new RuntimeException('PostgreSQL runtime role did not pass the least-privilege verification.');
        }
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}

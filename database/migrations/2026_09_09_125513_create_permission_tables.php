<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $teams = (bool) config('permission.teams');
        /** @var array<string, string> $tableNames */
        $tableNames = (array) config('permission.table_names');
        /** @var array<string, string> $columnNames */
        $columnNames = (array) config('permission.column_names');
        $pivotRole = (string) ($columnNames['role_pivot_key'] ?? 'role_id');
        $pivotPermission = (string) ($columnNames['permission_pivot_key'] ?? 'permission_id');

        throw_if(empty($tableNames), 'Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        throw_if($teams && empty($columnNames['team_foreign_key'] ?? null), 'Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');

        $permissionsTable = (string) ($tableNames['permissions'] ?? 'permissions');
        $rolesTable = (string) ($tableNames['roles'] ?? 'roles');
        $modelHasPermissionsTable = (string) ($tableNames['model_has_permissions'] ?? 'model_has_permissions');
        $modelHasRolesTable = (string) ($tableNames['model_has_roles'] ?? 'model_has_roles');
        $roleHasPermissionsTable = (string) ($tableNames['role_has_permissions'] ?? 'role_has_permissions');

        $teamForeignKey = (string) ($columnNames['team_foreign_key'] ?? 'team_foreign_key');
        $modelMorphKey = (string) ($columnNames['model_morph_key'] ?? 'model_id');

        /**
         * See `docs/prerequisites.md` for suggested lengths on 'name' and 'guard_name' if "1071 Specified key was too long" errors are encountered.
         */
        Schema::create($permissionsTable, static function (Blueprint $table) {
            $table->id(); // permission id
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        /**
         * See `docs/prerequisites.md` for suggested lengths on 'name' and 'guard_name' if "1071 Specified key was too long" errors are encountered.
         */
        Schema::create($rolesTable, static function (Blueprint $table) use ($teams, $teamForeignKey) {
            $table->id(); // role id
            if ($teams || config('permission.testing')) { // permission.testing is a fix for sqlite testing
                $table->unsignedBigInteger($teamForeignKey)->nullable();
                $table->index($teamForeignKey, 'roles_team_foreign_key_index');
            }
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            if ($teams || config('permission.testing')) {
                $table->unique([$teamForeignKey, 'name', 'guard_name']);
            } else {
                $table->unique(['name', 'guard_name']);
            }
        });

        Schema::create($modelHasPermissionsTable, static function (Blueprint $table) use ($permissionsTable, $modelMorphKey, $pivotPermission, $teams, $teamForeignKey) {
            $table->unsignedBigInteger($pivotPermission);

            $table->string('model_type');
            $table->unsignedBigInteger($modelMorphKey);
            $table->index([$modelMorphKey, 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                ->references('id') // permission id
                ->on($permissionsTable)
                ->cascadeOnDelete();
            if ($teams) {
                $table->unsignedBigInteger($teamForeignKey);
                $table->index($teamForeignKey, 'model_has_permissions_team_foreign_key_index');

                $table->primary([$teamForeignKey, $pivotPermission, $modelMorphKey, 'model_type'],
                    'model_has_permissions_permission_model_type_primary');
            } else {
                $table->primary([$pivotPermission, $modelMorphKey, 'model_type'],
                    'model_has_permissions_permission_model_type_primary');
            }
        });

        Schema::create($modelHasRolesTable, static function (Blueprint $table) use ($rolesTable, $modelMorphKey, $pivotRole, $teams, $teamForeignKey) {
            $table->unsignedBigInteger($pivotRole);

            $table->string('model_type');
            $table->unsignedBigInteger($modelMorphKey);
            $table->index([$modelMorphKey, 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)
                ->references('id') // role id
                ->on($rolesTable)
                ->cascadeOnDelete();
            if ($teams) {
                $table->unsignedBigInteger($teamForeignKey);
                $table->index($teamForeignKey, 'model_has_roles_team_foreign_key_index');

                $table->primary([$teamForeignKey, $pivotRole, $modelMorphKey, 'model_type'],
                    'model_has_roles_role_model_type_primary');
            } else {
                $table->primary([$pivotRole, $modelMorphKey, 'model_type'],
                    'model_has_roles_role_model_type_primary');
            }
        });

        Schema::create($roleHasPermissionsTable, static function (Blueprint $table) use ($pivotRole, $pivotPermission, $permissionsTable, $rolesTable) {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);

            $table->foreign($pivotPermission)
                ->references('id') // permission id
                ->on($permissionsTable)
                ->cascadeOnDelete();

            $table->foreign($pivotRole)
                ->references('id') // role id
                ->on($rolesTable)
                ->cascadeOnDelete();

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        $cacheStore = config('permission.cache.store');
        $cacheKey = config('permission.cache.key');

        app('cache')
            ->store(is_string($cacheStore) && $cacheStore != 'default' ? $cacheStore : null)
            ->forget(is_string($cacheKey) ? $cacheKey : 'permission');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /** @var array<string, string> $tableNames */
        $tableNames = (array) config('permission.table_names');

        throw_if(empty($tableNames), 'Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');

        Schema::dropIfExists((string) ($tableNames['role_has_permissions'] ?? 'role_has_permissions'));
        Schema::dropIfExists((string) ($tableNames['model_has_roles'] ?? 'model_has_roles'));
        Schema::dropIfExists((string) ($tableNames['model_has_permissions'] ?? 'model_has_permissions'));
        Schema::dropIfExists((string) ($tableNames['roles'] ?? 'roles'));
        Schema::dropIfExists((string) ($tableNames['permissions'] ?? 'permissions'));
    }
};

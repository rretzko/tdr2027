<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofits the already-migrated permission tables (created back when
 * config('permission.teams') was false) to the team-scoped shape that
 * 2026_06_11_192745_create_permission_tables.php now produces on a fresh
 * install. Every block is guarded with Schema::hasColumn so this is a safe
 * no-op wherever that migration already created the columns fresh (test DBs,
 * new dev clones, CI).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamForeignKey = $columnNames['team_foreign_key'];
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $modelMorphKey = $columnNames['model_morph_key'];

        if (! Schema::hasColumn($tableNames['roles'], $teamForeignKey)) {
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamForeignKey) {
                $table->unsignedBigInteger($teamForeignKey)->nullable()->after('id');
                $table->index($teamForeignKey, 'roles_team_foreign_key_index');
            });

            Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamForeignKey) {
                $table->dropUnique(['name', 'guard_name']);
                $table->unique([$teamForeignKey, 'name', 'guard_name']);
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_permissions'], $teamForeignKey)) {
            // The primary key being dropped is also the only index backing
            // the permission_id foreign key — add a plain index first so
            // MySQL doesn't refuse to drop it out from under the FK.
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($pivotPermission) {
                $table->index($pivotPermission, 'model_has_permissions_permission_id_index');
            });

            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) {
                $table->dropPrimary('model_has_permissions_permission_model_type_primary');
            });

            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($teamForeignKey, $pivotPermission, $modelMorphKey) {
                $table->unsignedBigInteger($teamForeignKey)->nullable()->after($modelMorphKey);
                $table->index($teamForeignKey, 'model_has_permissions_team_foreign_key_index');
                $table->foreign($teamForeignKey)->references('id')->on('versions')->cascadeOnDelete();

                $table->id();
                $table->unique([$teamForeignKey, $pivotPermission, $modelMorphKey, 'model_type'],
                    'model_has_permissions_permission_model_type_unique');
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_roles'], $teamForeignKey)) {
            // Same reasoning as model_has_permissions above: the primary key
            // being dropped also backs the role_id foreign key.
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($pivotRole) {
                $table->index($pivotRole, 'model_has_roles_role_id_index');
            });

            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) {
                $table->dropPrimary('model_has_roles_role_model_type_primary');
            });

            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($teamForeignKey, $pivotRole, $modelMorphKey) {
                $table->unsignedBigInteger($teamForeignKey)->nullable()->after($modelMorphKey);
                $table->index($teamForeignKey, 'model_has_roles_team_foreign_key_index');
                $table->foreign($teamForeignKey)->references('id')->on('versions')->cascadeOnDelete();

                $table->id();
                $table->unique([$teamForeignKey, $pivotRole, $modelMorphKey, 'model_type'],
                    'model_has_roles_role_model_type_unique');
            });
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamForeignKey = $columnNames['team_foreign_key'];

        if (Schema::hasColumn($tableNames['model_has_roles'], $teamForeignKey)) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($teamForeignKey, $columnNames) {
                $table->dropForeign([$teamForeignKey]);
                $table->dropUnique('model_has_roles_role_model_type_unique');
                $table->dropColumn('id');
                $table->dropColumn($teamForeignKey);
                $table->primary([$columnNames['role_pivot_key'] ?? 'role_id', $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary');
            });
        }

        if (Schema::hasColumn($tableNames['model_has_permissions'], $teamForeignKey)) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($teamForeignKey, $columnNames) {
                $table->dropForeign([$teamForeignKey]);
                $table->dropUnique('model_has_permissions_permission_model_type_unique');
                $table->dropColumn('id');
                $table->dropColumn($teamForeignKey);
                $table->primary([$columnNames['permission_pivot_key'] ?? 'permission_id', $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary');
            });
        }

        if (Schema::hasColumn($tableNames['roles'], $teamForeignKey)) {
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamForeignKey) {
                $table->dropUnique([$teamForeignKey, 'name', 'guard_name']);
                $table->unique(['name', 'guard_name']);
                $table->dropColumn($teamForeignKey);
            });
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofits the already-migrated permission tables (created back when
 * config('permission.teams') was false) to the team-scoped shape that
 * 2026_06_11_192745_create_permission_tables.php now produces on a fresh
 * install. The column/PK-shape blocks are guarded with Schema::hasColumn so
 * they're a safe no-op wherever that migration already created the columns
 * fresh (test DBs, new dev clones, CI).
 *
 * The version_id → versions.id foreign keys are handled separately (not
 * guarded by hasColumn) and always run: the stock migration runs before
 * the versions table exists, so it can never add these FKs itself — on a
 * fresh install the columns exist already but the FKs don't yet, so this
 * migration is what adds them, every time, once versions is guaranteed to
 * exist. Schema::getForeignKeys() makes that idempotent.
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

                $table->id();
                $table->unique([$teamForeignKey, $pivotRole, $modelMorphKey, 'model_type'],
                    'model_has_roles_role_model_type_unique');
            });
        }

        $this->ensureForeignKey($tableNames['model_has_permissions'], $teamForeignKey);
        $this->ensureForeignKey($tableNames['model_has_roles'], $teamForeignKey);

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
            $this->dropForeignKeyIfExists($tableNames['model_has_roles'], $teamForeignKey);

            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($teamForeignKey, $columnNames) {
                $table->dropUnique('model_has_roles_role_model_type_unique');
                $table->dropColumn('id');
                $table->dropColumn($teamForeignKey);
                $table->primary([$columnNames['role_pivot_key'] ?? 'role_id', $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary');
            });
        }

        if (Schema::hasColumn($tableNames['model_has_permissions'], $teamForeignKey)) {
            $this->dropForeignKeyIfExists($tableNames['model_has_permissions'], $teamForeignKey);

            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($teamForeignKey, $columnNames) {
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

    private function ensureForeignKey(string $table, string $column): void
    {
        $alreadyExists = collect(Schema::getForeignKeys($table))
            ->contains(fn (array $fk): bool => $fk['columns'] === [$column]);

        if ($alreadyExists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->foreign($column)->references('id')->on('versions')->cascadeOnDelete();
        });
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $existing = collect(Schema::getForeignKeys($table))
            ->first(fn (array $fk): bool => $fk['columns'] === [$column]);

        if ($existing === null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing) {
            $blueprint->dropForeign($existing['name']);
        });
    }
};

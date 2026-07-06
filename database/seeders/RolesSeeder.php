<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds shared role *definitions* only. Version-scoping happens at
 * *assignment* time — see App\Services\VersionRoleService — so every role
 * here is created once as a version_id = null row, shared across all
 * Versions, whether it's actually assigned globally (Teacher, Student,
 * Judge, Teacher_Supervisor, Founder/Admin) or per-Version (Event Manager,
 * Registration Manager, Co-Registration Manager, Web Registration Manager,
 * Tab Room Manager, Rehearsal Manager).
 */
class RolesSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const ROLES = [
        'Founder/Admin',
        'Event Manager',
        'Registration Manager',
        'Co-Registration Manager',
        'Web Registration Manager',
        'Tab Room Manager',
        'Rehearsal Manager',
        'Teacher',
        'Student',
        'Judge',
        'Teacher_Supervisor',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}

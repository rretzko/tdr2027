<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            GeostateSeeder::class,
            CountySeeder::class,
            PronounSeeder::class,
            VoicePartSeeder::class,
            InstrumentSeeder::class,
            RolesSeeder::class,
            SchoolSeeder::class,
            OrganizationSeeder::class,
            EventSeeder::class,
            EnsembleSeeder::class,
            EnsembleGradeSeeder::class,
            EnsembleVoicePartSeeder::class,
            VersionSeeder::class,
            VersionDateSeeder::class,
            VersionCountySeeder::class,
            VersionEnsembleOrderSeeder::class,
            VersionFeeSeeder::class,
            VersionMembershipRequirementSeeder::class,
            UserSeeder::class,
            TeacherSeeder::class,
            SchoolTeacherSeeder::class,
            StudentSeeder::class,
            SchoolStudentSeeder::class,
            StudentTeacherSeeder::class,
            VersionRoleSeeder::class,
            VersionInvitationSeeder::class,
            VersionPitchFileSeeder::class,
        ]);

        User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ]);
    }
}

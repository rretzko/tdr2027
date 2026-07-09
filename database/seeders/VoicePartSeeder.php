<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\VoicePart;
use Illuminate\Database\Seeder;

class VoicePartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seeds = [
            ['Descant', 'Des'],
            ['Soprano', 'Sop'],
            ['Soprano I', 'SI'],
            ['Soprano II', 'SII'],
            ['Alto', 'Alt'],
            ['Alto I', 'AI'],
            ['Alto II', 'AII'],
            ['Tenor', 'Ten'],
            ['Tenor I', 'TI'],
            ['Tenor II', 'TII'],
            ['Baritone', 'Bar'],
            ['High Baritone', 'HB'],
            ['Low Baritone', 'LB'],
            ['Bass Baritone', 'BB'],
            ['Bass', 'Bass'],
            ['Bass I', 'BI'],
            ['Bass II', 'BII'],
            ['ALL', 'ALL'],
        ];

        foreach ($seeds as $sortOrder => [$name, $abbr]) {
            VoicePart::create([
                'name' => $name,
                'abbr' => $abbr,
                'sort_order' => $sortOrder + 1,
            ]);
        }
    }
}

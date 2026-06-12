<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\County;
use Illuminate\Database\Seeder;

class CountySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seeds = [
            [1, 37, 'Atlantic'],
            [2, 37, 'Bergen'],
            [3, 37, 'Burlington'],
            [4, 37, 'Camden'],
            [5, 37, 'Cape May'],
            [6, 37, 'Cumberland'],
            [7, 37, 'Essex'],
            [8, 37, 'Gloucester'],
            [9, 37, 'Hudson'],
            [10, 37, 'Hunterdon'],
            [11, 37, 'Mercer'],
            [12, 37, 'Middlesex'],
            [13, 37, 'Morris'],
            [14, 37, 'Ocean'],
            [15, 37, 'Passaic'],
            [16, 37, 'Salem'],
            [17, 37, 'Somerset'],
            [18, 37, 'Sussex'],
            [19, 37, 'Union'],
            [20, 37, 'Warren'],
            [21, 37, 'Monmouth'],
            [22, 37, 'Unknown'],
        ];

        foreach ($seeds as [$id, $geostateId, $name]) {
            County::create([
                'id' => $id,
                'geostate_id' => $geostateId,
                'name' => $name,
            ]);
        }
    }
}

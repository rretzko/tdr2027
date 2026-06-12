<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Instrument;
use Illuminate\Database\Seeder;

class InstrumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seeds = [
            ['Flute', 'Fl', 'Woodwind', true, true],
            ['Piccolo', 'Picc', 'Woodwind', true, true],
            ['Oboe', 'Ob', 'Woodwind', true, true],
            ['English Horn', 'EH', 'Woodwind', false, true],
            ['Clarinet', 'Cl', 'Woodwind', true, true],
            ['Bass Clarinet', 'BCl', 'Woodwind', true, false],
            ['Bassoon', 'Bsn', 'Woodwind', true, true],
            ['Alto Saxophone', 'ASax', 'Woodwind', true, false],
            ['Tenor Saxophone', 'TSax', 'Woodwind', true, false],
            ['Baritone Saxophone', 'BSax', 'Woodwind', true, false],
            ['Trumpet', 'Tpt', 'Brass', true, true],
            ['Cornet', 'Cor', 'Brass', true, false],
            ['French Horn', 'Hn', 'Brass', true, true],
            ['Trombone', 'Tbn', 'Brass', true, true],
            ['Bass Trombone', 'BTbn', 'Brass', true, true],
            ['Euphonium', 'Euph', 'Brass', true, false],
            ['Tuba', 'Tba', 'Brass', true, true],
            ['Violin', 'Vln', 'String', false, true],
            ['Viola', 'Vla', 'String', false, true],
            ['Cello', 'Vc', 'String', false, true],
            ['Double Bass', 'Db', 'String', false, true],
            ['Harp', 'Hp', 'String', false, true],
            ['Snare Drum', 'SD', 'Percussion', true, true],
            ['Bass Drum', 'BD', 'Percussion', true, true],
            ['Timpani', 'Timp', 'Percussion', false, true],
            ['Xylophone', 'Xyl', 'Percussion', true, true],
            ['Mallet Percussion', 'Mal', 'Percussion', true, true],
            ['Piano', 'Pno', 'Keyboard', false, false],
        ];

        foreach ($seeds as $sortOrder => [$name, $abbr, $family, $inBand, $inOrchestra]) {
            Instrument::create([
                'name' => $name,
                'abbr' => $abbr,
                'family' => $family,
                'in_band' => $inBand,
                'in_orchestra' => $inOrchestra,
                'sort_order' => $sortOrder + 1,
            ]);
        }
    }
}

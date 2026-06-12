<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pronoun;
use Illuminate\Database\Seeder;

class PronounSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seeds = [
            ['she/her/hers/herself', 'herself', 'she', 'her', 'her'],
            ['he/him/his/himself', 'himself', 'he', 'his', 'him'],
            ['(f)ae/(f)aer/(f)aers/(f)aerself', '(f)aerself', '(f)ae', '(f)aers', '(f)aers'],
            ['e/ey/em/eir/eirs/eirself', 'eirself', 'e', 'eirs', 'eirs'],
            ['per/pers/perself', 'perself', 'per', 'pers', 'pers'],
            ['they/them/their/theirs/themself', 'themself', 'they', 'theirs', 'theirs'],
            ['ve/ver/vis/verself', 'verself', 've', 'vis', 'vis'],
            ['xe/xem/xyr/xyrs/xemself', 'xemself', 'xem', 'xyrs', 'xyrs'],
            ['ze,zie,hir/hirs/hirself', 'hirself', 'ze', 'hirs', 'hirs'],
        ];

        foreach ($seeds as $sortOrder => [$description, $intensive, $personal, $possessive, $object]) {
            Pronoun::create([
                'description' => $description,
                'intensive' => $intensive,
                'personal' => $personal,
                'possessive' => $possessive,
                'object' => $object,
                'sort_order' => $sortOrder + 1,
            ]);
        }
    }
}

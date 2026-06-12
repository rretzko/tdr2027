<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\SchoolTeacher;
use Carbon\Carbon;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'city', 'zip_code', 'geostate_id', 'county_id', 'school_year'])]
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    public function county(): BelongsTo
    {
        return $this->belongsTo(County::class);
    }

    public function geostate(): BelongsTo
    {
        return $this->belongsTo(Geostate::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(SchoolGrade::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'school_student')
            ->using(SchoolStudent::class)
            ->withPivot(['is_active', 'class_of'])
            ->withTimestamps();
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'school_teacher')
            ->using(SchoolTeacher::class)
            ->withPivot(['is_active', 'school_email', 'verified_at'])
            ->withTimestamps();
    }

    public function getSeniorYearAttribute(): int
    {
        if ($this->school_year === 'US') {
            $now = Carbon::now();

            return $now->month <= 6 ? $now->year : $now->year + 1;
        }

        return Carbon::now()->year; // TODO if non-US schools onboard
    }

    /**
     * @return array<int, int>
     */
    public function getGradesAttribute(): array
    {
        return $this->grades()->orderBy('grade')->pluck('grade')->all();
    }

    public function getShortNameAttribute(): string
    {
        $rhs = str_replace('Regional High School', 'RHS', $this->name);
        $rms = str_replace('Regional Middle School', 'RMS', $rhs);
        $shs = str_replace('Senior High School', 'Sr HS', $rms);
        $hs = str_replace('High School', 'HS', $shs);
        $ms = str_replace('Middle School', 'MS', $hs);
        $js1 = str_replace('Junior/Senior', 'J/S', $ms);
        $js2 = str_replace('Junior/senior', 'J/S', $js1);

        return str_replace('Elementary School', 'ES', $js2);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShirtSize;
use App\Models\Pivots\SchoolStudent;
use App\Models\Pivots\StudentTeacher;
use App\Support\ClassOfCalculator;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'height', 'birthday', 'shirt_size', 'instrument_id', 'voice_part_id', 'home_school_id'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'shirt_size' => ShirtSize::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<VoicePart, $this>
     */
    public function voicePart(): BelongsTo
    {
        return $this->belongsTo(VoicePart::class);
    }

    /**
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    /**
     * The student's actual school — recorded when a studio teacher adds this
     * student, since a studio's own school_student row represents the studio
     * itself, not where the student attends their regular chorus/band/orchestra
     * class. Used to flag event eligibility conflicts between a studio and the
     * student's school-based teacher for the same subject.
     *
     * @return BelongsTo<School, $this>
     */
    public function homeSchool(): BelongsTo
    {
        return $this->belongsTo(School::class, 'home_school_id');
    }

    /**
     * @return BelongsToMany<School, $this, SchoolStudent>
     */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_student')
            ->using(SchoolStudent::class)
            ->withPivot(['is_active', 'class_of'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Teacher, $this, StudentTeacher>
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'student_teacher')
            ->using(StudentTeacher::class)
            ->withPivot(['school_id', 'subject', 'role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasOne<HomeAddress, $this>
     */
    public function homeAddress(): HasOne
    {
        return $this->hasOne(HomeAddress::class);
    }

    /**
     * @return HasMany<EmergencyContact, $this>
     */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    /**
     * @return (School&object{pivot: SchoolStudent})|null
     */
    public function getCurrentSchoolAttribute(): ?School
    {
        return $this->schools()->wherePivot('is_active', true)->first();
    }

    public function getGradeAttribute(): ?int
    {
        $school = $this->getCurrentSchoolAttribute();

        if ($school === null) {
            return null;
        }

        return ClassOfCalculator::gradeFromClassOf((int) $school->pivot->class_of, $school->senior_year);
    }
}

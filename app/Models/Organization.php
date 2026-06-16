<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Pivots\TeacherSupervisor;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'abbr', 'parent_id', 'logo_file_url', 'logo_file_alt'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    /**
     * @return HasMany<TeacherSupervisor, $this>
     */
    public function teacherSupervisors(): HasMany
    {
        return $this->hasMany(TeacherSupervisor::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}

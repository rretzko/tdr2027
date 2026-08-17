<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoRegistrationManagerCounty;
use App\Models\School;
use App\Models\Version;
use App\Models\VersionMailToAddress;

/**
 * Resolves which manager's mailing address belongs on a given School's
 * Estimate Form Mail-To page — event-version-orientation.md §5.12. A county
 * claimed by a Co-Registration Manager (co_registration_manager_counties)
 * wins; otherwise the Version's single Registration Manager (cardinality
 * enforced by VersionRoleAssignmentService::assignRole()) is the fallback.
 * Returns null when no manager is resolvable, or a resolved manager simply
 * hasn't filled in their address yet — callers render a placeholder rather
 * than treating either case as an error.
 */
final class MailToAddressResolver
{
    public function __construct(
        private readonly VersionRoleAssignmentService $roles,
    ) {}

    public function resolve(Version $version, School $school): ?VersionMailToAddress
    {
        $responsibleUserId = $this->responsibleUserId($version, $school);

        if ($responsibleUserId === null) {
            return null;
        }

        return VersionMailToAddress::where('version_id', $version->id)
            ->where('user_id', $responsibleUserId)
            ->first();
    }

    private function responsibleUserId(Version $version, School $school): ?int
    {
        $coManagerUserId = CoRegistrationManagerCounty::where('version_id', $version->id)
            ->where('county_id', $school->county_id)
            ->value('user_id');

        if ($coManagerUserId !== null) {
            return (int) $coManagerUserId;
        }

        $registrationManager = $this->roles->assignmentsForVersion($version)->get('Registration Manager', collect())->first();

        return $registrationManager?->id;
    }
}

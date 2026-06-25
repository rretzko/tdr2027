<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trackable Routes
    |--------------------------------------------------------------------------
    |
    | Named routes eligible for "Fast Pass" tracking, mapped to the label
    | shown in the Fast Pass dropdown. Transient routes (modals, login,
    | settings actions, etc.) are intentionally left out so the dropdown
    | only ever surfaces meaningful destination pages.
    |
    */
    'trackable_routes' => [
        'dashboard' => 'Dashboard',
        'schools.index' => 'Schools',
        'organizations.index' => 'Organizations',
        'students.index' => 'Students',
        'events.index' => 'Events',
        'settings.profile' => 'Profile Settings',
        'settings.password' => 'Password Settings',
    ],

];

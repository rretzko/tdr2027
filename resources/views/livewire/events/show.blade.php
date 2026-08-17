<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.index') }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">Events</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>{{ $event->name }}</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div>
            <flux:heading size="xl">{{ $event->name }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ $event->organization->name }}</flux:text>
        </div>

        <flux:button id="tour-start" data-auto-start="{{ auth()->user()->dismissed_event_orientation_at === null ? '1' : '0' }}" size="sm" variant="ghost" icon="sparkles" type="button">Take a tour</flux:button>
    </div>

    {{-- Event summary badges --}}
    <div id="tour-event-badges" class="flex flex-wrap gap-2 mb-6">
        @php $rawStatus = $event->getRawOriginal('status'); @endphp
        @if ($rawStatus === 'active')
            <flux:badge color="green">Active</flux:badge>
        @elseif ($rawStatus === 'sandbox')
            <flux:badge color="amber">Sandbox</flux:badge>
        @elseif ($rawStatus === 'inactive')
            <flux:badge color="zinc">Inactive</flux:badge>
        @else
            <flux:badge color="red">Closed</flux:badge>
        @endif

        <flux:badge color="blue" class="capitalize">{{ $event->getRawOriginal('frequency') }}</flux:badge>
        <flux:badge color="zinc">{{ $event->audition_count }} {{ Str::plural('audition', $event->audition_count) }}</flux:badge>
        <flux:badge color="zinc">{{ $event->ensemble_count }} {{ Str::plural('ensemble', $event->ensemble_count) }}</flux:badge>
    </div>

    <flux:tab.group>
        <flux:tabs id="tour-event-tabs" wire:model="activeTab">
            <flux:tab id="tour-tab-versions" name="versions">Versions</flux:tab>
            <flux:tab id="tour-tab-ensembles" name="ensembles">Ensembles</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="versions">
            @if ($canManageEvent)
                <div id="tour-add-version" class="flex justify-end mb-4">
                    <flux:modal.trigger name="add-version">
                        <flux:button variant="primary" icon="plus" wire:click="openAddVersion">
                            Add Version
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            @endif

            {{-- Versions — cards below md:, table at md:+ --}}
            <div id="tour-version-list-mobile" class="md:hidden space-y-3">
                @forelse ($versions as $version)
                    <flux:card size="sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <flux:heading size="base" class="truncate">{{ $version->name }}</flux:heading>
                                <flux:text size="sm" class="text-zinc-500">Class of {{ $version->senior_class_of }}</flux:text>
                            </div>

                            <div id="{{ $loop->first ? 'tour-version-actions-mobile' : '' }}" class="flex flex-col items-end gap-2 shrink-0">
                                @php $vs = $version->getRawOriginal('status'); @endphp
                                @if ($vs === 'active')
                                    <flux:badge color="green" size="sm">Active</flux:badge>
                                @elseif ($vs === 'sandbox')
                                    <flux:badge color="amber" size="sm">Sandbox</flux:badge>
                                @elseif ($vs === 'inactive')
                                    <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                                @else
                                    <flux:badge color="red" size="sm">Closed</flux:badge>
                                @endif

                                @if ($canManageEvent)
                                    <flux:button size="sm" variant="filled" :href="route('events.versions.edit', $version)" wire:navigate>
                                        Configure
                                    </flux:button>
                                    <flux:button size="sm" variant="filled" class="!bg-blue-50 hover:!bg-blue-100 !text-blue-700 dark:!bg-blue-900/30 dark:hover:!bg-blue-900/50 dark:!text-blue-400" :href="route('events.versions.invitations', $version)" wire:navigate>
                                        Invitations
                                    </flux:button>
                                    <flux:button size="sm" variant="filled" class="!bg-violet-50 hover:!bg-violet-100 !text-violet-700 dark:!bg-violet-900/30 dark:hover:!bg-violet-900/50 dark:!text-violet-400" :href="route('events.versions.pitch-files', $version)" wire:navigate>
                                        Pitch Files
                                    </flux:button>
                                @endif
                                @if ($versionAuditionAccess[$version->id] ?? false)
                                    <flux:button size="sm" variant="filled" class="!bg-fuchsia-50 hover:!bg-fuchsia-100 !text-fuchsia-700 dark:!bg-fuchsia-900/30 dark:hover:!bg-fuchsia-900/50 dark:!text-fuchsia-400" :href="route('events.versions.scoring-rubric', $version)" wire:navigate>
                                        Scoring Rubric
                                    </flux:button>
                                    <flux:button size="sm" variant="filled" class="!bg-teal-50 hover:!bg-teal-100 !text-teal-700 dark:!bg-teal-900/30 dark:hover:!bg-teal-900/50 dark:!text-teal-400" :href="route('events.versions.rooms', $version)" wire:navigate>
                                        Rooms
                                    </flux:button>
                                @endif
                                @if ($versionCoRegAccess[$version->id] ?? false)
                                    <flux:button size="sm" variant="filled" class="!bg-lime-50 hover:!bg-lime-100 !text-lime-700 dark:!bg-lime-900/30 dark:hover:!bg-lime-900/50 dark:!text-lime-400" :href="route('events.versions.co-registration-managers', $version)" wire:navigate>
                                        Co-Registration Managers
                                    </flux:button>
                                @endif
                                @if ($versionReportsAccess[$version->id] ?? false)
                                    <flux:button size="sm" variant="filled" class="!bg-amber-50 hover:!bg-amber-100 !text-amber-700 dark:!bg-amber-900/30 dark:hover:!bg-amber-900/50 dark:!text-amber-400" :href="route('events.versions.reports', $version)" wire:navigate>
                                        Reports
                                    </flux:button>
                                @endif
                                @if ($versionTabRoomAccess[$version->id] ?? false)
                                    <flux:button size="sm" variant="filled" class="!bg-sky-50 hover:!bg-sky-100 !text-sky-700 dark:!bg-sky-900/30 dark:hover:!bg-sky-900/50 dark:!text-sky-400" :href="route('events.versions.tab-room.index', $version)" wire:navigate>
                                        Tab Room
                                    </flux:button>
                                @endif
                                @if ($versionWebRegistrationAccess[$version->id] ?? false)
                                    <flux:button size="sm" variant="filled" class="!bg-rose-50 hover:!bg-rose-100 !text-rose-700 dark:!bg-rose-900/30 dark:hover:!bg-rose-900/50 dark:!text-rose-400" :href="route('events.versions.web-registration', $version)" wire:navigate>
                                        Web Registration
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </flux:card>
                @empty
                    <flux:text class="text-zinc-500 py-4 text-center">No versions yet. Add one above.</flux:text>
                @endforelse
            </div>

            <flux:table id="tour-version-list-desktop" class="hidden md:table">
                <flux:table.columns>
                    <flux:table.column>Version</flux:table.column>
                    <flux:table.column>Class Of</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Upload</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($versions as $version)
                        <flux:table.row>
                            <flux:table.cell class="font-medium align-top">{{ $version->name }}</flux:table.cell>
                            <flux:table.cell class="align-top">{{ $version->senior_class_of }}</flux:table.cell>
                            <flux:table.cell class="capitalize align-top">{{ $version->getRawOriginal('audition_type') }}</flux:table.cell>
                            <flux:table.cell class="capitalize align-top">{{ $version->getRawOriginal('upload_type') }}</flux:table.cell>
                            <flux:table.cell class="align-top">
                                @php $vs = $version->getRawOriginal('status'); @endphp
                                @if ($vs === 'active')
                                    <flux:badge color="green" size="sm">Active</flux:badge>
                                @elseif ($vs === 'sandbox')
                                    <flux:badge color="amber" size="sm">Sandbox</flux:badge>
                                @elseif ($vs === 'inactive')
                                    <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                                @else
                                    <flux:badge color="red" size="sm">Closed</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="!border-t-0 pt-0 pb-4 whitespace-normal">
                                <div id="{{ $loop->first ? 'tour-version-actions-desktop' : '' }}" class="flex flex-wrap gap-2">
                                    @if ($canManageEvent)
                                        <flux:button size="sm" variant="filled" :href="route('events.versions.edit', $version)" wire:navigate>
                                            Configure
                                        </flux:button>
                                        <flux:button size="sm" variant="filled" class="!bg-blue-50 hover:!bg-blue-100 !text-blue-700 dark:!bg-blue-900/30 dark:hover:!bg-blue-900/50 dark:!text-blue-400" :href="route('events.versions.invitations', $version)" wire:navigate>
                                            Invitations
                                        </flux:button>
                                        <flux:button size="sm" variant="filled" class="!bg-violet-50 hover:!bg-violet-100 !text-violet-700 dark:!bg-violet-900/30 dark:hover:!bg-violet-900/50 dark:!text-violet-400" :href="route('events.versions.pitch-files', $version)" wire:navigate>
                                            Pitch Files
                                        </flux:button>
                                    @endif
                                    @if ($versionAuditionAccess[$version->id] ?? false)
                                        <flux:button size="sm" variant="filled" class="!bg-fuchsia-50 hover:!bg-fuchsia-100 !text-fuchsia-700 dark:!bg-fuchsia-900/30 dark:hover:!bg-fuchsia-900/50 dark:!text-fuchsia-400" :href="route('events.versions.scoring-rubric', $version)" wire:navigate>
                                            Scoring Rubric
                                        </flux:button>
                                        <flux:button size="sm" variant="filled" class="!bg-teal-50 hover:!bg-teal-100 !text-teal-700 dark:!bg-teal-900/30 dark:hover:!bg-teal-900/50 dark:!text-teal-400" :href="route('events.versions.rooms', $version)" wire:navigate>
                                            Rooms
                                        </flux:button>
                                    @endif
                                    @if ($versionCoRegAccess[$version->id] ?? false)
                                        <flux:button size="sm" variant="filled" class="!bg-lime-50 hover:!bg-lime-100 !text-lime-700 dark:!bg-lime-900/30 dark:hover:!bg-lime-900/50 dark:!text-lime-400" :href="route('events.versions.co-registration-managers', $version)" wire:navigate>
                                            Co-Registration Managers
                                        </flux:button>
                                    @endif
                                    @if ($versionReportsAccess[$version->id] ?? false)
                                        <flux:button size="sm" variant="filled" class="!bg-amber-50 hover:!bg-amber-100 !text-amber-700 dark:!bg-amber-900/30 dark:hover:!bg-amber-900/50 dark:!text-amber-400" :href="route('events.versions.reports', $version)" wire:navigate>
                                            Reports
                                        </flux:button>
                                    @endif
                                    @if ($versionTabRoomAccess[$version->id] ?? false)
                                        <flux:button size="sm" variant="filled" class="!bg-sky-50 hover:!bg-sky-100 !text-sky-700 dark:!bg-sky-900/30 dark:hover:!bg-sky-900/50 dark:!text-sky-400" :href="route('events.versions.tab-room.index', $version)" wire:navigate>
                                            Tab Room
                                        </flux:button>
                                    @endif
                                    @if ($versionWebRegistrationAccess[$version->id] ?? false)
                                        <flux:button size="sm" variant="filled" class="!bg-rose-50 hover:!bg-rose-100 !text-rose-700 dark:!bg-rose-900/30 dark:hover:!bg-rose-900/50 dark:!text-rose-400" :href="route('events.versions.web-registration', $version)" wire:navigate>
                                            Web Registration
                                        </flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                                No versions yet. Add one above.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:tab.panel>

        <flux:tab.panel name="ensembles">
            @if ($canManageEvent)
                <div id="tour-add-ensemble" class="flex justify-end mb-4">
                    <flux:modal.trigger name="edit-ensemble">
                        <flux:button variant="primary" icon="plus" wire:click="openAddEnsemble">
                            Add Ensemble
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            @endif

            @forelse ($ensembles as $ensemble)
                <flux:card id="{{ $loop->first ? 'tour-ensemble-card' : '' }}" class="mb-4">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div>
                            <flux:heading size="base">{{ $ensemble->name }}</flux:heading>
                            @if ($ensemble->abbreviation)
                                <flux:text size="sm" class="text-zinc-500">
                                    Abbr: {{ $ensemble->abbreviation }}
                                    @if ($ensemble->short_name)
                                        &bull; {{ $ensemble->short_name }}
                                    @endif
                                </flux:text>
                            @elseif ($ensemble->short_name)
                                <flux:text size="sm" class="text-zinc-500">{{ $ensemble->short_name }}</flux:text>
                            @endif
                        </div>
                        @if ($canManageEvent)
                            <flux:modal.trigger name="edit-ensemble">
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditEnsemble({{ $ensemble->id }})">
                                    Edit
                                </flux:button>
                            </flux:modal.trigger>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {{-- Grades --}}
                        <div id="{{ $loop->first ? 'tour-ensemble-grades' : '' }}">
                            <flux:subheading class="mb-2">Eligible Grades</flux:subheading>
                            <div class="flex flex-wrap gap-3 mb-3">
                                @foreach ($gradeOptions as $grade)
                                    <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                        <flux:checkbox
                                            wire:model="ens_grades.{{ $ensemble->id }}"
                                            value="{{ $grade }}"
                                        />
                                        Grade {{ $grade }}
                                    </label>
                                @endforeach
                            </div>
                            @if ($canManageEvent)
                                <flux:button size="sm" wire:click="saveEnsembleGrades({{ $ensemble->id }})">
                                    Save Grades
                                </flux:button>
                            @endif
                        </div>

                        {{-- Voice Parts --}}
                        <div id="{{ $loop->first ? 'tour-ensemble-voiceparts' : '' }}">
                            <flux:subheading class="mb-2">Voice Parts</flux:subheading>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-2 mb-3">
                                @foreach ($allVoiceParts as $vp)
                                    <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                        <flux:checkbox
                                            wire:model="ens_voice_parts.{{ $ensemble->id }}"
                                            value="{{ $vp->id }}"
                                        />
                                        {{ $vp->name }}
                                    </label>
                                @endforeach
                            </div>
                            @if ($canManageEvent)
                                <flux:button size="sm" wire:click="saveEnsembleVoiceParts({{ $ensemble->id }})">
                                    Save Voice Parts
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </flux:card>
            @empty
                <flux:text class="text-zinc-500 py-4 text-center">No ensembles yet. Add one above.</flux:text>
            @endforelse
        </flux:tab.panel>
    </flux:tab.group>

    {{-- Add Version modal --}}
    <flux:modal name="add-version" class="w-full max-w-md">
        <flux:heading size="lg" class="mb-4">Add Version</flux:heading>

        <div class="space-y-4">
            <flux:field>
                <flux:label>Version Name</flux:label>
                <flux:input wire:model="new_name" placeholder="e.g. 2025 All-State Chorus" />
                <flux:error name="new_name" />
            </flux:field>

            <flux:field>
                <flux:label>Short Name</flux:label>
                <flux:input wire:model="new_short_name" placeholder="e.g. 2025 All-State" />
                <flux:error name="new_short_name" />
            </flux:field>

            <flux:field>
                <flux:label>Senior Class Of</flux:label>
                <flux:input wire:model="new_senior_class_of" type="number" min="2000" max="2100" />
                <flux:description>The graduating year of seniors eligible for this version.</flux:description>
                <flux:error name="new_senior_class_of" />
            </flux:field>
        </div>

        @if ($errors->any())
            <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4">
                <flux:callout.text>Please correct the errors above.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex justify-end gap-3 mt-6">
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" wire:click="createVersion">
                Create Version
            </flux:button>
        </div>
    </flux:modal>

    {{-- Add/Edit Ensemble modal --}}
    <flux:modal name="edit-ensemble" class="w-full max-w-md">
        <flux:heading size="lg" class="mb-4">
            {{ $editingEnsembleId ? 'Edit Ensemble' : 'Add Ensemble' }}
        </flux:heading>

        <div class="space-y-4">
            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input wire:model="ens_name" placeholder="e.g. All-State Mixed Chorus" />
                <flux:error name="ens_name" />
            </flux:field>

            <flux:field>
                <flux:label>Short Name</flux:label>
                <flux:input wire:model="ens_short_name" placeholder="e.g. Mixed Chorus" />
                <flux:error name="ens_short_name" />
            </flux:field>

            <flux:field>
                <flux:label>Abbreviation</flux:label>
                <flux:input wire:model="ens_abbreviation" placeholder="e.g. MC" maxlength="20" />
                <flux:description>Short code used in reports and labels.</flux:description>
                <flux:error name="ens_abbreviation" />
            </flux:field>
        </div>

        @if ($errors->hasAny(['ens_name', 'ens_short_name', 'ens_abbreviation']))
            <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4">
                <flux:callout.text>Please correct the errors above.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex justify-end gap-3 mt-6">
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" wire:click="saveEnsemble">
                {{ $editingEnsembleId ? 'Save Changes' : 'Create Ensemble' }}
            </flux:button>
        </div>
    </flux:modal>

    {{-- Spotlight tour, same approach as the Registration Dashboard's
         (resources/views/livewire/registrations/version-dashboard.blade.php)
         — a persistent "Take a tour" button covering both the Versions and
         Ensembles tabs. Unlike that page, some steps live behind a tab that
         isn't the default active one, so the engine below programmatically
         clicks the real tab trigger (mirroring the click a user would make)
         before spotlighting a step whose `tab` doesn't match the currently
         active one — see the `existsEl`/`resolveEl` split in the script. --}}
    <button type="button" id="tour-dismiss-trigger" wire:click="dismissOrientation" class="hidden" aria-hidden="true" tabindex="-1"></button>

    <div id="tour-scrim" class="hidden fixed inset-0 z-[59]"></div>
    <div id="tour-cutout" class="hidden fixed z-[60] rounded-lg pointer-events-none transition-[top,left,width,height] duration-300 ease-out"></div>
    <div
        id="tour-card"
        class="hidden fixed z-[61] w-72 max-w-[calc(100vw-2rem)] bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-xl p-4 transition-[top,left] duration-300 ease-out"
        role="dialog" aria-modal="true" aria-labelledby="tour-title" aria-describedby="tour-body"
    >
        <div class="h-1 rounded-full bg-zinc-100 dark:bg-zinc-700 overflow-hidden mb-3">
            <div id="tour-progress" class="h-full bg-orange-600 dark:bg-orange-400 rounded-full transition-[width] duration-200"></div>
        </div>
        <div id="tour-stepcount" class="text-[11px] font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 mb-1"></div>
        <h3 id="tour-title" class="text-sm font-semibold text-zinc-800 dark:text-zinc-100 mb-1"></h3>
        <p id="tour-body" class="text-sm text-zinc-500 dark:text-zinc-400 mb-3"></p>
        <div class="flex items-center justify-between gap-2">
            <button type="button" id="tour-skip" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">Skip tour</button>
            <div class="flex gap-2">
                <button type="button" id="tour-prev" class="text-sm font-medium px-3 py-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 disabled:opacity-40">Back</button>
                <button type="button" id="tour-next" class="text-sm font-medium px-3 py-1.5 rounded-md border border-orange-600 bg-orange-600 text-white hover:brightness-110 dark:border-orange-400 dark:bg-orange-400 dark:text-zinc-900">Next</button>
            </div>
        </div>
    </div>

    <style>
        #tour-cutout { box-shadow: 0 0 0 9999px rgba(15, 13, 12, 0.6); }
        :root[data-theme="dark"] #tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) #tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        }
        #tour-cutout::after {
            content: "";
            position: absolute;
            inset: -4px;
            border-radius: 11px;
            border: 2px solid rgb(234 88 12);
            box-shadow: 0 0 0 4px rgba(234, 88, 12, 0.5);
            animation: tour-pulse 1.8s ease-in-out infinite;
        }
        @keyframes tour-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.45; }
        }
        @media (prefers-reduced-motion: reduce) {
            #tour-cutout, #tour-card { transition: none !important; }
            #tour-cutout::after { animation: none !important; }
        }
    </style>

    <script>
        (function () {
            var steps = [
                { ids: ['tour-event-badges'], title: 'Event Status', body: 'Status, frequency, and audition/ensemble counts for this Event at a glance.', tab: 'versions' },
                { ids: ['tour-event-tabs'], title: 'Versions & Ensembles', body: "Switch between this Event's Versions and its Ensembles." , tab: 'versions' },
                { ids: ['tour-add-version'], title: 'Add Version', body: "Start a new periodic run of this Event — most fields are cloned from the latest Version, so you're not starting from scratch.", tab: 'versions' },
                { ids: ['tour-version-list-desktop', 'tour-version-list-mobile'], title: 'Versions', body: 'Every Version of this Event, past and present, each with its own status.', tab: 'versions' },
                { ids: ['tour-version-actions-desktop', 'tour-version-actions-mobile'], title: 'Version tools', body: "Jump into a Version's own management pages — setup, roster access, and whichever other role-scoped tools you have access to.", tab: 'versions' },
                { ids: ['tour-add-ensemble'], title: 'Add Ensemble', body: 'Define a new choir this Event produces — Mixed Chorus, Treble Choir, and so on.', tab: 'ensembles' },
                { ids: ['tour-ensemble-card'], title: 'Ensembles', body: 'Each Ensemble this Event produces.', tab: 'ensembles' },
                { ids: ['tour-ensemble-grades'], title: 'Eligible Grades', body: 'Which grade levels can audition for this Ensemble.', tab: 'ensembles' },
                { ids: ['tour-ensemble-voiceparts'], title: 'Voice Parts', body: 'Which voice parts this Ensemble accepts.', tab: 'ensembles' }
            ];

            var activeSteps = [];
            var current = -1;
            var running = false;
            var currentTab = 'versions';
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var raf = null;

            var scrim = document.getElementById('tour-scrim');
            var cutout = document.getElementById('tour-cutout');
            var card = document.getElementById('tour-card');
            var startBtn = document.getElementById('tour-start');
            var dismissTrigger = document.getElementById('tour-dismiss-trigger');
            var prevBtn = document.getElementById('tour-prev');
            var nextBtn = document.getElementById('tour-next');
            var skipBtn = document.getElementById('tour-skip');
            var titleEl = document.getElementById('tour-title');
            var bodyEl = document.getElementById('tour-body');
            var stepCountEl = document.getElementById('tour-stepcount');
            var progressEl = document.getElementById('tour-progress');

            if (!startBtn || !scrim || !cutout || !card) return;

            // Existence-only check, used only to decide which steps are even
            // reachable on this page/user — a step behind the Ensembles tab
            // still "exists" while the Versions tab is showing (Flux keeps
            // both tab panels in the DOM, just CSS-hidden), so this must NOT
            // check visibility the way resolveEl() below does, or every
            // Ensembles-tab step would be wrongly dropped before the tour
            // ever gets a chance to switch tabs.
            function existsEl(ids) {
                for (var i = 0; i < ids.length; i++) {
                    if (document.getElementById(ids[i])) return true;
                }
                return false;
            }

            function resolveEl(ids) {
                for (var i = 0; i < ids.length; i++) {
                    var el = document.getElementById(ids[i]);
                    if (el && el.offsetParent !== null) return el;
                }
                return null;
            }

            function start() {
                activeSteps = steps.filter(function (s) { return existsEl(s.ids); });
                if (activeSteps.length === 0) return;

                running = true;
                current = 0;
                currentTab = 'versions';
                scrim.classList.remove('hidden');
                cutout.classList.remove('hidden');
                card.classList.remove('hidden');
                document.addEventListener('keydown', onKeydown);
                window.addEventListener('resize', onReposition);
                window.addEventListener('scroll', onReposition, true);
                render();
            }

            function end() {
                running = false;
                scrim.classList.add('hidden');
                cutout.classList.add('hidden');
                card.classList.add('hidden');
                document.removeEventListener('keydown', onKeydown);
                window.removeEventListener('resize', onReposition);
                window.removeEventListener('scroll', onReposition, true);
                if (dismissTrigger) dismissTrigger.click();
                startBtn.focus();
            }

            function go(delta) {
                var target = current + delta;
                if (target < 0) return;
                if (target >= activeSteps.length) { end(); return; }
                current = target;
                render();
            }

            function render() {
                var step = activeSteps[current];

                if (step.tab && step.tab !== currentTab) {
                    var tabBtn = document.getElementById('tour-tab-' + step.tab);
                    if (tabBtn) tabBtn.click();
                    currentTab = step.tab;
                }

                var el = resolveEl(step.ids);
                if (!el) { go(1); return; }

                titleEl.textContent = step.title;
                bodyEl.textContent = step.body;
                stepCountEl.textContent = 'Step ' + (current + 1) + ' of ' + activeSteps.length;
                progressEl.style.width = (((current + 1) / activeSteps.length) * 100) + '%';
                prevBtn.disabled = current === 0;
                nextBtn.textContent = current === activeSteps.length - 1 ? 'Finish' : 'Next';

                el.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });

                window.setTimeout(function () { position(el); }, reduceMotion ? 0 : 260);
                nextBtn.focus();
            }

            function position(el) {
                var pad = 6;
                var r = el.getBoundingClientRect();

                cutout.style.top = (r.top - pad) + 'px';
                cutout.style.left = (r.left - pad) + 'px';
                cutout.style.width = (r.width + pad * 2) + 'px';
                cutout.style.height = (r.height + pad * 2) + 'px';

                var cardW = card.offsetWidth || 288;
                var cardH = card.offsetHeight || 160;
                var margin = 14;
                var vw = window.innerWidth;
                var vh = window.innerHeight;

                var top = r.bottom + margin;
                if (top + cardH > vh) {
                    top = r.top - cardH - margin;
                    if (top < 8) top = Math.max(8, Math.min(vh - cardH - 8, r.top));
                }

                var left = r.left;
                if (left + cardW > vw - 8) left = vw - cardW - 8;
                if (left < 8) left = 8;

                card.style.top = top + 'px';
                card.style.left = left + 'px';
            }

            function onReposition() {
                if (!running) return;
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(function () {
                    var el = resolveEl(activeSteps[current].ids);
                    if (el) position(el);
                });
            }

            function onKeydown(e) {
                if (e.key === 'Escape') { end(); return; }
                if (e.key === 'ArrowRight' || e.key === 'Enter') { go(1); return; }
                if (e.key === 'ArrowLeft') { go(-1); return; }
            }

            startBtn.addEventListener('click', start);
            nextBtn.addEventListener('click', function () { go(1); });
            prevBtn.addEventListener('click', function () { go(-1); });
            skipBtn.addEventListener('click', end);

            if (startBtn.dataset.autoStart === '1') start();
        })();
    </script>
</div>

<x-layouts.app>
    @php $teacher = auth()->user()->teacher; @endphp

    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="xl">Dashboard</flux:heading>
            @if ($teacher)
                <flux:button id="dash-tour-start" data-auto-start="{{ auth()->user()->dismissed_dashboard_orientation_at === null ? '1' : '0' }}" size="sm" variant="ghost" icon="sparkles" type="button">Take a tour</flux:button>
            @endif
        </div>

        @if ($teacher)
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-4">
            <a id="tour-card-schools" href="{{ route('schools.index') }}" wire:navigate class="block h-full transition hover:shadow-md">
                <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-blue-500 sm:border-2 sm:border-blue-500">
                    <flux:heading size="lg">Schools</flux:heading>

                    @php
                        // A single callback returning a [rank, name] tuple — not Collection::sortBy()'s
                        // multi-criteria array form, whose comparator callbacks need two args (a, b),
                        // not one. PHP compares same-shaped arrays element-by-element, so this sorts
                        // by status rank first and then alphabetically within each rank.
                        $sortedSchools = $teacher->schools->sortBy(
                            fn ($school) => [$school->pivot->statusSortRank(), $school->name]
                        );
                    @endphp

                    @foreach ($sortedSchools as $school)
                        <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="w-full">
                                <flux:text class="font-medium">{{ $school->name }}</flux:text>
                            </div>

                            <div class="flex flex-row items-center gap-2 sm:flex-col sm:items-start">
                                @if ($school->pivot->role)
                                    <flux:badge color="zinc" size="sm">{{ $school->pivot->role->label() }}</flux:badge>
                                @endif

                                <flux:badge color="{{ $school->pivot->is_active ? 'green' : 'zinc' }}" size="sm">
                                    {{ $school->pivot->is_active ? 'Active' : 'Inactive' }}
                                </flux:badge>

                                <flux:badge color="{{ $school->pivot->verified_at !== null ? 'green' : 'amber' }}" size="sm">
                                    {{ $school->pivot->verified_at !== null ? 'Email verified' : 'Email pending' }}
                                </flux:badge>
                            </div>
                        </div>
                    @endforeach
                </flux:card>
            </a>

            <a id="tour-card-students" href="{{ route('students.index') }}" wire:navigate class="block h-full transition hover:shadow-md">
                <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-violet-500 sm:border-2 sm:border-violet-500">
                    <flux:heading size="lg">Students</flux:heading>

                    @php $rosterSummary = \App\Support\TeacherRosterSummary::forTeacher($teacher); @endphp

                    @foreach ($rosterSummary as $summary)
                        <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="w-full">
                                <flux:text class="font-medium">{{ $summary['school']->name }}</flux:text>
                            </div>

                            <flux:badge color="zinc" size="sm" class="self-start">{{ $summary['total'] }} {{ \Illuminate\Support\Str::plural('student', $summary['total']) }}</flux:badge>

                            @if ($summary['byGrade'] !== [])
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($summary['byGrade'] as $grade => $count)
                                        <flux:badge size="sm">Grade {{ $grade }}: {{ $count }}</flux:badge>
                                    @endforeach
                                </div>
                            @else
                                <flux:text size="sm" class="text-zinc-500">No students yet.</flux:text>
                            @endif
                        </div>
                    @endforeach
                </flux:card>
            </a>

            <a id="tour-card-organizations" href="{{ route('organizations.index') }}" wire:navigate class="block h-full transition hover:shadow-md">
                <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-orange-500 sm:border-2 sm:border-orange-500">
                    <flux:heading size="lg">Organizations</flux:heading>

                    @php $organizations = $teacher->teacherSupervisors()->with('organization')->get()->pluck('organization'); @endphp

                    <flux:badge color="zinc" size="sm">
                        {{ $organizations->count() }} {{ \Illuminate\Support\Str::plural('organization', $organizations->count()) }}
                    </flux:badge>

                    @if ($organizations->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            @foreach ($organizations as $organization)
                                <flux:badge size="sm">{{ $organization->abbr ?: $organization->name }}</flux:badge>
                            @endforeach
                        </div>
                    @else
                        <flux:text size="sm" class="text-zinc-500">No organizations linked yet.</flux:text>
                    @endif
                </flux:card>
            </a>

            @php
                $roleAssignmentService = app(\App\Services\VersionRoleAssignmentService::class);
                $canAccessEvents = $roleAssignmentService->canAccessEventsSection(auth()->user());
            @endphp

            @if ($canAccessEvents)
                <a id="tour-card-events" href="{{ route('events.index') }}" wire:navigate class="block h-full transition hover:shadow-md">
                    <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-teal-500 sm:border-2 sm:border-teal-500">
                        <flux:heading size="lg">Events</flux:heading>

                        @php
                            $organizationIds = $teacher->teacherSupervisors()->pluck('organization_id');
                            $judgeEventIds = $roleAssignmentService->judgeEventIds(auth()->user());
                            $openEvents = \App\Models\Event::where('status', 'active')
                                ->where(function ($query) use ($organizationIds, $judgeEventIds) {
                                    $query->whereIn('organization_id', $organizationIds)
                                        ->orWhereIn('id', $judgeEventIds);
                                })
                                ->get();
                        @endphp

                        @if ($openEvents->isNotEmpty())
                            <div class="flex flex-col gap-2">
                                @foreach ($openEvents as $event)
                                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                        <flux:text class="font-medium">{{ $event->name }}</flux:text>
                                        <flux:text size="sm" class="text-zinc-500 capitalize">{{ $event->getRawOriginal('frequency') }}</flux:text>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <flux:text size="sm" class="text-zinc-500">No open events.</flux:text>
                        @endif
                    </flux:card>
                </a>
            @else
                <a id="tour-card-events" href="{{ route('events.create') }}" wire:navigate class="block h-full transition hover:shadow-md">
                    <flux:card class="flex h-full flex-col gap-4 border-l-4 border-l-teal-500 sm:border-2 sm:border-teal-500">
                        <flux:heading size="lg">Events</flux:heading>
                        <flux:text size="sm" class="text-zinc-500">You're not managing any events yet.</flux:text>
                        <flux:text size="sm" class="font-medium text-teal-600 dark:text-teal-400">+ Start a new event</flux:text>
                    </flux:card>
                </a>
            @endif
        </div>
    </div>

    {{-- Spotlight tour, same approach as the Registration Dashboard's
         (resources/views/livewire/registrations/version-dashboard.blade.php)
         — a persistent "Take a tour" button covering the sidebar nav and
         this page's own cards. Persistence goes through a tiny embedded
         Livewire component (App\Livewire\Dashboard\TourDismiss) since this
         page itself is a plain Route::view, not a Livewire component. --}}
    <livewire:dashboard.tour-dismiss />

    <div id="dash-tour-scrim" class="hidden fixed inset-0 z-[59]"></div>
    <div id="dash-tour-cutout" class="hidden fixed z-[60] rounded-lg pointer-events-none transition-[top,left,width,height] duration-300 ease-out"></div>
    <div
        id="dash-tour-card"
        class="hidden fixed z-[61] w-72 max-w-[calc(100vw-2rem)] bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-xl p-4 transition-[top,left] duration-300 ease-out"
        role="dialog" aria-modal="true" aria-labelledby="dash-tour-title" aria-describedby="dash-tour-body"
    >
        <div class="h-1 rounded-full bg-zinc-100 dark:bg-zinc-700 overflow-hidden mb-3">
            <div id="dash-tour-progress" class="h-full bg-orange-600 dark:bg-orange-400 rounded-full transition-[width] duration-200"></div>
        </div>
        <div id="dash-tour-stepcount" class="text-[11px] font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 mb-1"></div>
        <h3 id="dash-tour-title" class="text-sm font-semibold text-zinc-800 dark:text-zinc-100 mb-1"></h3>
        <p id="dash-tour-body" class="text-sm text-zinc-500 dark:text-zinc-400 mb-3"></p>
        <div class="flex items-center justify-between gap-2">
            <button type="button" id="dash-tour-skip" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">Skip tour</button>
            <div class="flex gap-2">
                <button type="button" id="dash-tour-prev" class="text-sm font-medium px-3 py-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 disabled:opacity-40">Back</button>
                <button type="button" id="dash-tour-next" class="text-sm font-medium px-3 py-1.5 rounded-md border border-orange-600 bg-orange-600 text-white hover:brightness-110 dark:border-orange-400 dark:bg-orange-400 dark:text-zinc-900">Next</button>
            </div>
        </div>
    </div>

    <style>
        #dash-tour-cutout { box-shadow: 0 0 0 9999px rgba(15, 13, 12, 0.6); }
        :root[data-theme="dark"] #dash-tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) #dash-tour-cutout { box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72); }
        }
        #dash-tour-cutout::after {
            content: "";
            position: absolute;
            inset: -4px;
            border-radius: 11px;
            border: 2px solid rgb(234 88 12);
            box-shadow: 0 0 0 4px rgba(234, 88, 12, 0.5);
            animation: dash-tour-pulse 1.8s ease-in-out infinite;
        }
        @keyframes dash-tour-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.45; }
        }
        @media (prefers-reduced-motion: reduce) {
            #dash-tour-cutout, #dash-tour-card { transition: none !important; }
            #dash-tour-cutout::after { animation: none !important; }
        }
    </style>

    <script>
        (function () {
            var steps = [
                { ids: ['tour-sidebar-dashboard'], title: 'Dashboard', body: 'Your home base — jump back here anytime from the sidebar.' },
                { ids: ['tour-sidebar-fastpass'], title: 'Fast Pass', body: "Quick links to your recently and most visited pages, so you don't have to dig through menus every time." },
                { ids: ['tour-sidebar-schools'], title: 'Schools', body: 'Manage the schools you teach at — add a new one or check your verification status.' },
                { ids: ['tour-sidebar-students'], title: 'Students', body: 'Your full student roster across every active school.' },
                { ids: ['tour-sidebar-organizations'], title: 'Organizations', body: "The associations you're a member of, like NJMEA or CJMEA." },
                { ids: ['tour-sidebar-events'], title: 'Events', body: 'Events you manage or judge for — create a new one or open an existing one.' },
                { ids: ['tour-sidebar-registrations'], title: 'Registrations', body: 'Where you register candidates for an open Event and manage their status.' },
                { ids: ['tour-sidebar-results'], title: 'Results', body: "Score reports and outcomes once an Event's adjudication has closed." },
                { ids: ['tour-sidebar-profile'], title: 'Profile', body: 'Update your name, contact info, and pronouns.' },
                { ids: ['tour-sidebar-password'], title: 'Password', body: 'Change your account password.' },
                { ids: ['tour-sidebar-appearance'], title: 'Appearance', body: 'Switch between light and dark mode.' },
                { ids: ['tour-sidebar-logout'], title: 'Log out', body: 'Sign out of your account.' },
                { ids: ['tour-card-schools'], title: 'Schools', body: 'Every school you teach at, with your role and verification status at a glance.' },
                { ids: ['tour-card-students'], title: 'Students', body: 'A roster summary broken down by grade for each of your schools.' },
                { ids: ['tour-card-organizations'], title: 'Organizations', body: 'The associations linked to your account.' },
                { ids: ['tour-card-events'], title: 'Events', body: 'Events currently open for you to manage, or a shortcut to start your first one.' }
            ];

            var activeSteps = [];
            var current = -1;
            var running = false;
            var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var raf = null;

            var scrim = document.getElementById('dash-tour-scrim');
            var cutout = document.getElementById('dash-tour-cutout');
            var card = document.getElementById('dash-tour-card');
            var startBtn = document.getElementById('dash-tour-start');
            var dismissTrigger = document.getElementById('dashboard-tour-dismiss-trigger');
            var prevBtn = document.getElementById('dash-tour-prev');
            var nextBtn = document.getElementById('dash-tour-next');
            var skipBtn = document.getElementById('dash-tour-skip');
            var titleEl = document.getElementById('dash-tour-title');
            var bodyEl = document.getElementById('dash-tour-body');
            var stepCountEl = document.getElementById('dash-tour-stepcount');
            var progressEl = document.getElementById('dash-tour-progress');

            if (!startBtn || !scrim || !cutout || !card) return;

            function resolveEl(ids) {
                for (var i = 0; i < ids.length; i++) {
                    var el = document.getElementById(ids[i]);
                    if (el && el.offsetParent !== null) return el;
                }
                return null;
            }

            function start() {
                activeSteps = steps.filter(function (s) { return resolveEl(s.ids) !== null; });
                if (activeSteps.length === 0) return;

                running = true;
                current = 0;
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
    @endif
</x-layouts.app>

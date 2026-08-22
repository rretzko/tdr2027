<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <flux:heading size="xl">Events</flux:heading>

        <div class="flex items-center gap-3">
            <flux:button id="tour-start" data-auto-start="{{ auth()->user()->dismissed_events_index_orientation_at === null ? '1' : '0' }}" size="sm" variant="ghost" icon="sparkles" type="button">Take a tour</flux:button>

            @if ($isFounder)
                <flux:modal.trigger name="edit-event">
                    <flux:button id="tour-add-event" variant="primary" icon="plus" wire:click="add">
                        Add event
                    </flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    {{-- Cards below md:, full table at md:+ --}}
    <div id="tour-events-list-mobile" class="md:hidden space-y-3">
        @forelse ($events as $event)
            <flux:card size="sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <flux:heading size="base" class="truncate">{{ $event->name }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-500">{{ $event->organization->name }}</flux:text>
                        <flux:text size="sm" class="text-zinc-400">{{ $event->getRawOriginal('frequency') }}</flux:text>
                    </div>

                    <div id="tour-status-badge-mobile" class="flex flex-col items-end gap-2 shrink-0">
                        @php $raw = $event->getRawOriginal('status'); @endphp
                        @if ($raw === 'active')
                            <flux:badge color="green" size="sm">Active</flux:badge>
                        @elseif ($raw === 'sandbox')
                            <flux:badge color="amber" size="sm">Sandbox</flux:badge>
                        @elseif ($raw === 'inactive')
                            <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Closed</flux:badge>
                        @endif

                        @if ($adjudicatableVersions[$event->id] ?? null)
                            <flux:badge :href="route('events.versions.adjudicate', $adjudicatableVersions[$event->id])" wire:navigate color="teal" size="sm">
                                Adjudicate
                            </flux:badge>
                        @endif

                        <div class="flex gap-2">
                            @if ($isFounder || ($hasVersionRole[$event->id] ?? false))
                                <flux:button id="tour-versions-btn-mobile" size="sm" :href="route('events.show', $event)" wire:navigate>
                                    Versions
                                </flux:button>
                            @endif
                            @if ($isFounder)
                                <flux:modal.trigger name="edit-event">
                                    <flux:button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $event->id }})" />
                                </flux:modal.trigger>
                            @endif
                        </div>
                    </div>
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500 py-4 text-center">No events yet. Add one to get started.</flux:text>
        @endforelse
    </div>

    <flux:table id="tour-events-list-desktop" class="hidden md:table">
        <flux:table.columns>
            <flux:table.column>Event</flux:table.column>
            <flux:table.column>Organization</flux:table.column>
            <flux:table.column>Frequency</flux:table.column>
            <flux:table.column>Auditions</flux:table.column>
            <flux:table.column>Ensembles</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column class="w-24">Adjudicate</flux:table.column>
            <flux:table.column class="w-32"></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($events as $event)
                <flux:table.row>
                    <flux:table.cell class="font-medium align-top">
                        {{ $event->name }}
                        @if ($event->short_name)
                            <flux:text size="sm" class="text-zinc-400">{{ $event->short_name }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="align-top">{{ $event->organization->name }}</flux:table.cell>
                    <flux:table.cell class="capitalize align-top">{{ $event->getRawOriginal('frequency') }}</flux:table.cell>
                    <flux:table.cell class="align-top">{{ $event->audition_count }}</flux:table.cell>
                    <flux:table.cell class="align-top">{{ $event->ensemble_count }}</flux:table.cell>
                    <flux:table.cell id="tour-status-badge" class="align-top">
                        @php $raw = $event->getRawOriginal('status'); @endphp
                        @if ($raw === 'active')
                            <flux:badge color="green" size="sm">Active</flux:badge>
                        @elseif ($raw === 'sandbox')
                            <flux:badge color="amber" size="sm">Sandbox</flux:badge>
                        @elseif ($raw === 'inactive')
                            <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                        @else
                            <flux:badge color="red" size="sm">Closed</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-normal align-top">
                        @if ($adjudicatableVersions[$event->id] ?? null)
                            <flux:badge :href="route('events.versions.adjudicate', $adjudicatableVersions[$event->id])" wire:navigate color="teal" size="sm">
                                Adjudicate
                            </flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-normal align-top">
                        <div class="flex flex-wrap gap-2 justify-end">
                            @if ($isFounder || ($hasVersionRole[$event->id] ?? false))
                                <flux:button id="tour-versions-btn" size="sm" :href="route('events.show', $event)" wire:navigate>
                                    Versions
                                </flux:button>
                            @endif
                            @if ($isFounder)
                                <flux:modal.trigger name="edit-event">
                                    <flux:button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $event->id }})" />
                                </flux:modal.trigger>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center text-zinc-500 py-6">
                        No events yet. Add one to get started.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="edit-event" class="w-full max-w-lg">
        <flux:heading size="lg" class="mb-4">
            {{ $editingEventId ? 'Edit Event' : 'Add Event' }}
        </flux:heading>

        <div class="space-y-4">
            <flux:field>
                <flux:label>Event Name</flux:label>
                <flux:input wire:model="edit_name" placeholder="e.g. All-State Chorus" />
                <flux:error name="edit_name" />
            </flux:field>

            <flux:field>
                <flux:label>Short Name</flux:label>
                <flux:input wire:model="edit_short_name" placeholder="e.g. All-State" />
                <flux:error name="edit_short_name" />
            </flux:field>

            <flux:field>
                <flux:label>Organization</flux:label>
                <flux:select wire:model="edit_organization_id">
                    <flux:select.option value="">— select —</flux:select.option>
                    @foreach ($organizations as $org)
                        <flux:select.option value="{{ $org->id }}">{{ $org->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="edit_organization_id" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Status</flux:label>
                    <flux:select wire:model="edit_status">
                        @foreach ($statuses as $status)
                            <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="edit_status" />
                </flux:field>

                <flux:field>
                    <flux:label>Frequency</flux:label>
                    <flux:select wire:model="edit_frequency">
                        @foreach ($frequencies as $freq)
                            <flux:select.option value="{{ $freq->value }}">{{ $freq->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="edit_frequency" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Audition Count</flux:label>
                    <flux:input wire:model="edit_audition_count" type="number" min="1" max="10" />
                    <flux:error name="edit_audition_count" />
                </flux:field>

                <flux:field>
                    <flux:label>Ensemble Count</flux:label>
                    <flux:input wire:model="edit_ensemble_count" type="number" min="1" max="20" />
                    <flux:error name="edit_ensemble_count" />
                </flux:field>
            </div>
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
            <flux:button variant="primary" wire:click="save">
                {{ $editingEventId ? 'Save Changes' : 'Create Event' }}
            </flux:button>
        </div>
    </flux:modal>

    {{-- Spotlight tour, same approach as Events\Show's (resources/views/livewire/events/show.blade.php) --}}
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
                { ids: ['tour-add-event'], title: 'Add Event', body: 'Start a new Event — the stable definition of a program (name, sponsoring Organization, how often it runs). Founder only.' },
                { ids: ['tour-events-list-desktop', 'tour-events-list-mobile'], title: 'Your Events', body: 'Every Event you have a role on, across every Organization.' },
                { ids: ['tour-status-badge', 'tour-status-badge-mobile'], title: 'Status', body: 'Sandbox Events are for testing and preview — nothing here is visible to real Teachers until an Event goes Active.' },
                { ids: ['tour-versions-btn', 'tour-versions-btn-mobile'], title: 'Versions', body: "Open this Event to manage its Versions (each year's actual run) and Ensembles (the choirs it produces)." }
            ];

            var activeSteps = [];
            var current = -1;
            var running = false;
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
</div>

<div>
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-1 text-sm text-zinc-500">
        <a href="{{ route('events.show', $version->event) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->event->name }}</a>
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        @if ($canConfigure)
            <a href="{{ route('events.versions.edit', $version) }}" wire:navigate class="hover:text-zinc-800 dark:hover:text-zinc-200">{{ $version->name }}</a>
        @else
            <span>{{ $version->name }}</span>
        @endif
        <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
        <span>Setup checklist</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
        <div>
            <flux:heading size="xl">Setup checklist</flux:heading>
            <flux:text size="sm" class="text-zinc-500 max-w-3xl">
                Every decision {{ $version->name }} needs, in the order you'll need them. Status updates on its own as you fill things in —
                use <span class="font-medium">Looks right</span> to confirm a setting you've checked and are happy to keep.
            </flux:text>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            @if ($canConfigure)
                <flux:button size="sm" variant="ghost" icon="question-mark-circle" href="{{ route('events.versions.setup-questions', $version) }}" wire:navigate>Setup questions</flux:button>
            @endif
        <flux:button id="tour-start" data-auto-start="{{ auth()->user()->dismissed_readiness_orientation_at === null ? '1' : '0' }}" size="sm" variant="ghost" icon="sparkles" type="button">Take a tour</flux:button>
        </div>
    </div>

    <x-readiness-card :version="$version" :summary="$summary" :on-checklist="true" class="mb-8" />

    @foreach ($phases as $group)
        @php
            $phase = $group['phase'];
            $phaseSummary = $summary['phases'][$phase->value];
            $due = $dueDates[$phase->value] ?? null;
        @endphp

        <section id="phase-{{ $phase->value }}" class="mb-10 scroll-mt-6">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 mb-3">
                <flux:heading size="lg">{{ $phase->label() }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500 tabular-nums">{{ $phaseSummary['done'] }}/{{ $phaseSummary['total'] }} done</flux:text>
                @if ($due !== null && $phaseSummary['blocking'] > 0)
                    <flux:badge size="sm" color="{{ $due->isPast() ? 'red' : ($due->diffInDays(now(), true) <= 14 ? 'amber' : 'zinc') }}">
                        Due by {{ $due->format('M j, Y') }}
                    </flux:badge>
                @endif
            </div>

            {{-- Cards below md: --}}
            <div class="md:hidden space-y-3">
                @foreach ($group['results'] as $result)
                    @php
                        $isTourRow = $result->item->key === $tourFirstKey;
                        $canEdit = $editable[$result->item->editor->value];
                    @endphp
                    <div wire:key="card-{{ $result->item->key }}" class="rounded-lg border p-4 border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <div class="flex items-start justify-between gap-3">
                            @if ($canEdit)
                                <a @if ($isTourRow) id="tour-decision-mobile" @endif href="{{ $result->url }}" wire:navigate class="font-medium text-zinc-900 dark:text-zinc-100 hover:underline">{{ $result->item->question }}</a>
                            @else
                                <button type="button" @if ($isTourRow) id="tour-decision-mobile" @endif wire:click="explainLocked('{{ $result->item->key }}')" class="text-left font-medium text-zinc-900 dark:text-zinc-100 hover:underline">{{ $result->item->question }}</button>
                            @endif
                            <flux:badge :id="$isTourRow ? 'tour-status-mobile' : null" size="sm" color="{{ $result->status->color() }}" class="shrink-0">{{ $result->status->label() }}</flux:badge>
                        </div>
                        <flux:text size="sm" class="text-zinc-500 mt-1">{{ $result->item->why }}</flux:text>
                        @if ($result->detail)
                            <flux:text :id="$isTourRow ? 'tour-current-mobile' : null" size="sm" class="mt-1 text-zinc-700 dark:text-zinc-300">{{ $result->detail }}</flux:text>
                        @endif
                        <div class="flex flex-wrap gap-2 mt-3">
                            @if ($canEdit)
                                <flux:button :id="$isTourRow ? 'tour-open-mobile' : null" size="xs" href="{{ $result->url }}" wire:navigate>Open</flux:button>
                            @else
                                <flux:button :id="$isTourRow ? 'tour-open-mobile' : null" size="xs" icon="lock-closed" wire:click="explainLocked('{{ $result->item->key }}')">Open</flux:button>
                            @endif
                            @if ($result->canAcknowledge)
                                <flux:button :id="$result->item->key === $tourAckKey ? 'tour-ack-mobile' : null" size="xs" variant="primary" icon="check" wire:click="acknowledge('{{ $result->item->key }}')">Looks right</flux:button>
                            @endif
                            @if (! $result->item->blocking)
                                <flux:badge size="sm" color="zinc">Optional</flux:badge>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Table at md:+ --}}
            <div class="hidden md:block">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column :id="$loop->first ? 'tour-col-status' : null" class="w-36">Status</flux:table.column>
                        <flux:table.column :id="$loop->first ? 'tour-col-decision' : null">Decision</flux:table.column>
                        <flux:table.column :id="$loop->first ? 'tour-col-current' : null" class="w-56">Current</flux:table.column>
                        <flux:table.column class="w-48"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($group['results'] as $result)
                            @php $canEdit = $editable[$result->item->editor->value]; @endphp
                            <flux:table.row wire:key="row-{{ $result->item->key }}">
                                <flux:table.cell class="align-top">
                                    <div class="flex flex-col items-start gap-1">
                                        <flux:badge size="sm" color="{{ $result->status->color() }}">{{ $result->status->label() }}</flux:badge>
                                        @if (! $result->item->blocking)
                                            <span class="text-xs text-zinc-500">Optional</span>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="align-top !whitespace-normal">
                                    @if ($canEdit)
                                        <a href="{{ $result->url }}" wire:navigate class="font-medium text-zinc-900 dark:text-zinc-100 hover:underline">{{ $result->item->question }}</a>
                                    @else
                                        <button type="button" wire:click="explainLocked('{{ $result->item->key }}')" class="text-left font-medium text-zinc-900 dark:text-zinc-100 hover:underline">{{ $result->item->question }}</button>
                                    @endif
                                    <div class="text-sm text-zinc-500 mt-0.5">{{ $result->item->why }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="align-top !whitespace-normal text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $result->detail ?? '—' }}
                                </flux:table.cell>
                                <flux:table.cell class="align-top">
                                    <div class="flex justify-end gap-2">
                                        @if ($result->canAcknowledge)
                                            <flux:button :id="$result->item->key === $tourAckKey ? 'tour-ack-desktop' : null" size="xs" variant="primary" icon="check" wire:click="acknowledge('{{ $result->item->key }}')">Looks right</flux:button>
                                        @endif
                                        @if ($canEdit)
                                            <flux:button :id="$result->item->key === $tourFirstKey ? 'tour-open-desktop' : null" size="xs" href="{{ $result->url }}" wire:navigate>Open</flux:button>
                                        @else
                                            <flux:button :id="$result->item->key === $tourFirstKey ? 'tour-open-desktop' : null" size="xs" icon="lock-closed" wire:click="explainLocked('{{ $result->item->key }}')">Open</flux:button>
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </section>
    @endforeach

    @if ($hiddenCount > 0 || $showNotApplicable)
        <flux:switch wire:model.live="showNotApplicable" label="Show {{ $hiddenCount }} {{ \Illuminate\Support\Str::plural('item', $hiddenCount) }} that don't apply to this event" />
    @endif

    {{-- "Who can change this" — shown instead of a 403 when the viewer's
         role can't edit the setting behind an item (ReadinessEditor). --}}
    <flux:modal name="readiness-locked" class="md:w-[28rem]">
        @if ($lockedItem)
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Ask an Event Manager</flux:heading>
                    <flux:text class="mt-1">
                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $lockedItem->question }}</span>
                    </flux:text>
                    <flux:text size="sm" class="mt-2 text-zinc-500">
                        This setting can only be changed or confirmed by {{ $lockedItem->editor->description() }}.
                        You can follow its progress here; reach out to them to change it.
                    </flux:text>
                </div>

                @if ($eventManagers->isNotEmpty())
                    <ul class="space-y-2">
                        @foreach ($eventManagers as $manager)
                            <li class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $manager->name }}</span>
                                <a href="mailto:{{ $manager->email }}" class="text-sky-700 dark:text-sky-400 hover:underline break-all">{{ $manager->email }}</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <flux:text size="sm" class="text-zinc-500">No Event Manager is assigned to this event yet.</flux:text>
                @endif

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="primary">Got it</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
    {{-- Spotlight tour — same hand-rolled engine as Events Show
         (resources/views/livewire/events/show.blade.php), minus tab
         switching. Steps resolve to whichever of their ids is visible, so the
         md:+ table and the below-md: cards each get their own anchors. A step
         with `legend` renders that <template>'s markup (real Flux badges) as
         its body. wire:ignore keeps Livewire re-renders (e.g. a "Looks right"
         click) from morphing the overlay's JS-managed state away. --}}
    <template id="tour-status-legend">
        <span class="block mb-2">How far along each decision is:</span>
        <span class="block space-y-1.5">
            @foreach ([
                [\App\Enums\ReadinessStatus::NotStarted, 'Required information hasn\'t been entered yet.'],
                [\App\Enums\ReadinessStatus::InProgress, 'Partly set up — e.g. a date window with a start but no end, or a room still short of judges.'],
                [\App\Enums\ReadinessStatus::NeedsReview, 'A usable value is already there (a default, or carried over from last year) but nobody has confirmed it.'],
                [\App\Enums\ReadinessStatus::Done, 'Set up and confirmed.'],
                [\App\Enums\ReadinessStatus::NotApplicable, 'Doesn\'t apply given your other choices — hidden unless you ask to see it.'],
            ] as [$legendStatus, $legendText])
                <span class="flex items-start gap-2">
                    <flux:badge size="sm" color="{{ $legendStatus->color() }}" class="shrink-0 w-28 justify-center">{{ $legendStatus->label() }}</flux:badge>
                    <span>{{ $legendText }}</span>
                </span>
            @endforeach
        </span>
        <span class="block mt-2"><span class="font-medium text-zinc-700 dark:text-zinc-200">Optional</span> items never hold anything up. Required items that are Not started or In progress keep the Version from moving from Sandbox to Active.</span>
    </template>

    <div wire:ignore>
        <button type="button" id="tour-dismiss-trigger" wire:click="dismissOrientation" class="hidden" aria-hidden="true" tabindex="-1"></button>

        <div id="tour-scrim" class="hidden fixed inset-0 z-[59]"></div>
        <div id="tour-cutout" class="hidden fixed z-[60] rounded-lg pointer-events-none transition-[top,left,width,height] duration-300 ease-out"></div>
        <div
            id="tour-card"
            class="hidden fixed z-[61] w-80 max-w-[calc(100vw-2rem)] bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-xl p-4 transition-[top,left] duration-300 ease-out"
            role="dialog" aria-modal="true" aria-labelledby="tour-title" aria-describedby="tour-body"
        >
            <div class="h-1 rounded-full bg-zinc-100 dark:bg-zinc-700 overflow-hidden mb-3">
                <div id="tour-progress" class="h-full bg-orange-600 dark:bg-orange-400 rounded-full transition-[width] duration-200"></div>
            </div>
            <div id="tour-stepcount" class="text-[11px] font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 mb-1"></div>
            <h3 id="tour-title" class="text-sm font-semibold text-zinc-800 dark:text-zinc-100 mb-1"></h3>
            <div id="tour-body" class="text-sm text-zinc-500 dark:text-zinc-400 mb-3"></div>
            <div class="flex items-center justify-between gap-2">
                <button type="button" id="tour-skip" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">Skip tour</button>
                <div class="flex gap-2">
                    <button type="button" id="tour-prev" class="text-sm font-medium px-3 py-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 disabled:opacity-40">Back</button>
                    <button type="button" id="tour-next" class="text-sm font-medium px-3 py-1.5 rounded-md border border-orange-600 bg-orange-600 text-white hover:brightness-110 dark:border-orange-400 dark:bg-orange-400 dark:text-zinc-900">Next</button>
                </div>
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
                { ids: ['tour-setup-progress'], title: 'Setup progress',
                  body: 'Your overall progress: how many decisions are done, how many are waiting for you to review, and how many required items are still open. The tiles below break it down by stage — click one to jump to that section.' },
                { ids: ['tour-phase-structure'], title: 'Event structure',
                  body: 'The shape of the event itself: its ensembles, the grades and voice parts each one accepts, the order ensembles are filled, and how judges score. Usually set once and reused every year.' },
                { ids: ['tour-phase-before_invitations'], title: 'Before inviting teachers',
                  body: 'Everything a teacher sees the moment they are invited: registration dates, fees, the student information you require, any obligations they must accept, and the invitations themselves.' },
                { ids: ['tour-phase-before_registration'], title: 'Before registration opens',
                  body: 'What teachers and students need to actually register: the application, the recordings to upload, online payments, and who runs registration. A Version can\u2019t move from Sandbox to Active until the required items here and above are finished.' },
                { ids: ['tour-phase-before_auditions'], title: 'Before auditions',
                  body: 'Adjudication and tab-room dates, the audition rooms, the judges assigned to each room, and who runs the tab room.' },
                { ids: ['tour-phase-before_results'], title: 'Before releasing results',
                  body: 'How scores are ranked, how students are placed into ensembles, and whether anonymized results are shared with every participating teacher.' },
                { ids: ['tour-col-status', 'tour-status-mobile'], title: 'Status', legend: 'tour-status-legend', wide: true },
                { ids: ['tour-col-decision', 'tour-decision-mobile'], title: 'Decision',
                  body: 'Each row is one decision, written as the question you\u2019re answering, with a line underneath on why it matters. Click the question to go straight to where it\u2019s set.' },
                { ids: ['tour-col-current', 'tour-current-mobile', 'tour-decision-mobile'], title: 'Current',
                  body: 'What\u2019s set right now — a date, a fee, a count, or a short summary such as \u201cRemote, PDF application\u201d. A dash means there\u2019s nothing to summarize yet.' },
                { ids: ['tour-ack-desktop', 'tour-ack-mobile', 'tour-col-status', 'tour-status-mobile'], title: 'Looks right',
                  body: 'Confirms a setting you\u2019ve checked and are happy with as it stands — a default you want to keep, or a value carried over from last year — and marks it Done. On an optional item with nothing set, it means \u201cwe don\u2019t use this.\u201d It only appears where confirming makes sense; required information still has to be entered. Saving a tab on the Configure page also counts as reviewing that tab\u2019s items.' },
                { ids: ['tour-open-desktop', 'tour-open-mobile'], title: 'Open',
                  body: 'Takes you to the exact page and tab where this decision is made. Make your change there and come back — the checklist updates itself. If your role can’t change a setting, Open shows a lock and tells you who can.' }
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
                if (step.legend) {
                    var tpl = document.getElementById(step.legend);
                    bodyEl.innerHTML = tpl ? tpl.innerHTML : '';
                } else {
                    bodyEl.textContent = step.body;
                }
                card.classList.toggle('w-80', !step.wide);
                card.classList.toggle('w-[26rem]', !!step.wide);
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

                var cardW = card.offsetWidth || 320;
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

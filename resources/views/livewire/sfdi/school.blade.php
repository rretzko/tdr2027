<div class="flex flex-col gap-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <flux:heading size="xl">School</flux:heading>
        <flux:button id="tour-start" data-auto-start="{{ auth()->user()->dismissed_sfdi_school_orientation_at === null ? '1' : '0' }}" size="sm" variant="ghost" icon="sparkles" type="button">Take a tour</flux:button>
    </div>

    @if ($currentSchool)
        <flux:callout icon="building-library">
            <flux:callout.text>
                Your active school is <strong>{{ $currentSchool->name }}</strong>.
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($selectedSchool === null)
        <flux:card id="tour-search-card" class="flex flex-col gap-4">
            <flux:subheading>{{ $currentSchool ? 'Switch to a different school' : 'Which school do you attend?' }}</flux:subheading>

            <div id="tour-state-zip" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="geostate_id" label="State">
                    <flux:select.option value="">Select a state...</flux:select.option>
                    @foreach (\App\Models\Geostate::orderBy('name')->get() as $geostate)
                        <flux:select.option value="{{ $geostate->id }}">{{ $geostate->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model.live.blur="zip_code"
                    label="Zip code (optional)"
                    placeholder="e.g. 08901"
                    inputmode="numeric"
                    maxlength="5"
                />
            </div>

            <flux:input id="tour-school-search" wire:model.live.debounce.300ms="school_search" label="School name" placeholder="Start typing to search..." />

            @if ($schoolSuggestions->isNotEmpty())
                <div class="flex flex-col gap-2">
                    @foreach ($schoolSuggestions as $match)
                        <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div>
                                <flux:text class="font-medium">{{ $match['school']->name }}</flux:text>
                                <flux:text size="sm" class="text-zinc-500">{{ $match['school']->city }}, {{ $match['school']->zip_code }}</flux:text>
                            </div>
                            <flux:button size="sm" wire:click="selectSchool({{ $match['school']->id }})">This is my school</flux:button>
                        </div>
                    @endforeach
                </div>
            @elseif ($school_search !== '' || $zip_code !== '')
                <flux:text class="text-zinc-500">
                    No matching schools found. If your school isn't listed yet, ask your teacher to add it via TheDirectorsRoom.com.
                </flux:text>
            @endif
        </flux:card>
    @else
        <flux:card class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <flux:subheading>Joining {{ $selectedSchool->name }}</flux:subheading>
                <flux:button size="sm" variant="ghost" wire:click="changeSchool">Choose a different school</flux:button>
            </div>

            <flux:select wire:model="grade" label="Grade">
                <flux:select.option value="">Select a grade...</flux:select.option>
                @foreach ($gradeOptions as $gradeOption)
                    <flux:select.option value="{{ $gradeOption }}">Grade {{ $gradeOption }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="grade" />

            <flux:separator />

            <flux:subheading>Your teacher(s) at this school</flux:subheading>

            @if ($availableTeachers->isEmpty())
                <flux:text class="text-zinc-500">
                    No verified teachers found at this school yet. Ask your teacher to complete their TheDirectorsRoom.com setup first.
                </flux:text>
            @else
                <div class="flex flex-col gap-4">
                    @foreach ($availableTeachers as $teacher)
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <flux:checkbox.group wire:model="teacherSubjects.{{ $teacher->id }}" label="{{ $teacher->user->first_name }} {{ $teacher->user->last_name }}">
                                @foreach ($subjectOptions as $subject)
                                    <flux:checkbox value="{{ $subject->value }}" label="{{ $subject->label() }}" />
                                @endforeach
                            </flux:checkbox.group>
                        </div>
                    @endforeach
                </div>
            @endif
            <flux:error name="teacherSubjects" />

            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="join">Join School</flux:button>
            </div>
        </flux:card>
    @endif

    {{-- Spotlight tour, same approach as Events\Index's (resources/views/livewire/events/index.blade.php).
         Only covers the initial search state, since the grade/teacher step
         only exists in the DOM once a school has been selected — the tour
         can't spotlight elements that don't exist yet, so its last step
         describes that next step in words instead. --}}
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
                { ids: ['tour-search-card'], title: 'Find your school', body: "This is the one page every student has to start with — you can't do anything else until you join a school and at least one teacher." },
                { ids: ['tour-state-zip'], title: 'Narrow the search', body: 'Pick your state, and optionally your zip code, to shorten the list.' },
                { ids: ['tour-school-search'], title: 'Search by name', body: "Start typing your school's name — matches appear below as you type. Don't see it? Ask your teacher to add it first; students can't create a new school." },
                { ids: ['tour-search-card'], title: 'Next: grade and teacher', body: 'Once you pick your school from the list, you\'ll choose your grade and check off your teacher(s) and subject(s), then tap Join School to finish.' }
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

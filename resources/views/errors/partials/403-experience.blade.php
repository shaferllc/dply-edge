@php
    $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
    $pathHint = '/' . ltrim(request()->path(), '/');
@endphp

<div
    class="forbidden-experience"
    x-data="{
        scans: 0,
        excusesTried: [],
        auditing: false,
        stamped: false,
        badgeX: 50,
        badgeY: 72,
        quip: @js(__('Wave your badge at the reader. We dare you.')),
        quips: @js([
            __('Wave your badge at the reader. We dare you.'),
            __('Still denied. Shocking.'),
            __('The vault remains unimpressed.'),
            __('Achievement unlocked: Professional gate appeaser.'),
        ]),
        excuses: @js([
            ['id' => 'root', 'label' => __('I\'m basically root'), 'reply' => __('Root doesn\'t browse the dashboard.')],
            ['id' => 'staging', 'label' => __('It worked in staging'), 'reply' => __('Staging lied to you again.')],
            ['id' => 'url', 'label' => __('I know the URL'), 'reply' => __('Knowing the URL ≠ authorization.')],
            ['id' => 'jira', 'label' => __('I\'ll open a JIRA'), 'reply' => __('Ticket #403: Access Denied. Status: Won\'t fix.')],
            ['id' => 'manager', 'label' => __('My manager said so'), 'reply' => __('Your manager didn\'t push to prod either.')],
            ['id' => 'sudo', 'label' => __('sudo let me in'), 'reply' => __('Nice try. This isn\'t your laptop.')],
        ]),
        auditSteps: @js([
            __('Connecting to policy engine…'),
            __('Resolving subject :user…'),
            __('Checking organization membership…'),
            __('Evaluating role bindings…'),
            __('Scanning for wildcard admin…'),
            __('GET :path'),
            __('Decision: 403 Forbidden'),
        ]),
        pathHint: @js($pathHint),
        userHint: @js(auth()->user()?->email ?? __('anonymous@dply.local')),
        logs: [],
        moveBadge() {
            this.badgeX = 18 + Math.random() * 64;
            this.badgeY = 58 + Math.random() * 28;
        },
        scanBadge() {
            this.stamped = true;
            this.scans = Math.min(99, this.scans + 17);
            const idx = Math.min(this.scans >= 51 ? 3 : Math.floor(this.scans / 17), this.quips.length - 1);
            this.quip = this.quips[idx] ?? this.quips[0];
            this.logs.unshift({
                tone: 'deny',
                line: this.quips[Math.min(Math.floor(this.scans / 17), 2)] ?? @js(__('ACCESS DENIED')),
            });
            if (this.logs.length > 8) {
                this.logs.pop();
            }
            window.setTimeout(() => { this.stamped = false; }, 900);
            if (this.scans < 51) {
                this.moveBadge();
            }
        },
        tryExcuse(excuse) {
            if (this.excusesTried.includes(excuse.id)) {
                this.logs.unshift({ tone: 'muted', line: @js(__('Already tried that excuse. The vault has notes.')) });
            } else {
                this.excusesTried.push(excuse.id);
                this.scans = Math.min(99, this.scans + 14);
                this.logs.unshift({ tone: 'excuse', line: excuse.reply });
            }
            if (this.logs.length > 8) {
                this.logs.pop();
            }
            if (this.excusesTried.length >= this.excuses.length) {
                this.quip = this.quips[3];
            }
        },
        async runAudit() {
            if (this.auditing) return;
            this.auditing = true;
            this.logs = [];
            for (const step of this.auditSteps) {
                const line = step
                    .replace(':path', this.pathHint)
                    .replace(':user', this.userHint);
                this.logs.push({ tone: line.includes('403') ? 'deny' : 'audit', line });
                await new Promise((r) => setTimeout(r, 380));
            }
            this.scans = 99;
            this.auditing = false;
        },
        excuseUsed(id) {
            return this.excusesTried.includes(id);
        },
    }"
    x-on:keydown.window="
        if ($event.key === 'Enter' && $event.target === $el.querySelector('[data-run-audit]')) runAudit();
    "
>
    <div class="relative mx-auto mb-8 max-w-lg" aria-hidden="true">
        <div class="forbidden-vault relative mx-auto aspect-[4/5] w-full max-w-[min(100%,20rem)] overflow-hidden rounded-3xl border border-brand-ink/12 bg-linear-to-b from-brand-sand/40 to-brand-sand/15 shadow-inner shadow-brand-forest/10 dark:from-brand-sand/15 dark:to-brand-cream/5">
            <div class="forbidden-scanlines pointer-events-none absolute inset-0 opacity-40"></div>
            <div class="forbidden-vault-glow pointer-events-none absolute inset-x-6 top-8 h-24 rounded-full bg-brand-gold/15 blur-2xl"></div>

            <div class="absolute inset-x-0 top-5 flex justify-center gap-2">
                <span class="forbidden-led forbidden-led--red h-2 w-2 rounded-full"></span>
                <span class="forbidden-led forbidden-led--amber h-2 w-2 rounded-full"></span>
                <span class="forbidden-led forbidden-led--green h-2 w-2 rounded-full opacity-30"></span>
            </div>

            <div class="absolute inset-x-8 top-14 rounded-2xl border border-brand-ink/10 bg-brand-cream/80 p-4 shadow-sm dark:bg-brand-sand/40/10">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl border border-brand-rust/25 bg-brand-rust/10 text-brand-rust dark:text-brand-gold">
                        <x-heroicon-o-lock-closed class="h-7 w-7" />
                    </div>
                    <div class="min-w-0 flex-1 text-left">
                        <p class="text-2xs font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Secure zone') }}</p>
                        <p class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $pathHint }}</p>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="mb-1 flex items-center justify-between text-2xs font-semibold uppercase tracking-widest text-brand-moss">
                        <span>{{ __('Persuasion level') }}</span>
                        <span x-text="`${scans}%`"></span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-brand-ink/8">
                        <div
                            class="forbidden-meter h-full rounded-full bg-linear-to-r from-brand-rust via-brand-gold to-brand-sage transition-all duration-500 ease-out"
                            :style="`width: ${scans}%`"
                        ></div>
                    </div>
                    <p class="mt-2 text-left text-xs text-brand-moss/80">{{ __('Cap intentionally set at 99%.') }}</p>
                </div>
            </div>

            <div class="absolute inset-x-10 bottom-16 rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/50 px-4 py-3 dark:bg-brand-sand/40/5">
                <p class="text-2xs font-semibold uppercase tracking-widest text-brand-moss">{{ __('Badge reader') }}</p>
                <div class="relative mt-3 h-12 rounded-lg bg-brand-ink/90">
                    <div class="forbidden-reader-beam absolute inset-y-1 left-2 w-8 rounded bg-brand-gold/35"></div>
                    <button
                        type="button"
                        class="forbidden-badge absolute z-10 flex h-10 w-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-md border border-brand-cream/30 bg-linear-to-br from-brand-forest to-brand-ink text-3xs font-bold uppercase tracking-wider text-brand-cream shadow-lg shadow-brand-ink/30 transition-transform hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold focus-visible:ring-offset-2 focus-visible:ring-offset-brand-cream dark:focus-visible:ring-offset-brand-cream"
                        :style="`left: ${badgeX}%; top: ${badgeY}%;`"
                        x-on:click="scanBadge()"
                        :aria-label="@js(__('Scan access badge'))"
                    >
                        <span class="forbidden-badge-shine pointer-events-none absolute inset-0 rounded-md"></span>
                        <span class="relative">DPLY</span>
                    </button>
                </div>
            </div>

            <div
                class="forbidden-stamp pointer-events-none absolute inset-0 flex items-center justify-center"
                x-show="stamped"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-150 rotate-12"
                x-transition:enter-end="opacity-100 scale-100 rotate-[-8deg]"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            >
                <span class="rounded-xl border-4 border-brand-rust px-5 py-2 text-2xl font-black uppercase tracking-[0.2em] text-brand-rust/90">
                    {{ __('Denied') }}
                </span>
            </div>
        </div>
    </div>

    <p class="forbidden-code mb-2 text-6xl font-bold tracking-tighter text-brand-ink sm:text-7xl">
        403
    </p>

    <p class="text-xl font-semibold text-brand-ink sm:text-2xl">
        {{ __('Access forbidden') }}
    </p>

    <p class="mx-auto mt-3 max-w-md text-base leading-relaxed text-brand-moss">
        {{ __('You do not have permission to access this resource. If you believe this is an error, please contact your administrator.') }}
    </p>

    <p class="mx-auto mt-4 min-h-[1.25rem] max-w-md text-sm font-medium text-brand-rust dark:text-brand-gold" x-text="quip"></p>

    @if (! empty($errorContext['server']))
        <p class="mx-auto mt-4 max-w-md text-sm text-brand-moss/80">
            {{ __('You do not have access to the server ":server".', ['server' => $errorContext['server']->name]) }}
        </p>
    @elseif (! empty($errorContext['site']))
        <p class="mx-auto mt-4 max-w-md text-sm text-brand-moss/80">
            {{ __('You do not have access to the site ":site".', ['site' => $errorContext['site']->domain]) }}
        </p>
    @elseif (! empty($errorContext['organization']))
        <p class="mx-auto mt-4 max-w-md text-sm text-brand-moss/80">
            {{ __('You are not a member of ":org".', ['org' => $errorContext['organization']->name]) }}
        </p>
    @endif

    <div class="mx-auto mt-8 max-w-xl text-left">
        <p class="mb-3 text-center text-xs font-semibold uppercase tracking-widest text-brand-moss">{{ __('Try an excuse') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-2">
            <template x-for="excuse in excuses" :key="excuse.id">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold focus-visible:ring-offset-2 focus-visible:ring-offset-brand-cream dark:focus-visible:ring-offset-brand-cream"
                    :class="excuseUsed(excuse.id)
                        ? 'border-brand-sage/40 bg-brand-sage/10 text-brand-moss line-through decoration-brand-moss/50'
                        : 'border-brand-ink/15 bg-white/70 text-brand-ink hover:border-brand-rust/30 hover:bg-brand-sand/40 dark:bg-brand-sand/10'"
                    x-on:click="tryExcuse(excuse)"
                    x-text="excuse.label"
                ></button>
            </template>
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
            <button
                type="button"
                data-run-audit
                class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-ink/10 transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-70"
                x-on:click="runAudit()"
                x-bind:disabled="auditing"
            >
                <span class="inline-flex" x-bind:class="auditing && 'animate-spin'">
                    <x-heroicon-o-shield-exclamation class="h-4 w-4" />
                </span>
                <span x-text="auditing ? @js(__('Auditing…')) : @js(__('Run policy audit'))"></span>
            </button>

            @auth
                <a
                    href="{{ route('dashboard') }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/20 px-5 py-2.5 text-sm font-medium text-brand-ink transition-colors hover:bg-brand-sand/50"
                >
                    <x-heroicon-o-squares-2x2 class="h-4 w-4" />
                    {{ __('Dashboard') }}
                </a>
            @else
                <a
                    href="{{ route('login') }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/20 px-5 py-2.5 text-sm font-medium text-brand-ink transition-colors hover:bg-brand-sand/50"
                >
                    <x-heroicon-o-arrow-right-end-on-rectangle class="h-4 w-4" />
                    {{ __('Log in') }}
                </a>
            @endauth

            <a
                href="{{ url('/') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/20 px-5 py-2.5 text-sm font-medium text-brand-ink transition-colors hover:bg-brand-sand/50"
            >
                <x-heroicon-o-home class="h-4 w-4" />
                {{ __('Home') }}
            </a>
        </div>

        <div
            class="forbidden-terminal mt-5 overflow-hidden rounded-xl border border-brand-ink/12 bg-brand-ink text-left shadow-inner"
            x-show="logs.length > 0"
            x-transition
            role="log"
            aria-live="polite"
            aria-relevant="additions"
        >
            <div class="flex items-center gap-2 border-b border-white/10 px-3 py-2">
                <span class="h-2.5 w-2.5 rounded-full bg-brand-rust/90"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-brand-gold/90"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-brand-sage/90"></span>
                <span class="ml-1 text-2xs font-semibold uppercase tracking-widest text-brand-mist">{{ __('Access log') }}</span>
            </div>
            <ul class="max-h-40 space-y-1 overflow-y-auto px-3 py-3 font-mono text-xs leading-relaxed text-brand-sand/90">
                <template x-for="(entry, index) in logs" :key="`${index}-${entry.line}`">
                    <li
                        class="forbidden-log-line flex gap-2"
                        :class="{
                            'text-brand-rust/95': entry.tone === 'deny',
                            'text-brand-gold/90': entry.tone === 'excuse',
                            'text-brand-mist/80': entry.tone === 'muted',
                        }"
                    >
                        <span class="shrink-0 text-brand-gold/70" x-text="entry.tone === 'deny' ? 'DENY' : entry.tone === 'excuse' ? 'NOTE' : 'AUDIT'"></span>
                        <span x-text="entry.line"></span>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</div>

<style>
    @keyframes forbidden-scan {
        0% { transform: translateY(-100%); }
        100% { transform: translateY(100%); }
    }

    @keyframes forbidden-led-blink {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.35; }
    }

    @keyframes forbidden-beam {
        0%, 100% { transform: translateX(0); opacity: 0.45; }
        50% { transform: translateX(calc(100% + 8rem)); opacity: 0.85; }
    }

    @keyframes forbidden-badge-shimmer {
        0%, 100% { opacity: 0.15; }
        50% { opacity: 0.45; }
    }

    @keyframes forbidden-log-in {
        from { opacity: 0; transform: translateX(-6px); }
        to { opacity: 1; transform: translateX(0); }
    }

    .forbidden-scanlines {
        background: repeating-linear-gradient(
            0deg,
            transparent,
            transparent 3px,
            color-mix(in srgb, var(--color-brand-ink) 4%, transparent) 3px,
            color-mix(in srgb, var(--color-brand-ink) 4%, transparent) 4px
        );
    }

    .forbidden-scanlines::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(
            180deg,
            transparent 0%,
            color-mix(in srgb, var(--color-brand-gold) 12%, transparent) 50%,
            transparent 100%
        );
        animation: forbidden-scan 3.8s linear infinite;
    }

    .forbidden-led--red {
        background: var(--color-brand-rust);
        animation: forbidden-led-blink 1.1s ease-in-out infinite;
    }

    .forbidden-led--amber {
        background: var(--color-brand-gold);
        animation: forbidden-led-blink 1.6s ease-in-out infinite 0.2s;
    }

    .forbidden-reader-beam {
        animation: forbidden-beam 2.4s ease-in-out infinite;
    }

    .forbidden-badge-shine {
        background: linear-gradient(120deg, transparent, rgb(255 255 255 / 0.35), transparent);
        animation: forbidden-badge-shimmer 2.2s ease-in-out infinite;
    }

    .forbidden-log-line {
        animation: forbidden-log-in 0.35s ease-out both;
    }

    @media (prefers-reduced-motion: reduce) {
        .forbidden-scanlines::after,
        .forbidden-led--red,
        .forbidden-led--amber,
        .forbidden-reader-beam,
        .forbidden-badge-shine,
        .forbidden-log-line {
            animation: none !important;
        }
    }
</style>

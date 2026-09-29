/**
 * The deploy pill: a stack at the bottom of every page showing the
 * organization's in-flight deploys and the step each is on
 * (resources/views/partials/deploy-pill.blade.php, @persist'd across
 * wire:navigate).
 *
 * Sources, in order of trust:
 *   - GET /deploy-pill — the deploys this member may see (app roles are
 *     enforced there). Loaded on page load, every 30s, and on reconnect.
 *   - `dply-deploy-progress` window events, forwarded from the org channel by
 *     bootstrap.js. They reach every member, so a push only moves a deploy
 *     this endpoint listed; an unknown one triggers a refetch instead.
 *
 * Toasts on finish go to whoever started the deploy, and to anyone on that
 * app's pages. Everyone else just sees the pill resolve.
 */
const FINAL = ['live', 'failed', 'cancelled', 'superseded'];
const SHOWN = 3;

export function registerDeployPill(Alpine) {
    Alpine.data('deployPill', (config = {}) => ({
        deploys: {},
        endpoint: config.endpoint,
        tailUrl: config.tailUrl,
        actionUrl: config.actionUrl,
        csrf: config.csrf,
        userId: String(config.userId ?? ''),
        siteId: '',
        minimized: false,
        expanded: null,
        showAll: false,
        confirmCancel: null,
        tail: { id: null, lines: [], offset: -1, timer: null },
        now: Date.now(),
        ready: false,

        init() {
            (config.initial ?? []).forEach((d) => this.put(d));
            this.ready = true;
            try {
                this.minimized = localStorage.getItem('dply.deployPill.minimized') === '1';
            } catch {}
            this.readContext();
            document.addEventListener('livewire:navigated', () => this.readContext());
            window.addEventListener('dply-deploy-progress', (e) => this.push(e.detail));
            // "Show progress" on a page, and a first deploy opening its own pill.
            window.addEventListener('dply-deploy-pill-open', (e) => this.open(e.detail?.id));
            setInterval(() => (this.now = Date.now()), 1000);
            setInterval(() => this.refresh(), 30000);
            this.bindReconnect();
        },

        // The page's app (from #dply-broadcast-context, re-rendered per page).
        readContext() {
            this.siteId = document.getElementById('dply-broadcast-context')?.dataset?.siteId?.trim() ?? '';
        },

        bindReconnect() {
            const connection = window.Echo?.connector?.pusher?.connection;
            if (connection?.bind) {
                connection.bind('connected', () => this.refresh());
            } else {
                setTimeout(() => this.bindReconnect(), 2000); // Echo loads as a module, sometimes after us.
            }
        },

        put(d) {
            const had = this.deploys[d.id];
            this.deploys[d.id] = { ...(had ?? {}), ...d, finishedAt: had?.finishedAt ?? null };
            // Pages showing this app (Overview's hero and map) refresh when a
            // deploy starts or ends; steps in between don't concern them.
            if (this.ready && (!had || (FINAL.includes(d.status) && !had.finishedAt))) {
                window.Livewire?.dispatch('edge-deploy-changed', { siteId: d.site_id, status: d.status });
            }
            if (FINAL.includes(d.status) && !had?.finishedAt && had) {
                this.finish(this.deploys[d.id]);
            }
        },

        async open(id) {
            if (!id) return;
            if (!this.deploys[id]) await this.refresh();
            const d = this.deploys[id];
            if (!d) return;
            this.minimized = false;
            if (this.expanded !== id) this.toggle(d);
        },

        push(d) {
            if (!d?.id) return;
            if (this.deploys[d.id]) {
                // can_deploy is per member: keep ours, not the broadcast's.
                this.put({ ...d, can_deploy: this.deploys[d.id].can_deploy });
            } else if (!FINAL.includes(d.status)) {
                this.refreshSoon();
            }
        },

        refreshSoon() {
            clearTimeout(this._refreshTimer);
            this._refreshTimer = setTimeout(() => this.refresh(), 400);
        },

        async refresh(extra = []) {
            const watching = [...Object.values(this.deploys).filter((d) => !d.finishedAt).map((d) => d.id), ...extra];
            const query = watching.map((id) => 'watch[]=' + encodeURIComponent(id)).join('&');
            try {
                const response = await fetch(this.endpoint + (query ? '?' + query : ''), { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const { deploys } = await response.json();
                deploys.forEach((d) => this.put(d));
            } catch {}
        },

        // Resolve a finished deploy: green and gone after 2s, red until
        // dismissed, cancelled just fades. Then the toast, for its audience.
        finish(d) {
            d.finishedAt = Date.now();
            if (this.tail.id === d.id) this.stopTail();
            // Pages showing "Deploying…" for it refresh (partials/edge/deployment-journey-card).
            window.dispatchEvent(new CustomEvent('dply-deploy-finished', { detail: { id: d.id, siteId: d.site_id, status: d.status } }));
            // The push leaves out the failure text; ask for it.
            if (d.status === 'failed' && !d.failure) this.refresh([d.id]);
            if (d.status !== 'failed') {
                setTimeout(() => this.forget(d.id), d.status === 'live' ? 2000 : 800);
            }
            if (d.status === 'cancelled' || d.status === 'superseded') return;
            const mine = d.triggered_by && d.triggered_by === this.userId;
            const here = this.siteId && (this.siteId === d.site_id || this.siteId === d.app_id);
            if (!mine && !here) return;
            const name = d.app_name + (d.preview ? ' ' + d.preview : '');
            window.dispatchEvent(new CustomEvent('toast', {
                detail: d.status === 'live'
                    ? { type: 'success', message: name + ' is live', url: d.live_url ?? d.app_url, newTab: !!d.live_url, linkLabel: 'Open', duration: 6000 }
                    : { type: 'error', message: name + ' failed' + (d.failed_step ? ' at ' + d.failed_step : ''), url: d.log_url, linkLabel: 'View log', duration: 10000 },
            }));
        },

        forget(id) {
            delete this.deploys[id];
            if (this.expanded === id) this.expanded = null;
        },

        // Previews show only on their own app's pages; this app's deploys first.
        get visible() {
            return Object.values(this.deploys)
                .filter((d) => !d.preview || (this.siteId && (this.siteId === d.site_id || this.siteId === d.app_id)))
                .sort((a, b) => {
                    const hereA = this.siteId === a.site_id || this.siteId === a.app_id;
                    const hereB = this.siteId === b.site_id || this.siteId === b.app_id;
                    if (hereA !== hereB) return hereA ? -1 : 1;
                    return String(a.started_at).localeCompare(String(b.started_at));
                });
        },

        get shown() {
            return this.showAll ? this.visible : this.visible.slice(0, SHOWN);
        },

        get hidden() {
            return Math.max(0, this.visible.length - SHOWN);
        },

        elapsed(d) {
            const start = Date.parse(d.started_at ?? '');
            if (Number.isNaN(start)) return '';
            const s = Math.max(0, Math.floor(((d.finishedAt ?? this.now) - start) / 1000));
            return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + String(s % 60).padStart(2, '0') + 's';
        },

        tone(d) {
            if (d.status === 'live') return 'live';
            if (d.status === 'failed') return 'failed';
            if (d.status === 'cancelled' || d.status === 'superseded') return 'cancelled';
            return 'running';
        },

        toggleMinimized() {
            this.minimized = !this.minimized;
            try {
                localStorage.setItem('dply.deployPill.minimized', this.minimized ? '1' : '0');
            } catch {}
        },

        toggle(d) {
            this.confirmCancel = null;
            if (this.expanded === d.id) {
                this.expanded = null;
                this.stopTail();
                return;
            }
            this.expanded = d.id;
            this.startTail(d);
        },

        // Only while a pill is open: the last lines of its log, every 2s.
        startTail(d) {
            this.stopTail();
            this.tail = { id: d.id, lines: [], offset: -1, timer: null };
            const tick = async () => {
                if (this.tail.id !== d.id) return;
                try {
                    const response = await fetch(this.tailUrl.replace('__ID__', d.id) + '?offset=' + this.tail.offset, { headers: { Accept: 'application/json' } });
                    if (response.ok) {
                        const body = await response.json();
                        if (this.tail.id !== d.id) return;
                        this.tail.offset = body.offset;
                        this.tail.lines = [...this.tail.lines, ...body.lines].slice(-8);
                    }
                } catch {}
                if (this.tail.id === d.id && !this.deploys[d.id]?.finishedAt) {
                    this.tail.timer = setTimeout(tick, 2000);
                }
            };
            tick();
        },

        stopTail() {
            clearTimeout(this.tail.timer);
            this.tail = { id: null, lines: [], offset: -1, timer: null };
        },

        async act(d, action) {
            this.confirmCancel = null;
            try {
                const response = await fetch(this.actionUrl.replace('__ID__', d.id).replace('__ACTION__', action), {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                });
                const body = await response.json().catch(() => ({}));
                if (!response.ok) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', message: body.message ?? 'That didn’t work.' } }));
                    return;
                }
                if (action === 'redeploy') {
                    this.forget(d.id);
                    if (body.deploy) this.put(body.deploy);
                }
                this.refresh();
            } catch {}
        },
    }));
}

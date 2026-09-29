// Alpine component for the edge Live requests tail (live-request-tail.blade.php).
// Lives in the bundle, not a @push'd <script>: the Traffic tab is rendered by a
// Livewire update, and a pushed stack never reaches the layout on a morph.
window.edgeLiveTail = function (opts) {
    return {
        siteId: opts.siteId,
        max: opts.max || 200,
        pollUrl: opts.pollUrl || '',
        status: 'connecting',
        rows: [],
        filter: '',
        methodFilter: '',
        statusFilter: '',
        paused: false,
        lastTickAt: null,
        seenKeys: new Set(),
        _channel: null,
        _pollTimer: null,
        _newTimers: new Map(),

        get filteredRows() {
            const needle = (this.filter || '').toLowerCase();
            const method = (this.methodFilter || '').toUpperCase();
            const bucket = this.statusFilter || '';

            return this.rows.filter((row) => {
                if (method && (row.method || '').toUpperCase() !== method) {
                    return false;
                }

                if (bucket) {
                    const code = Number(row.status || 0);
                    const ok = (
                        (bucket === '2xx' && code >= 200 && code < 300)
                        || (bucket === '3xx' && code >= 300 && code < 400)
                        || (bucket === '4xx' && code >= 400 && code < 500)
                        || (bucket === '5xx' && code >= 500 && code < 600)
                    );
                    if (! ok) return false;
                }

                if (! needle) return true;

                return (row.path || '').toLowerCase().includes(needle)
                    || String(row.status || '').includes(needle)
                    || (row.method || '').toLowerCase().includes(needle);
            });
        },

        connect() {
            [...(opts.seed || [])].reverse().forEach((payload) => this.ingestRow(payload, { highlight: false }));

            if (window.Echo) {
                try {
                    this._channel = window.Echo.private(`site.${this.siteId}`);
                    this._channel.listen('.edge.access-log', (payload) => this.onMessage(payload));
                    this.status = 'connected';
                } catch (err) {
                    console.error('[edge-live-tail] subscribe failed', err);
                    this.status = 'disconnected';
                }
            } else {
                this.status = 'disconnected';
                console.warn('[edge-live-tail] window.Echo is not initialized.');
            }

            this.startPoll();
        },

        disconnect() {
            try {
                if (window.Echo && this._channel) {
                    window.Echo.leave(`site.${this.siteId}`);
                }
            } catch (_err) {
                // ignore — page is going away anyway
            }

            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
        },

        startPoll() {
            if (! this.pollUrl || this._pollTimer) return;

            const tick = () => this.pollRecent();
            tick();
            this._pollTimer = setInterval(tick, 2500);
        },

        async pollRecent() {
            if (this.paused || ! this.pollUrl) return;

            try {
                const since = this.rows[0]?.occurred_at
                    || new Date(Date.now() - 60 * 60 * 1000).toISOString();
                const url = new URL(this.pollUrl, window.location.origin);
                url.searchParams.set('since', since);
                url.searchParams.set('limit', '50');

                const res = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (! res.ok) return;

                const body = await res.json();
                const data = Array.isArray(body.data) ? body.data : [];
                // API returns newest-first; ingest oldest-first so order stays stable.
                [...data].reverse().forEach((payload) => this.ingestRow(payload, { highlight: true }));
            } catch (_err) {
                // Poll is a fallback — ignore transient failures.
            }
        },

        onMessage(payload) {
            if (this.paused) return;
            this.ingestRow(payload, { highlight: true });
        },

        rowKey(payload) {
            return [
                payload.occurred_at || '',
                payload.method || '',
                payload.status || '',
                payload.path || '',
                payload.hostname || '',
            ].join('|');
        },

        ingestRow(payload, { highlight = true } = {}) {
            const key = this.rowKey(payload);
            if (this.seenKeys.has(key)) return;
            this.seenKeys.add(key);

            const row = {
                _id: `${payload.occurred_at || Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
                _timeLabel: this.formatTime(payload.occurred_at),
                _isNew: !! highlight,
                ...payload,
            };
            this.rows.unshift(row);
            if (this.rows.length > this.max) {
                const dropped = this.rows.splice(this.max);
                dropped.forEach((d) => this.seenKeys.delete(this.rowKey(d)));
            }

            if (highlight) {
                const timer = setTimeout(() => {
                    row._isNew = false;
                    this._newTimers.delete(row._id);
                }, 1500);
                this._newTimers.set(row._id, timer);
            }

            this.lastTickAt = Date.now();
        },

        formatTime(iso) {
            if (! iso) return '—';
            try {
                const d = new Date(iso);
                return d.toLocaleTimeString([], { hour12: false }) + '.' + String(d.getMilliseconds()).padStart(3, '0');
            } catch (_err) {
                return iso;
            }
        },
    };
};

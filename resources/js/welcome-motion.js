// Homepage motion: replays the hero deploy trace, runs a small live stage inside
// each WHAT_RUNS_HERE card, and walks the HOW_IT_WORKS steps. The Blade markup is
// the still frame it starts from; reduced-motion and no-JS readers keep it.

if (! window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const rand = (a, b) => a + Math.random() * (b - a);
    const color = (name) => getComputedStyle(document.documentElement).getPropertyValue(`--color-edge-${name}`).trim();

    // Loops only do work while their element is on screen.
    const onScreen = new WeakMap();
    const io = new IntersectionObserver((entries) => entries.forEach((e) => onScreen.set(e.target, e.isIntersecting)));
    const watch = (el) => { onScreen.set(el, false); io.observe(el); };
    const whenVisible = async (el) => { while (! onScreen.get(el)) await sleep(400); };

    const fitCanvas = (c) => {
        const dpr = window.devicePixelRatio || 1, w = c.clientWidth, h = c.clientHeight;
        if (c.width !== Math.round(w * dpr)) { c.width = Math.round(w * dpr); c.height = Math.round(h * dpr); }
        const ctx = c.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        return [ctx, w, h];
    };

    /* ---------- hero: replay the deploy trace ---------- */
    const trace = document.querySelector('[data-motion="deploy"]');
    if (trace) {
        const steps = [...trace.querySelectorAll('[data-step]')];
        const tail = [...trace.querySelectorAll('[data-tail]')];
        const live = document.querySelector('[data-motion="deploy-live"]');
        const replay = document.querySelector('[data-motion="deploy-replay"]');
        const frames = '⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏';
        let run = 0;

        const play = async () => {
            const id = ++run;
            replay.hidden = true;
            live.textContent = '○ BUILDING';
            [...steps, ...tail].forEach((el) => { el.style.visibility = 'hidden'; });
            for (const el of steps) {
                const mark = el.querySelector('[data-mark]'), time = el.querySelector('[data-time]');
                const done = { mark: mark?.textContent, time: time?.textContent };
                el.style.visibility = '';
                if (! mark) { await sleep(500); continue; }
                let f = 0;
                const spin = setInterval(() => { mark.textContent = frames[f++ % frames.length]; }, 80);
                time.textContent = '…';
                await sleep(Math.min(1400, 300 + parseFloat(done.time) * 70));
                clearInterval(spin);
                if (id !== run) return;
                mark.textContent = done.mark;
                time.textContent = done.time;
            }
            tail.forEach((el) => { el.style.visibility = ''; });
            live.textContent = '● LIVE';
            replay.hidden = false;
        };

        replay.addEventListener('click', play);
        play();
    }

    /* ---------- SITES: previews per branch, then a rollback ---------- */
    const sites = document.querySelector('[data-motion="sites"]');
    if (sites) {
        watch(sites);
        const [main, preview, deploys] = sites.querySelectorAll('[data-row]');
        const pill = (row, text, tone) => {
            const p = row.querySelector('[data-pill]');
            p.textContent = text;
            p.style.color = { ok: color('lime'), warn: '#e2a06a', mute: color('faint') }[tone];
        };
        (async () => {
            for (;;) {
                await whenVisible(sites);
                pill(preview, 'building', 'warn'); await sleep(1800);
                pill(preview, 'preview', 'ok'); await sleep(1600);
                deploys.querySelector('[data-text]').textContent = 'v43 · v42 · v41';
                pill(deploys, 'v43 live', 'warn'); pill(main, 'errors ↑', 'warn'); await sleep(1600);
                deploys.style.borderColor = color('lime');
                pill(deploys, '↺ v42 live', 'ok'); pill(main, 'production', 'ok'); await sleep(2600);
                deploys.style.borderColor = '';
                deploys.querySelector('[data-text]').textContent = 'v42 · v41 · v40';
                pill(deploys, 'v42 live', 'mute'); await sleep(900);
            }
        })();
    }

    /* ---------- SERVER_APPS: traffic drives instances, down to zero ---------- */
    const apps = document.querySelector('[data-motion="apps"]');
    if (apps) {
        watch(apps);
        const canvas = apps.querySelector('canvas'), pods = [...apps.querySelectorAll('[data-pod]')];
        const rps = apps.querySelector('[data-rps]'), inst = apps.querySelector('[data-inst]');
        const N = 80, data = Array(N).fill(0);
        let t = 0;
        // A day squeezed into ~70s: asleep overnight, morning ramp, a lunchtime spike.
        const traffic = (t) => {
            const p = (t % 600) / 600;
            if (p > 0.85 || p < 0.08) return 0;
            const base = Math.max(0, Math.sin(p * Math.PI * 2 - Math.PI / 2) * 0.5 + 0.35);
            return Math.max(0, base + Math.exp(-((p - 0.55) ** 2) / 0.002) * 0.9 + rand(-0.04, 0.04));
        };
        for (let i = 0; i < N; i++) { data.shift(); data.push(traffic(++t)); }
        const draw = () => {
            const [ctx, w, h] = fitCanvas(canvas);
            const y = (v) => h - 2 - (Math.min(v, 1.3) / 1.3) * (h - 4);
            ctx.beginPath();
            data.forEach((v, i) => (i ? ctx.lineTo : ctx.moveTo).call(ctx, (i / (N - 1)) * w, y(v)));
            ctx.strokeStyle = color('lime'); ctx.lineWidth = 1.5; ctx.stroke();
            ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath();
            ctx.globalAlpha = 0.12; ctx.fillStyle = color('lime'); ctx.fill(); ctx.globalAlpha = 1;
        };
        const step = () => {
            if (! onScreen.get(apps)) return;
            data.shift(); const v = traffic(++t); data.push(v);
            const want = v === 0 ? 0 : Math.min(5, Math.max(1, Math.ceil(v * 4.2)));
            pods.forEach((p, i) => { p.style.background = i < want ? color('lime') : ''; });
            rps.textContent = Math.round(v * 840);
            inst.textContent = want ? `${want} running` : 'asleep';
            draw();
        };
        draw();
        setInterval(step, 120);
    }

    /* ---------- DATA: query console + point-in-time restore ---------- */
    const db = document.querySelector('[data-motion="data"]');
    if (db) {
        watch(db);
        const q = db.querySelector('[data-query]'), out = db.querySelector('[data-result]');
        const knob = db.querySelector('[data-knob]'), at = db.querySelector('[data-at]');
        const queries = [
            ['select status, count(*) from orders group by 1;', [['paid', '18,204'], ['refunded', '311'], ['pending', '97']]],
            ['select plan, count(*) from customers group by 1;', [['monthly', '6,842'], ['annual', '2,105'], ['trial', '488']]],
        ];
        (async () => {
            for (let n = 0; ; n++) {
                await whenVisible(db);
                const [sql, rows] = queries[n % queries.length];
                out.replaceChildren();
                for (let i = 1; i <= sql.length; i++) { q.textContent = sql.slice(0, i); await sleep(26); }
                await sleep(300);
                for (const [k, v] of rows) {
                    const li = document.createElement('li');
                    li.className = 'flex justify-between';
                    li.innerHTML = `<span></span><span class="tabular-nums text-edge-text"></span>`;
                    li.children[0].textContent = k; li.children[1].textContent = v;
                    out.appendChild(li);
                    await sleep(150);
                }
                await sleep(1500);
                knob.style.left = '38%'; at.textContent = 'restore to 14:02 yesterday';
                await sleep(2200);
                knob.style.left = '100%'; at.textContent = 'now';
                await sleep(1500);
            }
        })();
    }

    /* ---------- WORKERS: backlog in, workers scale, a failed-job alert ---------- */
    const workers = document.querySelector('[data-motion="workers"]');
    if (workers) {
        watch(workers);
        const canvas = workers.querySelector('canvas');
        const label = workers.querySelector('[data-workers]'), note = workers.querySelector('[data-note]');
        const lanes = 5;
        let jobs = [], active = 2, t = 0, alert = 0;
        const spawn = () => jobs.push({ x: -8, y: 0, lane: -1, state: 'queued', p: 0, fail: Math.random() < 0.04 });
        const frame = () => {
            requestAnimationFrame(frame);
            if (! onScreen.get(workers)) return;
            const [ctx, w, h] = fitCanvas(canvas);
            const lime = color('lime'), line = color('line'), text = color('dim');
            t++;
            if (Math.random() < (Math.sin(t / 160) > 0.3 ? 0.2 : 0.05)) spawn();
            const queued = jobs.filter((j) => j.state === 'queued');
            if (t % 40 === 0) active += Math.sign(Math.min(lanes, Math.max(1, Math.ceil(queued.length / 4))) - active);

            const qx = 4, qw = w * 0.42, wx = w * 0.52, ww = w * 0.48 - 4, lh = h / lanes;
            ctx.strokeStyle = line; ctx.lineWidth = 1;
            ctx.strokeRect(qx + 0.5, h / 2 - 10.5, qw, 21);
            for (let i = 0; i < lanes; i++) {
                ctx.setLineDash(i < active ? [] : [3, 3]);
                ctx.strokeStyle = i < active ? lime : line;
                ctx.strokeRect(wx + 0.5, i * lh + 2.5, ww - 1, lh - 5);
            }
            ctx.setLineDash([]);

            queued.forEach((j, i) => { j.x += (Math.max(qx + qw - 8 - i * 8, qx + 6) - j.x) * 0.15; j.y = h / 2; });
            const busy = new Set(jobs.filter((j) => j.state === 'move' || j.state === 'work').map((j) => j.lane));
            for (let l = 0; l < active; l++) {
                const j = ! busy.has(l) && jobs.find((j) => j.state === 'queued');
                if (j) { j.state = 'move'; j.lane = l; }
            }
            for (const j of jobs) {
                if (j.state === 'move') {
                    const tx = wx + 8, ty = j.lane * lh + lh / 2;
                    j.x += (tx - j.x) * 0.2; j.y += (ty - j.y) * 0.2;
                    if (Math.abs(tx - j.x) < 1) j.state = 'work';
                } else if (j.state === 'work') {
                    j.p += 0.02; j.x = wx + 8 + (ww - 16) * j.p;
                    if (j.p >= 1) { j.state = j.fail ? 'failed' : 'done'; if (j.fail) alert = 120; }
                } else if (j.state !== 'queued') {
                    j.x += 3; j.dead = j.x > w + 8;
                }
                ctx.beginPath(); ctx.arc(j.x, j.y, 3, 0, 7);
                ctx.fillStyle = j.state === 'failed' ? '#f07a62' : j.state === 'queued' ? text : lime;
                ctx.fill();
            }
            jobs = jobs.filter((j) => ! j.dead);
            label.textContent = `${queued.length} waiting · ${active}/${lanes} workers`;
            if (alert > 0) alert--;
            note.textContent = alert ? '! SendInvoiceEmail failed → #alerts' : 'scheduler · every minute';
            note.style.color = alert ? '#f07a62' : '';
        };
        for (let i = 0; i < 6; i++) spawn();
        frame();
    }

    /* ---------- HOW_IT_WORKS: walk the four steps ---------- */
    const how = [...document.querySelectorAll('[data-motion="step"]')];
    if (how.length) {
        let i = 0;
        setInterval(() => {
            how.forEach((el, k) => { el.style.background = k === i ? color('raise') : ''; });
            i = (i + 1) % how.length;
        }, 1800);
    }
}

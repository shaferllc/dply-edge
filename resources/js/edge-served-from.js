/**
 * The Traffic tab's "Served from" map: faint dots for Cloudflare's data
 * centres (EdgeColos), lit and pulsing for the ones that answered this site,
 * sized by their share of requests. Equirectangular, cropped to 72° N–56° S.
 */
export function registerEdgeServedFrom(Alpine) {
    Alpine.data('edgeServedFrom', (network = [], served = []) => ({
        start() {
            const canvas = this.$refs.map;
            const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
            const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            const max = Math.max(1, ...served.map((c) => c.requests));
            const at = (lat, lon, w, h) => [((lon + 180) / 360) * w, ((72 - lat) / 128) * h];

            const draw = (t) => {
                if (! canvas.isConnected) return;
                const dpr = devicePixelRatio || 1, w = canvas.clientWidth, h = canvas.clientHeight;
                if (canvas.width !== Math.round(w * dpr)) { canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr); }
                const ctx = canvas.getContext('2d');
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                ctx.clearRect(0, 0, w, h);
                const lit = css('--color-brand-sage') || '#53695f';
                const dim = css('--color-brand-mist') || '#6b6c62';

                ctx.fillStyle = dim;
                ctx.globalAlpha = 0.35;
                for (const [lat, lon] of network) {
                    const [x, y] = at(lat, lon, w, h);
                    ctx.fillRect(x - 1, y - 1, 2, 2);
                }
                ctx.globalAlpha = 1;

                for (const c of served) {
                    const [x, y] = at(c.lat, c.lon, w, h);
                    const r = 2.5 + Math.sqrt(c.requests / max) * 7;
                    const phase = reduce ? 0 : ((t / 1600 + (c.lon + 180) / 90) % 1);
                    ctx.strokeStyle = lit;
                    ctx.globalAlpha = reduce ? 0.4 : 1 - phase;
                    ctx.beginPath(); ctx.arc(x, y, r + phase * 14, 0, 7); ctx.stroke();
                    ctx.globalAlpha = 1;
                    ctx.fillStyle = lit;
                    ctx.beginPath(); ctx.arc(x, y, r, 0, 7); ctx.fill();
                }
                if (! reduce) requestAnimationFrame(draw);
            };
            requestAnimationFrame(draw);
        },
    }));
}

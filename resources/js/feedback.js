// ============================================================================
// Global feedback / bug-report capture.
//
// Two responsibilities:
//
//   1. A always-on console-error RING BUFFER so that when a user opens the
//      feedback sidebar to report a bug, we can attach the last N JS errors /
//      warnings / unhandled rejections that led up to it. Installed at import
//      time (before Alpine) so it catches errors from the very first paint.
//
//   2. An Alpine component (`dplyFeedbackSidebar`) that drives the slide-over:
//      page-screenshot capture (lazy-loaded modern-screenshot from CDN, with a
//      deny-by-default redaction filter so on-screen secrets / SSH output never
//      get rasterised), then hands the screenshot + console buffer + page
//      context to the Livewire component right before it submits.
//
// Screenshot capture is best-effort and NON-BLOCKING: a failed/slow capture
// must never stop the report from being filed.
// ============================================================================

const CONSOLE_MAX_ENTRIES = 50;
const CONSOLE_MAX_BYTES = 32 * 1024;
const SNAPDOM_CDN = 'https://cdn.jsdelivr.net/npm/@zumer/snapdom/dist/snapdom.mjs';
const SCREENSHOT_CDN = 'https://cdn.jsdelivr.net/npm/modern-screenshot@4/+esm';

// ---------------------------------------------------------------------------
// 1. Console ring buffer
// ---------------------------------------------------------------------------

const consoleBuffer = [];

function pushConsoleEntry(level, message) {
    try {
        consoleBuffer.push({
            level,
            message: String(message).slice(0, 2000),
            at: new Date().toISOString(),
        });
        while (consoleBuffer.length > CONSOLE_MAX_ENTRIES) {
            consoleBuffer.shift();
        }
    } catch (_) {
        /* never let instrumentation throw */
    }
}

function snapshotConsoleBuffer() {
    // Trim from the oldest end until the serialized payload fits the byte cap —
    // mirrors the server-side ceiling so we don't ship something it'll reject.
    let entries = consoleBuffer.slice();
    let serialized = JSON.stringify(entries);
    while (serialized.length > CONSOLE_MAX_BYTES && entries.length > 1) {
        entries = entries.slice(1);
        serialized = JSON.stringify(entries);
    }
    return entries;
}

export function installFeedbackConsoleBuffer() {
    if (window.__dplyFeedbackConsoleInstalled) {
        return;
    }
    window.__dplyFeedbackConsoleInstalled = true;

    const origError = console.error.bind(console);
    const origWarn = console.warn.bind(console);

    console.error = (...args) => {
        pushConsoleEntry('error', args.map(stringifyArg).join(' '));
        origError(...args);
    };
    console.warn = (...args) => {
        pushConsoleEntry('warn', args.map(stringifyArg).join(' '));
        origWarn(...args);
    };

    window.addEventListener('error', (e) => {
        const where = e.filename ? ` (${e.filename}:${e.lineno || 0})` : '';
        pushConsoleEntry('error', `${e.message || 'Uncaught error'}${where}`);
    });

    window.addEventListener('unhandledrejection', (e) => {
        if (e.defaultPrevented) {
            return;
        }
        const reason = e.reason && e.reason.message ? e.reason.message : e.reason;
        pushConsoleEntry('error', `Unhandled promise rejection: ${stringifyArg(reason)}`);
    });

    window.dplyFeedbackConsole = { snapshot: snapshotConsoleBuffer };
}

function stringifyArg(arg) {
    if (arg instanceof Error) {
        return `${arg.name}: ${arg.message}`;
    }
    if (typeof arg === 'object') {
        try {
            return JSON.stringify(arg);
        } catch (_) {
            return '[object]';
        }
    }
    return String(arg);
}

// ---------------------------------------------------------------------------
// 2. Screenshot capture (with redaction) + Alpine sidebar driver
// ---------------------------------------------------------------------------

let snapdomLib = null;
let screenshotLib = null;

async function loadSnapdom() {
    if (snapdomLib) {
        return snapdomLib;
    }
    // Loaded from CDN on demand (same pattern as Plotly) so it stays out of the
    // main bundle. @vite-ignore keeps Vite from trying to resolve the URL.
    snapdomLib = await import(/* @vite-ignore */ SNAPDOM_CDN);
    return snapdomLib;
}

async function loadScreenshotLib() {
    if (screenshotLib) {
        return screenshotLib;
    }
    screenshotLib = await import(/* @vite-ignore */ SCREENSHOT_CDN);
    return screenshotLib;
}

// Deny-by-default: anything tagged for redaction, plus known-sensitive surfaces
// (secret/env fields, the SSH console drawer, password inputs) is dropped from
// the capture entirely rather than risking a leak of customer secrets.
const REDACT_SELECTORS = [
    '[data-feedback-redact]',
    '[data-sensitive]',
    'input[type="password"]',
    '.dply-feedback-sidebar', // never screenshot the feedback panel itself
];

function shouldRedact(node) {
    if (!node || node.nodeType !== 1 || typeof node.matches !== 'function') {
        return false;
    }
    try {
        return REDACT_SELECTORS.some((sel) => node.matches(sel));
    } catch (_) {
        return false;
    }
}

function nextFrame() {
    return new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
}

async function waitForPaint() {
    // The #1 cause of a "background-only" capture is firing before web fonts
    // and layout have settled.
    if (document.fonts && document.fonts.ready) {
        try {
            await document.fonts.ready;
        } catch (_) {
            /* ignore */
        }
    }
    await nextFrame();
}

// Output bounds. We cap dimensions + quality so the uploaded screenshot stays
// small (~under a few hundred KB) — both to keep storage lean and, critically,
// so the payload never bloats the request. Tall pages are cropped, not scaled
// to nothing.
const MAX_W = 1600;
const MAX_H = 2400;
const WEBP_QUALITY = 0.72;

// Primary engine: snapdom. It rasterizes the live DOM by deeply inlining
// computed styles + fonts, which avoids the blank/background-only <foreignObject>
// renders that modern-screenshot and html-to-image hit on complex Tailwind pages.
async function captureCanvasWithSnapdom() {
    const { snapdom } = await loadSnapdom();
    const result = await snapdom(document.body, {
        scale: 1,
        backgroundColor: getComputedStyle(document.body).backgroundColor || '#ffffff',
        embedFonts: true,
        // Deny-by-default: drop sensitive/secret-bearing nodes from the capture.
        exclude: REDACT_SELECTORS,
        filter: (node) => !shouldRedact(node),
    });

    return result.toCanvas();
}

// Fallback engine, in case snapdom ever fails to load/run.
async function captureCanvasWithModernScreenshot() {
    const { domToCanvas } = await loadScreenshotLib();
    const options = {
        backgroundColor: getComputedStyle(document.body).backgroundColor || '#ffffff',
        filter: (node) => !shouldRedact(node),
    };

    // Warm-up pass primes the resource cache; the second pass is the keeper.
    await domToCanvas(document.body, options).catch(() => null);
    await nextFrame();

    return domToCanvas(document.body, options);
}

// Downscale to MAX_W and crop to MAX_H, then encode WebP — bounding the output
// size regardless of how large/tall the source page was.
function canvasToCappedWebpBlob(src) {
    const sw = src.width;
    const sh = src.height;
    if (!sw || !sh) {
        return Promise.resolve(null);
    }

    const ratio = Math.min(1, MAX_W / sw);
    const srcCropH = Math.min(sh, Math.round(MAX_H / ratio));
    const tw = Math.max(1, Math.round(sw * ratio));
    const th = Math.max(1, Math.round(srcCropH * ratio));

    const out = document.createElement('canvas');
    out.width = tw;
    out.height = th;
    const ctx = out.getContext('2d');
    ctx.drawImage(src, 0, 0, sw, srcCropH, 0, 0, tw, th);

    return new Promise((resolve) => {
        out.toBlob((blob) => resolve(blob), 'image/webp', WEBP_QUALITY);
    });
}

// Returns a capped WebP Blob of the current page (or null). We upload this as a
// streamed file rather than a base64 string so it never inflates the Livewire
// component snapshot (which is copied several times per request).
export async function captureFeedbackScreenshotBlob() {
    try {
        await waitForPaint();

        let canvas = null;
        try {
            canvas = await captureCanvasWithSnapdom();
        } catch (e) {
            console.warn('snapdom capture failed, falling back:', e);
        }
        if (!canvas) {
            canvas = await captureCanvasWithModernScreenshot();
        }
        if (!canvas) {
            return null;
        }

        return await canvasToCappedWebpBlob(canvas);
    } catch (e) {
        console.warn('Feedback screenshot capture failed (continuing without it):', e);
        return null;
    }
}

function collectPageContext() {
    return {
        url: window.location.href,
        path: window.location.pathname,
        title: document.title,
        user_agent: navigator.userAgent,
        language: navigator.language,
        viewport: { width: window.innerWidth, height: window.innerHeight },
        screen: { width: window.screen?.width, height: window.screen?.height },
        device_pixel_ratio: window.devicePixelRatio,
        app_version: window.__DPLY_VERSION__ || null,
        captured_at: new Date().toISOString(),
    };
}

export function registerFeedbackSidebar(Alpine) {
    Alpine.data('dplyFeedbackSidebar', () => ({
        // Prefer drawerOpen over `open` — bare `open` can resolve to window.open
        // if Alpine scope is briefly lost during Livewire morph.
        drawerOpen: false,
        includeScreenshot: true,
        busy: false,

        toggle() {
            this.drawerOpen = !this.drawerOpen;
            window.dispatchEvent(new CustomEvent(this.drawerOpen ? 'dply-feedback-opened' : 'dply-feedback-closed'));
        },
        close() {
            if (! this.drawerOpen) {
                return;
            }
            this.drawerOpen = false;
            window.dispatchEvent(new CustomEvent('dply-feedback-closed'));
        },

        async submitWithCapture() {
            if (this.busy) {
                return;
            }
            this.busy = true;
            try {
                if (this.includeScreenshot) {
                    const blob = await captureFeedbackScreenshotBlob();
                    if (blob) {
                        const file = new File([blob], 'screenshot.webp', { type: 'image/webp' });
                        // Streamed upload — keeps the (multi-100KB) image OUT of the
                        // component snapshot. Non-fatal: a failed upload still files
                        // the report, just without the screenshot.
                        try {
                            await new Promise((resolve, reject) => {
                                this.$wire.upload('screenshotUpload', file, () => resolve(), (e) => reject(e));
                            });
                        } catch (e) {
                            console.warn('Screenshot upload failed, filing report without it:', e);
                        }
                    }
                }

                // These are small (capped) — fine as deferred string properties.
                await this.$wire.set(
                    'consoleBuffer',
                    JSON.stringify(window.dplyFeedbackConsole?.snapshot() ?? []),
                    false,
                );
                await this.$wire.set('pageContext', JSON.stringify(collectPageContext()), false);

                await this.$wire.submit();
            } catch (e) {
                console.warn('Feedback submit failed:', e);
            } finally {
                this.busy = false;
            }
        },

        init() {
            // Livewire tells us when a report was filed OK so we can close + reset.
            this.$wire.on('feedback-submitted', () => {
                this.drawerOpen = false;
            });

            // Floating dock (and other chrome) opens the panel via this event.
            window.addEventListener('dply-open-feedback', () => {
                if (! this.drawerOpen) {
                    this.drawerOpen = true;
                    window.dispatchEvent(new CustomEvent('dply-feedback-opened'));
                }
            });
            window.addEventListener('dply-toggle-feedback', () => {
                this.toggle();
            });
        },
    }));
}

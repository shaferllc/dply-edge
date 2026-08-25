import './bootstrap';

import {
    dplyEnsureDocsProseStyles,
    registerDplyLazyAssetListeners,
} from './lazy-load.js';
import { registerDplyThemeListeners } from './theme.js';
import { registerDeployPipelineWorkspace } from './deploy-pipeline-dnd.js';
import { registerRealtimeConsole } from './realtime-console.js';
import {
    installFeedbackConsoleBuffer,
    registerFeedbackSidebar,
} from './feedback.js';
import { registerDplyTooltips } from './tooltip.js';
import { registerMarkdownEditor } from './markdown-editor.js';

window.dplyEnsureDocsProseStyles = dplyEnsureDocsProseStyles;

registerDplyLazyAssetListeners();
registerDplyThemeListeners();
// Styled hover tooltips for [data-tooltip] and for truncated [title] text.
registerDplyTooltips();

// Install the console-error ring buffer immediately so the global feedback
// sidebar can attach the errors that preceded a bug report.
installFeedbackConsoleBuffer();

/**
 * Livewire 3 ships Alpine; do not import `alpinejs` or call `Alpine.start()` here
 * (avoids "Detected multiple instances of Alpine running").
 *
 * @see https://livewire.laravel.com/docs/installation
 */
// z-[110] keeps toasts above open modals (modal panel is z-[100]) so error
// feedback stays visible while a modal is open — otherwise a validation toast
// renders behind the modal and the action looks like it did nothing.
const toastRegionClasses = {
    top_center:
        'fixed left-1/2 top-24 z-[110] flex w-full max-w-xl -translate-x-1/2 flex-col items-center gap-2 px-4',
    top_right: 'fixed top-24 right-4 z-[110] flex max-w-xl flex-col items-end gap-2 px-4',
    bottom_right: 'fixed bottom-4 right-4 z-[110] flex max-w-md flex-col gap-2',
    bottom_left: 'fixed bottom-4 left-4 z-[110] flex max-w-md flex-col gap-2',
};

document.addEventListener('alpine:init', () => {
    // Site header Deploy/Console/Sync drawers — store survives Livewire morph
    // (local x-data on DeployControl is lost during poll/lazy clone).
    if (! Alpine.store('deployControl')) {
        Alpine.store('deployControl', {
            deployDrawerOpen: false,
            syncDrawerOpen: false,
            openDeployDrawer() {
                this.deployDrawerOpen = true;
            },
            closeDeployDrawer() {
                this.deployDrawerOpen = false;
            },
            toggleDeployDrawer() {
                this.deployDrawerOpen = ! this.deployDrawerOpen;
            },
            openSyncDrawer() {
                this.syncDrawerOpen = true;
            },
            closeSyncDrawer() {
                this.syncDrawerOpen = false;
            },
            toggleSyncDrawer() {
                this.syncDrawerOpen = ! this.syncDrawerOpen;
            },
        });
    }

    window.Alpine.data('toastStore', (config = {}) => {
        const positionKey = config.position ?? 'bottom_right';
        const savedClass =
            toastRegionClasses[positionKey] ?? toastRegionClasses.bottom_right;

        return {
            regionClass: savedClass,
            savedRegionClass: savedClass,
            toasts: [],
            init() {
                window.addEventListener('toast', (e) => {
                    const pos = e.detail?.position;
                    if (pos && toastRegionClasses[pos]) {
                        this.regionClass = toastRegionClasses[pos];
                    } else {
                        this.regionClass = this.savedRegionClass;
                    }

                    const id = Date.now();
                    const message = e.detail?.message ?? 'Done';
                    const type = e.detail?.type ?? 'success';
                    this.toasts.push({ id, message, type });
                    setTimeout(() => {
                        this.toasts = this.toasts.filter((t) => t.id !== id);
                    }, 4000);
                });
            },
            remove(id) {
                this.toasts = this.toasts.filter((t) => t.id !== id);
            },
        };
    });

    registerDeployPipelineWorkspace(window.Alpine);
    registerFeedbackSidebar(window.Alpine);
    registerRealtimeConsole(window.Alpine);
    // Toolbar/shortcuts for Markdown textareas (server notes today). Pure DOM
    // work — no parser, so it stays in the main bundle rather than a lazy entry.
    registerMarkdownEditor(window.Alpine);
});

const plotlyCdnUrl = 'https://cdn.jsdelivr.net/npm/plotly.js-dist-min@3.4.0/plotly.min.js';

let plotlyLoader = null;

function loadPlotly() {
    if (window.Plotly) {
        return Promise.resolve(window.Plotly);
    }

    if (plotlyLoader) {
        return plotlyLoader;
    }

    plotlyLoader = new Promise((resolve, reject) => {
        const existing = document.querySelector('script[data-plotly-cdn="1"]');

        if (existing) {
            existing.addEventListener('load', () => resolve(window.Plotly), { once: true });
            existing.addEventListener('error', () => reject(new Error('Failed to load Plotly CDN script.')), { once: true });

            return;
        }

        const script = document.createElement('script');
        script.src = plotlyCdnUrl;
        script.async = true;
        script.dataset.plotlyCdn = '1';
        script.onload = () => resolve(window.Plotly);
        script.onerror = () => reject(new Error('Failed to load Plotly CDN script.'));
        document.head.appendChild(script);
    });

    return plotlyLoader;
}

function isDplyDocumentDark() {
    return document.documentElement.classList.contains('dark');
}

function buildDplyRegionMapTrace(points, selected, isDark) {
    const markerSelected = isDark ? '#38bdf8' : '#0284c7';
    const markerUnselected = isDark ? '#71717a' : '#475569';
    const textColor = isDark ? '#e4e4e7' : '#0f172a';
    const markerLine = isDark ? '#fafafa' : '#ffffff';

    return {
        type: 'scattergeo',
        mode: 'markers+text',
        lon: points.map((point) => point.lon),
        lat: points.map((point) => point.lat),
        text: points.map((point) => point.value),
        textposition: 'top center',
        hovertext: points.map((point) => `${point.label} (${point.value})`),
        hoverinfo: 'text',
        marker: {
            size: points.map((point) => point.value === selected ? 18 : 12),
            color: points.map((point) => (point.value === selected ? markerSelected : markerUnselected)),
            line: {
                color: markerLine,
                width: 2,
            },
        },
        textfont: {
            family: 'Inter, ui-sans-serif, system-ui, sans-serif',
            size: 11,
            color: textColor,
        },
        customdata: points.map((point) => point.value),
    };
}

function buildDplyRegionMapLayout(isDark) {
    const paper = isDark ? '#18181b' : '#ffffff';
    const land = isDark ? '#1e3a4a' : '#dbeafe';
    const ocean = isDark ? '#0c1220' : '#f8fafc';
    const country = isDark ? '#3f3f46' : '#cbd5e1';
    const coast = isDark ? '#52525b' : '#94a3b8';

    return {
        margin: { l: 0, r: 0, t: 0, b: 0 },
        paper_bgcolor: paper,
        plot_bgcolor: paper,
        geo: {
            scope: 'world',
            projection: { type: 'natural earth' },
            showland: true,
            landcolor: land,
            showocean: true,
            oceancolor: ocean,
            showcountries: true,
            countrycolor: country,
            coastlinecolor: coast,
            showcoastlines: true,
            bgcolor: paper,
        },
    };
}

async function renderDplyRegionMaps() {
    const mapElements = [...document.querySelectorAll('[data-region-map]')];

    if (mapElements.length === 0) {
        return;
    }

    const Plotly = await loadPlotly();
    const isDark = isDplyDocumentDark();

    mapElements.forEach((el) => {
        const rawPoints = el.dataset.regionPoints ?? '[]';
        const selected = el.dataset.selectedRegion ?? '';

        let points = [];
        try {
            points = JSON.parse(rawPoints);
        } catch {
            points = [];
        }

        if (!Array.isArray(points) || points.length === 0) {
            return;
        }

        const trace = buildDplyRegionMapTrace(points, selected, isDark);
        const layout = buildDplyRegionMapLayout(isDark);

        Plotly.react(el, [trace], layout, {
            responsive: true,
            displayModeBar: false,
        });

        if (el.dataset.regionMapBound !== '1') {
            el.on('plotly_click', (event) => {
                const value = event?.points?.[0]?.customdata;
                if (!value) {
                    return;
                }

                window.dispatchEvent(new CustomEvent('dply-region-selected', {
                    detail: { value },
                }));
            });
            el.dataset.regionMapBound = '1';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    renderDplyRegionMaps().catch(() => {});
});

// wire:navigate swaps the DOM without firing DOMContentLoaded — re-render any maps
// that landed in the new page so the create wizard's region map works after SPA nav.
document.addEventListener('livewire:navigated', () => {
    renderDplyRegionMaps().catch(() => {});
});

window.addEventListener('dply-theme-applied', () => {
    renderDplyRegionMaps().catch(() => {});
});

window.addEventListener('dply:region-map-open', () => {
    window.setTimeout(() => {
        renderDplyRegionMaps().catch(() => {});
        window.dispatchEvent(new Event('resize'));
    }, 50);
});

// ============================================================================
// Global "button busy" feedback for any element with `wire:click`.
//
// Why this exists: a Livewire round-trip is fast but not instant, and a typical
// dply action button (Test Caddy, Restart NGINX, Save config, …) shows no
// visual change between click and the network response. Operators end up
// clicking twice because they're unsure the first click registered.
//
// What it does: on click, mark the target element as busy (disabled + CSS
// hook), then clear that state on the next Livewire commit. Buttons that don't
// trigger a round-trip (e.g. Alpine-only `$dispatch('open-modal', …)`) are
// ignored because they don't have `wire:click`; the spinner only renders for
// elements that actually talk to the server.
//
// A safety timeout clears the busy class after 8 s so a misconfigured handler
// that never commits doesn't leave a button stuck.
// ============================================================================

const DPLY_BUTTON_BUSY_CLASS = 'dply-btn-busy';
const DPLY_BUTTON_BUSY_TIMEOUT_MS = 8000;

function dplyClearBusyButtons() {
    document
        .querySelectorAll(`[data-dply-busy="1"]`)
        .forEach((el) => {
            el.classList.remove(DPLY_BUTTON_BUSY_CLASS);
            el.removeAttribute('data-dply-busy');
            el.style.removeProperty('--dply-btn-spinner-color');
            // Only remove disabled if WE set it (data-dply-busy-set-disabled
            // marker) so we don't trample an explicitly disabled button.
            if (el.dataset.dplyBusySetDisabled === '1') {
                el.removeAttribute('disabled');
                delete el.dataset.dplyBusySetDisabled;
            }
        });
}

document.addEventListener(
    'click',
    (event) => {
        // Walk up from the click target until we hit something with a
        // wire:click* attribute (Livewire 3 uses the bare attribute name).
        let el = event.target;
        let trigger = null;
        while (el && el !== document.body) {
            if (el.nodeType === 1) {
                for (const attr of el.attributes) {
                    if (attr.name === 'wire:click' || attr.name.startsWith('wire:click.')) {
                        trigger = el;
                        break;
                    }
                }
                if (trigger) break;
            }
            el = el.parentElement;
        }
        if (! trigger) return;
        // Opt-out: callers can set data-skip-busy to disable the affordance
        // (e.g. tabs that re-render the page on click — a stuck-looking
        // button is worse than no spinner).
        if (trigger.dataset.skipBusy === '1' || trigger.dataset.skipBusy === 'true') return;
        if (trigger.dataset.dplyBusy === '1') return; // already busy
        if (trigger.disabled || trigger.getAttribute('aria-disabled') === 'true') return;

        // Capture the button's current text colour BEFORE we apply the busy
        // class (which sets color: transparent). The CSS spinner reads this
        // variable so a rose-tinted destructive button gets a rose spinner,
        // an emerald primary gets an emerald spinner, etc. Falls back to a
        // neutral ink if computed-style is unavailable.
        try {
            const computed = window.getComputedStyle(trigger).color;
            if (computed && computed !== 'rgba(0, 0, 0, 0)' && computed !== 'transparent') {
                trigger.style.setProperty('--dply-btn-spinner-color', computed);
            }
        } catch (_) { /* getComputedStyle can throw in detached trees — ignore */ }

        trigger.classList.add(DPLY_BUTTON_BUSY_CLASS);
        trigger.dataset.dplyBusy = '1';
        if (! trigger.hasAttribute('disabled')) {
            trigger.setAttribute('disabled', 'disabled');
            trigger.dataset.dplyBusySetDisabled = '1';
        }

        // Safety: if no commit ever fires, clear after a hard timeout.
        window.setTimeout(() => {
            if (trigger.dataset.dplyBusy === '1') {
                trigger.classList.remove(DPLY_BUTTON_BUSY_CLASS);
                delete trigger.dataset.dplyBusy;
                trigger.style.removeProperty('--dply-btn-spinner-color');
                if (trigger.dataset.dplyBusySetDisabled === '1') {
                    trigger.removeAttribute('disabled');
                    delete trigger.dataset.dplyBusySetDisabled;
                }
            }
        }, DPLY_BUTTON_BUSY_TIMEOUT_MS);
    },
    true, // capture phase: run before Livewire's own click handler kicks in
);

document.addEventListener('livewire:init', () => {
    if (! window.Livewire || typeof window.Livewire.hook !== 'function') return;

    // Clear busy state after every successful or failed commit. Multiple
    // commits in one round-trip just trigger this hook multiple times — the
    // selector finds nothing on subsequent calls, which is a no-op.
    window.Livewire.hook('commit', ({ succeed, fail }) => {
        if (typeof succeed === 'function') {
            succeed(() => dplyClearBusyButtons());
        }
        if (typeof fail === 'function') {
            fail(() => dplyClearBusyButtons());
        }
    });
});

// Navigations (wire:navigate links + redirects) replace the page DOM; any
// busy state from before the nav is now meaningless.
document.addEventListener('livewire:navigated', dplyClearBusyButtons);

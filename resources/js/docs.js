/**
 * Public docs (/docs): search (⌘K / Ctrl-K / "/"), mobile nav drawer,
 * copy buttons, "On this page" scroll-spy, and code highlighting.
 *
 * Highlighting uses the Lezer parsers CodeMirror already ships with the app
 * (no new dependency); the parsers load as a lazy chunk only when the page
 * has a fenced code block.
 */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

// ---------------------------------------------------------------- dialogs
function openDialog(dialog) {
    if (dialog && !dialog.open) dialog.showModal();
}

// Clicking the backdrop (the <dialog> itself, outside its panel) closes it.
$$('dialog[data-docs-dialog]').forEach((dialog) => {
    dialog.addEventListener('click', (e) => {
        if (e.target === dialog) dialog.close();
    });
    $$('[data-docs-close]', dialog).forEach((b) => b.addEventListener('click', () => dialog.close()));
});

$$('[data-docs-open-menu]').forEach((b) => b.addEventListener('click', () => openDialog($('#docs-menu'))));

// ---------------------------------------------------------------- search
const searchDialog = $('#docs-search');
const input = $('#docs-search-input');
const list = $('#docs-search-results');
let index = null;
let results = [];
let active = 0;

async function loadIndex() {
    if (index) return index;
    const res = await fetch(searchDialog.dataset.index, { headers: { Accept: 'application/json' } });
    index = res.ok ? await res.json() : [];
    return index;
}

function openSearch() {
    $('#docs-menu')?.close();
    openDialog(searchDialog);
    input.select();
    loadIndex().then(() => render());
}

$$('[data-docs-open-search]').forEach((b) => b.addEventListener('click', openSearch));

document.addEventListener('keydown', (e) => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;
    if (((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') || (e.key === '/' && !typing)) {
        e.preventDefault();
        searchDialog.open ? searchDialog.close() : openSearch();
    }
});

const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

// One pass over the raw text, escaping each piece, so a term can never match
// inside an entity or an earlier <mark>.
function mark(text, terms) {
    const re = new RegExp(`(${terms.map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`, 'gi');
    return text
        .split(re)
        .map((part, i) => (i % 2 ? `<mark>${escapeHtml(part)}</mark>` : escapeHtml(part)))
        .join('');
}

function snippet(text, term) {
    const i = text.toLowerCase().indexOf(term);
    if (i < 0) return '';
    const start = Math.max(0, i - 50);
    return (start > 0 ? '…' : '') + text.slice(start, i + 90).trim() + '…';
}

function search(query) {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);
    if (!terms.length) return [];
    const has = (s) => terms.every((t) => s.toLowerCase().includes(t));
    const out = [];

    for (const page of index) {
        const title = page.title.toLowerCase();
        let score = 0;
        if (has(page.title)) score = title.startsWith(terms[0]) ? 100 : 80;
        else if (has(page.description)) score = 40;
        else if (has(page.text)) score = 10;
        if (score) {
            out.push({ score, url: page.url, title: page.title, crumb: page.section, excerpt: page.description || snippet(page.text, terms[0]) });
        }
        for (const h of page.headings) {
            if (has(h.text)) {
                out.push({ score: 60, url: `${page.url}#${h.id}`, title: h.text, crumb: `${page.section} › ${page.title}`, excerpt: '' });
            }
        }
    }

    return out.sort((a, b) => b.score - a.score).slice(0, 12).map((r) => ({ ...r, terms }));
}

function render() {
    const q = input.value.trim();
    results = index ? search(q) : [];
    active = 0;

    if (!q) {
        list.innerHTML = `<li class="docs-search__empty">${escapeHtml(list.dataset.hint)}</li>`;
        return;
    }
    if (!results.length) {
        list.innerHTML = `<li class="docs-search__empty">No results for “${escapeHtml(q)}”</li>`;
        return;
    }

    list.innerHTML = results
        .map(
            (r, i) => `<li role="option" id="docs-result-${i}" aria-selected="${i === active}">
                <a href="${escapeHtml(r.url)}" class="docs-search__result">
                    <span class="docs-search__crumb">${escapeHtml(r.crumb)}</span>
                    <span class="docs-search__title">${mark(r.title, r.terms)}</span>
                    ${r.excerpt ? `<span class="docs-search__excerpt">${mark(r.excerpt, r.terms)}</span>` : ''}
                </a>
            </li>`,
        )
        .join('');
    highlightActive();
}

function highlightActive() {
    $$('[role="option"]', list).forEach((li, i) => li.setAttribute('aria-selected', String(i === active)));
    const el = $(`#docs-result-${active}`);
    input.setAttribute('aria-activedescendant', el ? el.id : '');
    el?.scrollIntoView({ block: 'nearest' });
}

input?.addEventListener('input', () => loadIndex().then(render));
input?.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!results.length) return;
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
        highlightActive();
    } else if (e.key === 'Enter' && results[active]) {
        e.preventDefault();
        searchDialog.close();
        window.location.href = results[active].url;
    }
});
// Same-page #heading results: close the dialog so the jump is visible.
list?.addEventListener('click', (e) => {
    if (e.target.closest('a')) searchDialog.close();
});

// ---------------------------------------------------------------- copy buttons
$$('[data-docs-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const code = button.closest('.docs-code')?.querySelector('pre code');
        const text = code ? code.innerText : button.dataset.docsCopy;
        try {
            await navigator.clipboard.writeText(text.replace(/\n$/, ''));
            button.dataset.copied = 'true';
            setTimeout(() => delete button.dataset.copied, 1500);
        } catch {
            /* clipboard blocked: nothing to do */
        }
    });
});

// ---------------------------------------------------------------- scroll-spy
const tocLinks = $$('[data-docs-toc] a');
if (tocLinks.length && 'IntersectionObserver' in window) {
    const byId = new Map(tocLinks.map((a) => [decodeURIComponent(a.hash.slice(1)), a]));
    const headings = [...byId.keys()].map((id) => document.getElementById(id)).filter(Boolean);
    const visible = new Set();
    const setActive = (id) => tocLinks.forEach((a) => a.toggleAttribute('data-active', a === byId.get(id)));

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((e) => (e.isIntersecting ? visible.add(e.target.id) : visible.delete(e.target.id)));
            const first = headings.find((h) => visible.has(h.id));
            if (first) setActive(first.id);
            else {
                // Between headings: the last one above the viewport stays active.
                const above = headings.filter((h) => h.getBoundingClientRect().top < 120).pop();
                if (above) setActive(above.id);
            }
        },
        { rootMargin: '-80px 0px -60% 0px' },
    );
    headings.forEach((h) => observer.observe(h));
}

// ---------------------------------------------------------------- highlighting
const blocks = $$('.docs-prose pre code[class*="language-"]');
if (blocks.length) {
    import('./docs-highlight.js').then(({ highlight }) => blocks.forEach(highlight));
}

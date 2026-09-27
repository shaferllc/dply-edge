// Only packages package.json declares (CodeMirror is already an app
// dependency); @lezer/highlight is a hard dependency of @codemirror/language.
import { classHighlighter, highlightCode } from '@lezer/highlight';
import { StreamLanguage } from '@codemirror/language';
import { phpLanguage } from '@codemirror/lang-php';
import { jsonLanguage } from '@codemirror/lang-json';
import { yamlLanguage } from '@codemirror/lang-yaml';
import { xmlLanguage } from '@codemirror/lang-xml';
import { javascript, typescript } from '@codemirror/legacy-modes/mode/javascript';
import { css } from '@codemirror/legacy-modes/mode/css';
import { html } from '@codemirror/legacy-modes/mode/xml';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { toml } from '@codemirror/legacy-modes/mode/toml';
import { properties } from '@codemirror/legacy-modes/mode/properties';
import { http } from '@codemirror/legacy-modes/mode/http';
import { dockerFile } from '@codemirror/legacy-modes/mode/dockerfile';
import { ruby } from '@codemirror/legacy-modes/mode/ruby';
import { python } from '@codemirror/legacy-modes/mode/python';
import { nginx } from '@codemirror/legacy-modes/mode/nginx';
import { standardSQL } from '@codemirror/legacy-modes/mode/sql';
import { diff } from '@codemirror/legacy-modes/mode/diff';

const stream = (mode) => StreamLanguage.define(mode).parser;

const parsers = {
    php: (code) => (code.trimStart().startsWith('<?') ? phpLanguage.parser : phpLanguage.parser.configure({ top: 'Program' })),
    js: () => stream(javascript),
    javascript: () => stream(javascript),
    mjs: () => stream(javascript),
    jsx: () => stream(javascript),
    ts: () => stream(typescript),
    typescript: () => stream(typescript),
    tsx: () => stream(typescript),
    json: () => jsonLanguage.parser,
    jsonc: () => stream(javascript),
    yaml: () => yamlLanguage.parser,
    yml: () => yamlLanguage.parser,
    xml: () => xmlLanguage.parser,
    html: () => stream(html),
    blade: () => stream(html),
    css: () => stream(css),
    bash: () => stream(shell),
    sh: () => stream(shell),
    shell: () => stream(shell),
    zsh: () => stream(shell),
    console: () => stream(shell),
    toml: () => stream(toml),
    env: () => stream(properties),
    dotenv: () => stream(properties),
    ini: () => stream(properties),
    http: () => stream(http),
    dockerfile: () => stream(dockerFile),
    docker: () => stream(dockerFile),
    ruby: () => stream(ruby),
    rb: () => stream(ruby),
    python: () => stream(python),
    py: () => stream(python),
    nginx: () => stream(nginx),
    sql: () => stream(standardSQL),
    diff: () => stream(diff),
};

export function highlight(el) {
    const lang = [...el.classList].find((c) => c.startsWith('language-'))?.slice(9).toLowerCase();
    const make = parsers[lang];
    if (!make) return;

    const code = el.textContent;
    const parser = make(code);
    const frag = document.createDocumentFragment();
    highlightCode(
        code,
        parser.parse(code),
        classHighlighter,
        (text, classes) => {
            if (!classes) return frag.append(text);
            const span = document.createElement('span');
            span.className = classes;
            span.textContent = text;
            frag.append(span);
        },
        () => frag.append('\n'),
    );
    el.replaceChildren(frag);
}

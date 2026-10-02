// Operator terminal (T-041): xterm.js on a WebSocket to the dply agent in a
// customer's container. The URL is a short-lived signed ticket made by the
// admin Resources page; everything typed is also logged by the agent into
// the app's own logs. xterm loads only when a terminal opens.
export function registerTerminal(Alpine) {
    Alpine.data('dplyTerminal', (url) => ({
        status: 'connecting',
        socket: null,
        term: null,
        async init() {
            const [{ Terminal }, { FitAddon }] = await Promise.all([import('@xterm/xterm'), import('@xterm/addon-fit')]);
            await import('@xterm/xterm/css/xterm.css');
            const fit = new FitAddon();
            this.term = new Terminal({
                cursorBlink: true,
                fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
                fontSize: 13,
                theme: { background: '#0b0d0a', foreground: '#e8ece3', cursor: '#c3f53c', selectionBackground: '#c3f53c55' },
            });
            this.term.loadAddon(fit);
            this.term.open(this.$refs.screen);
            fit.fit();

            const sized = new URL(url);
            sized.searchParams.set('cols', String(this.term.cols));
            sized.searchParams.set('rows', String(this.term.rows));
            this.socket = new WebSocket(sized.toString());
            this.socket.binaryType = 'arraybuffer';
            this.socket.onopen = () => {
                this.status = 'open';
                this.term.focus();
            };
            this.socket.onmessage = (e) => this.term.write(typeof e.data === 'string' ? e.data : new Uint8Array(e.data));
            this.socket.onclose = (e) => {
                this.status = 'closed';
                this.term.write(`\r\n\x1b[2m[session ended${e.reason ? ': ' + e.reason : ''}]\x1b[0m\r\n`);
            };
            this.term.onData((d) => this.send({ t: 'i', d }));
            this.term.onResize(({ cols, rows }) => this.send({ t: 'r', c: cols, r: rows }));
            this.resize = () => fit.fit();
            window.addEventListener('resize', this.resize);
        },
        send(message) {
            if (this.socket?.readyState === WebSocket.OPEN) this.socket.send(JSON.stringify(message));
        },
        destroy() {
            window.removeEventListener('resize', this.resize);
            this.socket?.close();
            this.term?.dispose();
        },
    }));
}

// Synthetic round trip for the realtime relay (dply:edge:check-realtime).
// Connects, subscribes, prints "subscribed", then waits for the event the PHP
// side publishes; prints "received" and exits 0 once data carries the nonce.
// Any error or the timeout exits 1 with the reason on stderr.
//
//   node monitor-roundtrip.mjs <wss-url> <channel> <event> <nonce> <timeout-ms>

const [url, channel, eventName, nonce, timeoutMs = '10000'] = process.argv.slice(2);
let stage = 'connecting';
const fail = (reason) => {
  process.stderr.write(`${reason}\n`);
  try { ws.close(); } catch {}
  process.exit(1);
};
const timer = setTimeout(() => fail(`timed out after ${timeoutMs}ms while ${stage}`), Number(timeoutMs));

const ws = new WebSocket(url);
ws.addEventListener('error', (e) => fail(`socket error while ${stage}: ${e.message || e.error?.message || e.error?.cause?.code || 'connect or handshake failed'}`));
ws.addEventListener('close', (e) => fail(`socket closed while ${stage} (code ${e.code}${e.reason ? `: ${e.reason}` : ''})`));
ws.addEventListener('message', ({ data }) => {
  let msg;
  try { msg = JSON.parse(data); } catch { return; }
  if (msg.event === 'pusher:ping') {
    ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
  } else if (msg.event === 'pusher:error') {
    fail(`pusher:error while ${stage}: ${typeof msg.data === 'string' ? msg.data : JSON.stringify(msg.data)}`);
  } else if (msg.event === 'pusher:connection_established') {
    stage = 'subscribing';
    ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel } }));
  } else if (msg.event === 'pusher_internal:subscription_succeeded' && msg.channel === channel) {
    stage = 'waiting for the published event';
    process.stdout.write('subscribed\n');
  } else if (msg.event === eventName && msg.channel === channel && String(msg.data).includes(nonce)) {
    clearTimeout(timer);
    process.stdout.write('received\n');
    ws.close();
    process.exit(0);
  }
});

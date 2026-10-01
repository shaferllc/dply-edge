// Run by TestRESTUpstashSDKLive: the published Upstash clients against the gateway.
// Exits non-zero with a message on the first mismatch.
import { Redis } from '@upstash/redis';
import { Ratelimit } from '@upstash/ratelimit';

const redis = new Redis({ url: process.env.REST_URL, token: process.env.REST_TOKEN });
const check = (what, got, want) => {
  if (JSON.stringify(got) !== JSON.stringify(want)) {
    console.error(`${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`);
    process.exit(1);
  }
};

await redis.del('sdk:k', 'sdk:n', 'sdk:obj', 'sdk:h');
check('set', await redis.set('sdk:k', 'héllo wörld'), 'OK');
check('get (base64 round trip, unicode)', await redis.get('sdk:k'), 'héllo wörld');
check('json value', (await redis.set('sdk:obj', { a: 1, b: [2, 3] }), await redis.get('sdk:obj')), { a: 1, b: [2, 3] });
check('incr', await redis.incrby('sdk:n', 5), 5);
check('hash', (await redis.hset('sdk:h', { f: 'v' }), await redis.hgetall('sdk:h')), { f: 'v' });

const p = redis.pipeline();
p.incr('sdk:n'); p.get('sdk:k'); p.exists('sdk:missing');
check('pipeline', await p.exec(), [6, 'héllo wörld', 0]);

const tx = redis.multi();
tx.incr('sdk:n'); tx.incr('sdk:n');
check('multi-exec', await tx.exec(), [7, 8]);

// Lua through the SDK's script helper: EVALSHA first, EVAL on NOSCRIPT.
const script = redis.createScript("return redis.call('INCRBY', KEYS[1], ARGV[1])");
check('script (evalsha → eval)', await script.exec(['sdk:n'], ['2']), 10);
check('eval', await redis.eval("return {KEYS[1], ARGV[1]}", ['sdk:x'], ['y']), ['sdk:x', 'y']);

// @upstash/ratelimit runs entirely on Lua scripts.
const limiter = new Ratelimit({ redis, limiter: Ratelimit.fixedWindow(3, '10 s'), prefix: 'sdk:rl' });
const results = [];
const who = `user-${Date.now()}`; // a fresh window each run
for (let i = 0; i < 4; i++) results.push((await limiter.limit(who)).success);
check('ratelimit fixed window', results, [true, true, true, false]);

// The SDK has no blocking commands; a raw request shows the gateway refuses them.
const raw = await fetch(process.env.REST_URL, {
  method: 'POST',
  headers: { Authorization: `Bearer ${process.env.REST_TOKEN}` },
  body: JSON.stringify(['BLPOP', 'sdk:list', '1']),
});
check('blocking command refused', [raw.status, /not supported over REST/.test((await raw.json()).error)], [400, true]);

await redis.del('sdk:k', 'sdk:n', 'sdk:obj', 'sdk:h');
console.log('ok');

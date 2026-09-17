import { Container, getContainer } from '@cloudflare/containers';

interface Env { APP: DurableObjectNamespace<PhpApp>; DATABASE_URL: string }

export class PhpApp extends Container<Env> {
  defaultPort = 8080;
  sleepAfter = '5m';
  constructor(ctx: DurableObjectState, env: Env) {
    super(ctx, env);
    this.envVars = { DATABASE_URL: env.DATABASE_URL };
  }
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const started = Date.now();
    const response = await getContainer(env.APP, 'default').fetch(request);
    const out = new Response(response.body, response);
    out.headers.set('X-Spike-Container-Ms', String(Date.now() - started));
    return out;
  },
};

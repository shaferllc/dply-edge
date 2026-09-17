interface Env { DISPATCHER: DispatchNamespace }

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    // Same call packages/edge-worker/src/handler.ts makes for SSR sites.
    return env.DISPATCHER.get('php-spike').fetch(request);
  },
};

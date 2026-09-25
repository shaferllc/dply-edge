import { handleRequest, type Env } from './handler';
import { handleQueue } from './queue';

export default {
  fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    return handleRequest(request, env, ctx);
  },
  queue(batch: MessageBatch<unknown>, env: Env): Promise<void> {
    return handleQueue(batch, env);
  },
};

export { handleRequest, type Env, type HostMapEntry } from './handler';

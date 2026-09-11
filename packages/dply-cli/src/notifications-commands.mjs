/**
 * `dply notifications` — channels, event routing, and test sends.
 *
 * dply's notification model is one matrix: a **channel** (Slack, email, webhook,
 * PagerDuty…) × an **event key** × the **site** it fires for. This command
 * shows every group that applies to a site in one place.
 *
 * Backed by /v1/notifications/* and /v1/sites/{id}/notifications, which write
 * through the same matrix the browser does.
 */
import { requireClient } from './server-context.mjs';
import { resolveAnySiteId } from './site-context.mjs';
import { isInteractive, pickRow } from './pick.mjs';
import { c, info, ok, printJson, printTable, warn } from './print.mjs';

const SUBCOMMANDS = ['channels', 'events', 'show', 'subscribe', 'unsubscribe', 'test', 'help'];

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function notificationsCommand(args, flags) {
  const sub = args[0]?.toLowerCase();

  if (sub === 'help' || flags.help || flags.h) {
    return printNotificationsHelp();
  }

  if (sub === 'channels') {
    return listChannels(flags);
  }

  if (sub === 'events') {
    return listEvents(flags);
  }

  if (sub === 'test') {
    return testChannel(args.slice(1), flags);
  }

  if (sub === 'subscribe' || sub === 'unsubscribe') {
    return changeSubscription(sub, args.slice(1), flags);
  }

  // `dply notifications [site]` — anything that is not a subcommand is the
  // site selector.
  const positional = sub === 'show' ? args[1] : args[0];

  return showSubscriptions(positional, flags);
}

/**
 * @param {Record<string, unknown>} flags
 */
async function listChannels(flags) {
  const client = await requireClient(flags);
  const channels = (await client.get('/notifications/channels'))?.data ?? [];

  if (flags.json) {
    printJson(channels);

    return 0;
  }

  if (channels.length === 0) {
    warn('No notification channels available to this token.');
    info(c.dim('Add one in the dply web app under Notifications, or ask an org admin.'));

    return 0;
  }

  printTable(
    ['id', 'label', 'type', 'destination', 'owner'],
    channels.map((channel) => ({
      id: channel.id,
      label: channel.label ?? '—',
      type: channel.type ?? '—',
      destination: channel.destination ?? '—',
      owner: channel.owner ?? '—',
    })),
  );

  info('');
  info(c.dim('Route one: `dply notifications subscribe <event> --channel <id>` · try it: `dply notifications test <id>`'));

  return 0;
}

/**
 * @param {Record<string, unknown>} flags
 */
async function listEvents(flags) {
  const client = await requireClient(flags);
  const subject = flags.subject ? `?subject=${encodeURIComponent(String(flags.subject))}` : '';
  const groups = (await client.get(`/notifications/events${subject}`))?.data ?? [];

  if (flags.json) {
    printJson(groups);

    return 0;
  }

  for (const group of groups) {
    info('');
    info(c.bold(group.label || group.key));
    for (const event of group.events ?? []) {
      info(`  ${c.cyan(event.key.padEnd(38))} ${c.dim(event.label)}`);
    }
  }

  info('');
  info(c.dim('Narrow: --subject site'));

  return 0;
}

/**
 * @param {string|undefined} positional
 * @param {Record<string, unknown>} flags
 */
async function showSubscriptions(positional, flags) {
  const { client, path, label } = await resolveSubject(positional, flags);
  const state = (await client.get(path))?.data ?? {};
  const groups = state.groups ?? [];
  const channels = state.channels ?? [];

  if (flags.json) {
    printJson(state);

    return 0;
  }

  info(c.bold(`Notifications for ${label}`));

  const routed = channels.filter((channel) => (channel.events ?? []).length > 0);

  if (routed.length === 0) {
    info('');
    info(c.dim('Nothing routed yet.'));
  }

  for (const channel of routed) {
    info('');
    info(`${c.cyan(channel.label ?? channel.id)} ${c.dim(`(${channel.type} · ${channel.id})`)}`);
    for (const key of channel.events) {
      info(`  ${c.green('✓')} ${key}`);
    }
  }

  info('');
  info(c.bold('Available events'));
  printTable(
    ['group', 'event', 'routed to'],
    groups.flatMap((group) => (group.events ?? []).map((event) => ({
      group: group.label || group.key,
      event: event.key,
      'routed to': channels
        .filter((channel) => (channel.events ?? []).includes(event.key))
        .map((channel) => channel.label ?? channel.id)
        .join(', ') || c.dim('—'),
    }))),
  );

  info('');
  info(c.dim('Route: `dply notifications subscribe <event> --channel <id>` · channels: `dply notifications channels`'));

  return 0;
}

/**
 * @param {'subscribe'|'unsubscribe'} action
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
async function changeSubscription(action, args, flags) {
  const events = args.filter((arg) => ! arg.startsWith('-'));

  if (events.length === 0) {
    throw cliError(`Which event? e.g. \`dply notifications ${action} site.uptime.down --channel <id>\`. List them with \`dply notifications events\`.`, 2);
  }

  const { client, path, label } = await resolveSubject(undefined, flags);
  const channel = await resolveChannel(client, flags);

  const response = await client.post(path, {
    channel,
    [action === 'subscribe' ? 'subscribe' : 'unsubscribe']: events,
  });

  const data = response?.data ?? {};
  const verb = action === 'subscribe' ? 'Routed' : 'Unrouted';

  ok(`${verb} ${events.join(', ')} ${action === 'subscribe' ? '→' : '↛'} ${data.channel ?? channel} on ${label}.`);
  info(c.dim(`That channel now fires for: ${(data.events ?? []).join(', ') || 'nothing'}`));

  return 0;
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
async function testChannel(args, flags) {
  const client = await requireClient(flags);
  const id = args[0] ?? (await resolveChannel(client, flags));

  const response = await client.post(`/notifications/channels/${encodeURIComponent(String(id))}/test`, {});

  ok(String(response?.data?.message ?? 'Test sent.'));

  return 0;
}

/**
 * @param {string|undefined} positional
 * @param {Record<string, unknown>} flags
 */
async function resolveSubject(positional, flags) {
  const client = await requireClient(flags);
  const siteId = await resolveAnySiteId(client, flags, positional);

  return {
    client,
    path: `/sites/${encodeURIComponent(siteId)}/notifications`,
    label: `site ${siteId}`,
  };
}

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {Record<string, unknown>} flags
 */
async function resolveChannel(client, flags) {
  const wanted = String(flags.channel ?? flags.c ?? '').trim();
  const channels = (await client.get('/notifications/channels'))?.data ?? [];

  if (channels.length === 0) {
    throw cliError('No notification channels available to this token. Add one in the web app first.', 2);
  }

  if (wanted) {
    const exact = channels.find((channel) => String(channel.id) === wanted);
    if (exact) {
      return String(exact.id);
    }

    const byLabel = channels.filter((channel) => String(channel.label ?? '').toLowerCase().includes(wanted.toLowerCase()));
    if (byLabel.length === 1) {
      return String(byLabel[0].id);
    }

    if (byLabel.length === 0) {
      throw cliError(`No channel matched "${wanted}". Run \`dply notifications channels\`.`, 2);
    }
  }

  const picked = await pickRow(wanted ? channels.filter((channel) => String(channel.label ?? '').toLowerCase().includes(wanted.toLowerCase())) : channels, {
    title: 'Which channel?',
    label: (channel) => String(channel.label ?? channel.id),
    hint: (channel) => [channel.type, channel.destination].filter(Boolean).join(' · '),
  });

  if (! picked?.id) {
    throw cliError('Which channel? Pass --channel <id> (see `dply notifications channels`).', 2);
  }

  return String(picked.id);
}

function printNotificationsHelp() {
  info(`${c.bold('dply notifications')} — channels and event routing`);
  info('');
  info(`  ${'notifications [site]'.padEnd(34)} ${c.dim('What fires for a site, and where it goes')}`);
  info(`  ${'notifications channels'.padEnd(34)} ${c.dim('Channels this token can route to')}`);
  info(`  ${'notifications events [--subject site]'.padEnd(34)} ${c.dim('The event catalog')}`);
  info(`  ${'notifications subscribe <event…>'.padEnd(34)} ${c.dim('Route events to --channel <id> (notifications.write)')}`);
  info(`  ${'notifications unsubscribe <event…>'.padEnd(34)} ${c.dim('Stop routing them')}`);
  info(`  ${'notifications test <channel>'.padEnd(34)} ${c.dim('Send the channel a test message')}`);
  info('');
  info(c.dim('Reading needs notifications.read; changing routing or testing needs notifications.write.'));

  return 0;
}

/**
 * @param {string} message
 * @param {number} [exitCode]
 */
function cliError(message, exitCode = 1) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}

export const NOTIFICATIONS_SUBCOMMANDS = SUBCOMMANDS;
export const __testing = { isInteractive, resolveChannel };

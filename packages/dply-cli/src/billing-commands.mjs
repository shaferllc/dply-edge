import { requireClient } from './server-context.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function billingCommand(args, flags) {
  const sub = args[0];

  if (!sub || sub === '--help' || sub === '-h' || sub === 'help') {
    return printBillingHelp();
  }

  switch (sub) {
    case 'show':
      return billingShow(flags);
    case 'breakdown':
      return billingBreakdown(flags);
    case 'invoices':
      return billingInvoices(flags);
    default:
      throw cliError(`Unknown billing command: ${sub}. Run \`dply billing help\`.`, 2);
  }
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function billingShow(flags) {
  const client = await requireClient(flags);
  const data = (await client.get('/billing'))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return;
  }

  const summary = data.summary ?? {};
  const plan = data.plan ?? {};
  const counts = data.counts ?? {};
  const subscription = data.subscription ?? {};

  info(c.bold('Billing'));
  printKeyValues([
    ['Plan', plan.label ?? '—'],
    ['Monthly estimate', formatMoney(data.monthly_total_cents)],
    ['Managed products', formatMoney(data.managed_subtotal_cents)],
    ['Billing interval', summary.interval ?? subscription.interval ?? '—'],
    ['Subscribed', summary.subscribed ? 'yes' : 'no'],
    ['Stripe status', summary.stripe_status ?? subscription.status ?? '—'],
    ['Next invoice', summary.next_invoice_at ?? '—'],
  ]);

  info('');
  info(c.bold('Fleet counts (billable)'));
  printKeyValues([
    ['Edge sites', String(counts.edge ?? 0)],
  ]);

  if (data.is_free) {
    info('');
    info(c.dim('No subscription required this cycle (free plan, no managed usage).'));
  }

  info('');
  info(c.dim('Details: dply billing breakdown · dply billing invoices'));

  return 0;
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function billingBreakdown(flags) {
  const client = await requireClient(flags);
  const data = (await client.get('/billing/breakdown'))?.data ?? {};

  if (flags.json) {
    printJson(data);

    return;
  }

  const categories = data.categories ?? [];
  const lineItems = data.line_items ?? [];

  info(c.bold('Estimated monthly total'));
  ok(formatMoney(data.monthly_total_cents));

  if (categories.length > 0) {
    info('');
    info(c.bold('Categories'));
    printTable(
      ['Category', 'Amount'],
      categories.map((row) => [row.label ?? row.key ?? '—', formatMoney(row.cents)]),
    );
  }

  if (lineItems.length > 0) {
    info('');
    info(c.bold('Line items'));
    printTable(
      ['Item', 'Qty', 'Unit', 'Line total'],
      lineItems.map((row) => [
        row.detail ? `${row.label} (${row.detail})` : row.label ?? '—',
        String(row.quantity ?? 1),
        formatMoney(row.unit_cents),
        formatMoney(row.line_cents),
      ]),
    );
  }

  if (categories.length === 0 && lineItems.length === 0) {
    warn('No billable line items this cycle.');
  }

  return 0;
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function billingInvoices(flags) {
  const client = await requireClient(flags);
  const rows = (await client.get('/billing/invoices'))?.data?.invoices ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    warn('No Stripe invoices on file (org may be on the free zone or not linked to Stripe yet).');

    return;
  }

  printTable(
    ['Date', 'Number', 'Total', 'Status', 'Paid'],
    rows.map((row) => [
      row.date ?? '—',
      row.number ?? row.id ?? '—',
      formatMoney(row.total_cents),
      row.status ?? '—',
      row.paid ? 'yes' : 'no',
    ]),
  );

  return 0;
}

function printBillingHelp() {
  info(`${c.bold('dply billing')} — plan estimates and invoices (org admin)`);
  info('');
  info(`  ${'show'.padEnd(12)} ${c.dim('Plan, monthly estimate, subscription status')}`);
  info(`  ${'breakdown'.padEnd(12)} ${c.dim('Category + line-item estimate')}`);
  info(`  ${'invoices'.padEnd(12)} ${c.dim('Recent Stripe invoices')}`);
  info('');
  info(c.dim('Requires billing.read scope and org admin role.'));

  return 0;
}

/**
 * @param {number | null | undefined} cents
 */
function formatMoney(cents) {
  if (cents === null || cents === undefined || Number.isNaN(Number(cents))) {
    return '—';
  }

  const value = Number(cents) / 100;

  return `$${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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

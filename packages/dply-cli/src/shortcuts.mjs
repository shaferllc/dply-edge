/** Single-token shortcuts → argv prefix (rest appended when present). */
const SINGLE_TOKEN = {
  r: ['refresh'],
  refresh: ['refresh'],
  deploy: ['deploy'],
  sites: ['sites'],
  me: ['whoami'],
  who: ['whoami'],
  orgs: ['account', 'orgs'],
  bill: ['billing', 'show'],
  billing: ['billing', 'show'],
  login: ['login'],
  logout: ['logout'],
  menu: ['menu'],
  m: ['menu'],
};

/**
 * Extra lines for tab completion (shell + `dply ls shortcuts`).
 *
 * @returns {string[]}
 */
export function shortcutCommandLines() {
  return ['me', 'who', 'orgs', 'bill', 'r', 'deploy', 'sites'];
}

/**
 * Expand friendly shortcuts into canonical argv before routing.
 *
 * @param {string[]} argv
 * @returns {string[]}
 */
export function expandArgv(argv) {
  if (argv.length === 0) {
    return argv;
  }

  const [first, ...rest] = argv;

  // `dply edge:status` — a colon is just a space. One rule here means every
  // route that already exists gets a `ns:sub` form for free.
  if (first.includes(':') && ! first.startsWith('-')) {
    return expandArgv([...first.split(':').filter(Boolean), ...rest]);
  }

  const key = first.toLowerCase();

  if (rest.length === 0 && SINGLE_TOKEN[key]) {
    return [...SINGLE_TOKEN[key]];
  }

  if (key === 'account' && rest.length === 0) {
    return ['account', 'show'];
  }

  return argv;
}

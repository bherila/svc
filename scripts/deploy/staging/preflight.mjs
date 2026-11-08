// Staging preflight: names every missing or unsafe STAGING_* setting, then fails.
// Inputs are the environment's STAGING_* variables and secrets passed as plain env
// vars by the workflow. Values are never printed, only names and the rule broken.
import process from 'node:process'
import { pathToFileURL } from 'node:url'

const required = [
  ['STAGING_DEPLOY_ENABLED', 'var', 'must be exactly "true" (the owner switch)'],
  ['STAGING_DEPLOY_DIR', 'var', 'directory name under the SSH user home, e.g. svc-staging'],
  ['STAGING_ENV_SOURCE', 'var', 'private env file on the host, .config/<name>/deployment.env'],
  ['STAGING_APP_URL', 'var', 'https origin of the staging site'],
  ['STAGING_DB_HOST', 'var', 'database host'],
  ['STAGING_DB_NAME', 'var', 'database name (its own schema, never the production one)'],
  ['STAGING_DB_USER', 'var', 'database user'],
  ['STAGING_MAIL_MAILER', 'var', '"log" or "smtp" (a sink such as Mailpit)'],
  ['STAGING_STRIPE_PUBLISHABLE_KEY', 'var', 'Stripe TEST publishable key, pk_test_...'],
  ['STAGING_OAUTH_PROVIDER_URL', 'var', 'identity provider base URL'],
  ['STAGING_OAUTH_CLIENT_ID', 'var', 'staging OAuth client id'],
  ['STAGING_OAUTH_REDIRECT_URI', 'var', 'https://<staging-host>/oauth/callback'],
  ['STAGING_SSH_HOST', 'secret', 'SSH host'],
  ['STAGING_SSH_USERNAME', 'secret', 'SSH user'],
  ['STAGING_SSH_PRIVATE_KEY', 'secret', 'deployment key for this host only'],
  ['STAGING_SSH_KNOWN_HOSTS', 'secret', 'pinned host key line(s)'],
  ['STAGING_APP_KEY', 'secret', 'its own APP_KEY, base64:... (php artisan key:generate --show)'],
  ['STAGING_DB_PASSWORD', 'secret', 'database password'],
  ['STAGING_STRIPE_SECRET_KEY', 'secret', 'Stripe TEST secret key, sk_test_...'],
  ['STAGING_STRIPE_WEBHOOK_SECRET', 'secret', 'signing secret of the staging webhook endpoint, whsec_...'],
  ['STAGING_OAUTH_CLIENT_SECRET', 'secret', 'staging OAuth client secret'],
]

const smtpOnly = [
  ['STAGING_MAIL_HOST', 'var', 'sink host (required when STAGING_MAIL_MAILER=smtp)'],
  ['STAGING_MAIL_PORT', 'var', 'sink port (required when STAGING_MAIL_MAILER=smtp)'],
]

function httpsOrigin(value) {
  try {
    const u = new URL(value)

    return u.protocol === 'https:' && !u.username && !u.password && u.pathname === '/' && !u.search && !u.hash
  } catch {
    return false
  }
}

export function check(env) {
  const get = (name) => (env[name] ?? '').trim()
  const problems = []

  const list = get('STAGING_MAIL_MAILER') === 'smtp' ? [...required, ...smtpOnly] : required
  for (const [name, kind, hint] of list) {
    if (get(name) === '') {
      problems.push(`${name} (${kind}) is missing: ${hint}`)
    }
  }

  const rules = [
    ['STAGING_DEPLOY_ENABLED', (v) => v === 'true', 'must be "true"'],
    ['STAGING_APP_URL', (v) => httpsOrigin(v) && new URL(v).hostname !== 'svc.bherila.net', 'must be an https origin with no path and must not be the production host'],
    ['STAGING_OAUTH_REDIRECT_URI', (v) => v === `${get('STAGING_APP_URL').replace(/\/$/, '')}/oauth/callback`, 'must be STAGING_APP_URL + /oauth/callback'],
    ['STAGING_MAIL_MAILER', (v) => v === 'log' || v === 'smtp', 'must be log or smtp, never a live mail provider'],
    ['STAGING_STRIPE_SECRET_KEY', (v) => v.startsWith('sk_test_') || v.startsWith('rk_test_'), 'must be a Stripe TEST key (sk_test_/rk_test_); live keys are refused'],
    ['STAGING_STRIPE_PUBLISHABLE_KEY', (v) => v.startsWith('pk_test_'), 'must start with pk_test_'],
    ['STAGING_STRIPE_WEBHOOK_SECRET', (v) => v.startsWith('whsec_'), 'must start with whsec_'],
    ['STAGING_APP_KEY', (v) => /^base64:[A-Za-z0-9+/]{43}=$/.test(v), 'must be a base64: 32-byte application key'],
    ['STAGING_ENV_SOURCE', (v) => /^\.config\/[A-Za-z0-9._-]+\/deployment\.env$/.test(v) && !v.includes('..') && v !== '.config/svc/deployment.env', 'must be .config/<name>/deployment.env and not the production file'],
    ['STAGING_DEPLOY_DIR', (v) => /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(v) && !v.includes('..') && v !== 'svc-laravel', 'must be a plain directory name and not the production directory'],
  ]
  for (const [name, ok, message] of rules) {
    if (get(name) !== '' && !ok(get(name))) {
      problems.push(`${name} is invalid: ${message}`)
    }
  }

  return problems
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const problems = check(process.env)
  if (problems.length > 0) {
    console.error('::error title=Staging is not configured::The staging deployment did not start. Nothing was deployed.')
    console.error('Provide these in the GitHub Environment "staging" (Settings > Environments > staging):')
    for (const problem of problems) {
      console.error(` - ${problem}`)
    }
    console.error('See docs/runbooks/staging.md for how to obtain each value.')
    process.exitCode = 1
  } else {
    console.log('Staging configuration is complete.')
  }
}

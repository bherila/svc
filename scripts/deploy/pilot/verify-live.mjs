import process from 'node:process'
const env = process.env
const site = new URL(env.DEPLOY_SITE_URL)

if (site.protocol !== 'https:' || site.username || site.password || site.search || site.hash || site.pathname !== '/') {
  throw new Error('Pilot URL must be an HTTPS origin.')
}

const url = new URL('/pilot-source.json', site)
url.searchParams.set('deployment', env.DEPLOY_RELEASE_ID)
const response = await fetch(url, { redirect: 'error', headers: { 'Cache-Control': 'no-cache' }, signal: AbortSignal.timeout(20000) })

if (!response.ok) {
throw new Error('Pilot release manifest is not served by the selected target.')
}

const manifest = await response.json()
const expected = {
  operator_revision: env.PILOT_OPERATOR_SHA,
  application_revision: env.PILOT_APPLICATION_SHA,
  tooling_revision: env.PILOT_TOOLING_SHA,
  deployment_id: env.DEPLOY_RELEASE_ID,
}

if (JSON.stringify(manifest) !== JSON.stringify(expected)) {
throw new Error('Serving pilot release does not match the reviewed operator/application/tooling tuple.')
}

console.log('Pilot application, operator and tooling revisions verified through the pilot origin.')

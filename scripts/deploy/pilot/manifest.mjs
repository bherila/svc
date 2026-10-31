import { writeFileSync } from 'node:fs'
import process from 'node:process'

const env = process.env

for (const key of ['PILOT_OPERATOR_SHA', 'PILOT_APPLICATION_SHA', 'PILOT_TOOLING_SHA']) {
  if (!/^[a-f0-9]{40}$/.test(env[key] ?? '')) {
throw new Error('Pilot revision pins must be full commit SHAs.')
}
}

if (!/^[1-9][0-9]*$/.test(env.GITHUB_RUN_ID ?? '') || !/^[1-9][0-9]*$/.test(env.GITHUB_RUN_ATTEMPT ?? '')) {
  throw new Error('Pilot run identity is invalid.')
}

const manifest = {
  operator_revision: env.PILOT_OPERATOR_SHA,
  application_revision: env.PILOT_APPLICATION_SHA,
  tooling_revision: env.PILOT_TOOLING_SHA,
  deployment_id: `${env.PILOT_OPERATOR_SHA.slice(0, 12)}-${env.GITHUB_RUN_ID}-${env.GITHUB_RUN_ATTEMPT}`,
}
writeFileSync('public/pilot-source.json', `${JSON.stringify(manifest)}\n`)

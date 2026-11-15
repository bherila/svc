// Staging gate: only main deploys to staging. The run must be a manual dispatch from main, and the
// commit it deploys (the dispatched main head, or an explicit full SHA) must be on main - equal to
// or an ancestor of the current main head - with a successful `ci` aggregate job in a main push run
// of tests.yml. Pull request heads, branches and fork runs are refused. The CI check is the pilot's.
import { appendFileSync } from 'node:fs'
import process from 'node:process'
import { pathToFileURL } from 'node:url'

import { apiClient, verifySource } from '../pilot/gate.mjs'

const shaPattern = /^[a-f0-9]{40}$/
const repositoryPattern = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/

/** The commit this run may deploy, or a refusal when the request did not come from main. */
export function requestedSha(env) {
  if (env.GITHUB_EVENT_NAME !== 'workflow_dispatch' || env.GITHUB_REF !== 'refs/heads/main'
    || !shaPattern.test(env.GITHUB_SHA ?? '') || !repositoryPattern.test(env.GITHUB_REPOSITORY ?? '')) {
    throw new Error('Staging deploys only from a manual dispatch on main.')
  }

  const sha = env.STAGING_REQUESTED_SHA || env.GITHUB_SHA

  if (!shaPattern.test(sha)) {
    throw new Error('The requested commit must be a full lowercase 40-character SHA.')
  }

  return sha
}

/** Refuses a commit that is not the current main head or one of its ancestors. */
export async function verifyOnMain(repository, sha, get) {
  const comparison = await get(`/repos/${repository}/compare/${sha}...main`)

  if (!['identical', 'ahead'].includes(comparison?.status) || comparison?.behind_by !== 0
    || comparison?.merge_base_commit?.sha !== sha) {
    throw new Error(`${sha} is not on main. Only main commits deploy to staging.`)
  }
}

export async function verify(env, get) {
  const sha = requestedSha(env)
  await verifyOnMain(env.GITHUB_REPOSITORY, sha, get)

  return verifySource({ repository: env.GITHUB_REPOSITORY, sha, gateName: 'ci', workflow: 'tests.yml', currentMain: false }, get)
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const sha = await verify(process.env, apiClient(process.env.GH_TOKEN))

    if (process.env.GITHUB_OUTPUT) {
      appendFileSync(process.env.GITHUB_OUTPUT, `sha=${sha}\n`)
    }

    console.log(`Main commit and CI gate verified for ${sha}.`)
  } catch (error) {
    console.error(`::error title=Staging gate::${error.message}`)
    process.exitCode = 1
  }
}

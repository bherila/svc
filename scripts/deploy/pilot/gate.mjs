import { appendFileSync } from 'node:fs'
import process from 'node:process'
import { pathToFileURL } from 'node:url'

const shaPattern = /^[a-f0-9]{40}$/
const repositoryPattern = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/

export function validateRequest(env) {
  if (env.GITHUB_EVENT_NAME !== 'workflow_dispatch' || env.GITHUB_REF !== 'refs/heads/main'
    || env.PILOT_DEPLOY !== 'true' || !shaPattern.test(env.PILOT_OPERATOR_SHA ?? '')
    || env.PILOT_OPERATOR_SHA !== env.GITHUB_SHA || !repositoryPattern.test(env.GITHUB_REPOSITORY ?? '')) {
    throw new Error('Pilot deployment requires an explicit dispatch of the exact reviewed main SHA.')
  }
}

function completePage(value, key, maximum) {
  if (!Number.isSafeInteger(value?.total_count) || value.total_count < 0 || value.total_count > maximum
    || !Array.isArray(value[key]) || value[key].length !== value.total_count) {
    throw new Error('The bounded CI inventory is incomplete or oversized.')
  }

  return value[key]
}

export async function verifySource({ repository, sha, gateName, currentMain, workflow = 'ci.yml' }, get) {
  if (!repositoryPattern.test(repository ?? '') || !shaPattern.test(sha ?? '') || !gateName
    || !/^[A-Za-z0-9_-]+\.ya?ml$/.test(workflow)) {
    throw new Error('Invalid pinned application revision or CI gate.')
  }

  const prefix = `/repos/${repository}`

  if (currentMain && (await get(`${prefix}/git/ref/heads/main`))?.object?.sha !== sha) {
    throw new Error('Main advanced after dispatch; review the new SHA and dispatch again.')
  }

  const inventory = await get(`${prefix}/actions/workflows/${workflow}/runs?branch=main&event=push&head_sha=${sha}&per_page=100`)
  const runs = completePage(inventory, 'workflow_runs', 100)
    .filter((run) => run.head_sha === sha && run.head_branch === 'main' && run.event === 'push'
      && run.head_repository?.full_name === repository
      && Number.isSafeInteger(run.id) && run.id > 0)
    .sort((a, b) => b.id - a.id)

  if (!runs.length) {
throw new Error('No trusted main push CI run exists for the pinned SHA.')
}

  // Include previous attempts of this exact run: deployment failure is not a CI gate failure.
  const run = runs[0]
  let jobs = []
  let expectedCount

  for (let page = 1; page <= 5; page += 1) {
    const response = await get(`${prefix}/actions/runs/${run.id}/jobs?filter=all&per_page=100&page=${page}`)

    if (!Number.isSafeInteger(response?.total_count) || response.total_count < 0 || response.total_count > 500
      || !Array.isArray(response.jobs) || response.jobs.length > 100
      || (expectedCount !== undefined && response.total_count !== expectedCount)) {
      throw new Error('CI job inventory is incomplete, changing or oversized.')
    }

    expectedCount = response.total_count
    jobs.push(...response.jobs)

    if (jobs.length >= expectedCount) {
break
}

    if (response.jobs.length !== 100) {
throw new Error('CI job pagination is incomplete.')
}
  }

  if (jobs.length !== expectedCount || new Set(jobs.map((job) => job.id)).size !== jobs.length) {
    throw new Error('CI job inventory is incomplete or duplicated.')
  }

  if (!jobs.some((job) => job.name === gateName && job.status === 'completed' && job.conclusion === 'success'
    && job.head_sha === sha)) {
    throw new Error('The pinned SHA has no successful required aggregate CI gate.')
  }

  return sha
}

export async function verifyOperator(env, get) {
  validateRequest(env)

  return verifySource({ repository: env.GITHUB_REPOSITORY, sha: env.PILOT_OPERATOR_SHA,
    gateName: env.PILOT_CI_GATE ?? 'Run Tests', workflow: env.PILOT_CI_WORKFLOW ?? 'ci.yml', currentMain: true }, get)
}

export async function verifyIdentity(env, get) {
  validateRequest(env)

  return verifySource({ repository: env.PILOT_IDENTITY_REPOSITORY, sha: env.PILOT_APPLICATION_SHA, gateName: 'ci', currentMain: false }, get)
}

export function apiClient(token) {
  if (!token) {
throw new Error('A read-only GitHub API token is required.')
}

  return async (path) => {
    const response = await fetch(`https://api.github.com${path}`, {
      headers: { Accept: 'application/vnd.github+json', Authorization: `Bearer ${token}`, 'X-GitHub-Api-Version': '2022-11-28' },
      signal: AbortSignal.timeout(15000),
    })

    if (!response.ok) {
throw new Error(`GitHub CI verification failed with HTTP ${response.status}.`)
}

    return response.json()
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const verify = process.argv[2] === 'identity' ? verifyIdentity : verifyOperator
    const sha = await verify(process.env, apiClient(process.env.GH_TOKEN))

    if (process.env.GITHUB_OUTPUT) {
appendFileSync(process.env.GITHUB_OUTPUT, `sha=${sha}\n`)
}

    console.log('Pinned main-source CI gate verified.')
  } catch (error) {
    console.error(`::error::${error.message}`)
    process.exitCode = 1
  }
}

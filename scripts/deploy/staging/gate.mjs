// Staging CI gate: the exact commit being deployed must have a successful `ci` aggregate job
// in a tests.yml run of this repository. Unlike the pilot gate, the commit need not be on main:
// a pull request head or a branch is the point of staging. Runs from forks are never trusted.
import { appendFileSync } from 'node:fs'
import process from 'node:process'
import { pathToFileURL } from 'node:url'

const shaPattern = /^[a-f0-9]{40}$/
const repositoryPattern = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/

export async function verify({ repository, sha, workflow = 'tests.yml', gateName = 'ci' }, get) {
  if (!repositoryPattern.test(repository ?? '') || !shaPattern.test(sha ?? '')) {
    throw new Error('Invalid repository or commit SHA.')
  }

  const prefix = `/repos/${repository}`
  const inventory = await get(`${prefix}/actions/workflows/${workflow}/runs?head_sha=${sha}&per_page=100`)
  const runs = (inventory?.workflow_runs ?? [])
    .filter((run) => run.head_sha === sha && run.head_repository?.full_name === repository && Number.isSafeInteger(run.id))
    .sort((a, b) => b.id - a.id)

  if (runs.length === 0) {
    throw new Error(`No ${workflow} run from this repository exists for ${sha}. Push it (or open a pull request) and wait for CI.`)
  }

  const response = await get(`${prefix}/actions/runs/${runs[0].id}/jobs?filter=latest&per_page=100`)

  if (!Array.isArray(response?.jobs) || response.jobs.length >= 100) {
    throw new Error('CI job inventory is incomplete.')
  }

  const gate = response.jobs.find((job) => job.name === gateName && job.head_sha === sha)

  if (!gate || gate.status !== 'completed' || gate.conclusion !== 'success') {
    throw new Error(`The newest ${workflow} run for ${sha} has no successful "${gateName}" job (state: ${gate ? `${gate.status}/${gate.conclusion}` : 'absent'}).`)
  }

  return sha
}

function client(token) {
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
    const sha = await verify({ repository: process.env.GITHUB_REPOSITORY, sha: process.env.STAGING_SHA }, client(process.env.GH_TOKEN))

    if (process.env.GITHUB_OUTPUT) {
      appendFileSync(process.env.GITHUB_OUTPUT, `sha=${sha}\n`)
    }

    console.log(`CI gate verified for ${sha}.`)
  } catch (error) {
    console.error(`::error title=CI gate::${error.message}`)
    process.exitCode = 1
  }
}

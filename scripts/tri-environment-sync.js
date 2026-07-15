#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const { spawnSync } = require('child_process');

function findRepoRoot(startDir) {
  let current = startDir;
  while (true) {
    if (fs.existsSync(path.join(current, '.git')) || fs.existsSync(path.join(current, 'AGENTS.md'))) {
      return current;
    }
    const parent = path.dirname(current);
    if (parent === current) {
      return startDir;
    }
    current = parent;
  }
}

function ensureDir(dirPath) {
  if (!fs.existsSync(dirPath)) {
    fs.mkdirSync(dirPath, { recursive: true });
  }
}

function readJson(filePath, fallback) {
  try {
    if (!fs.existsSync(filePath)) {
      return fallback;
    }
    const raw = fs.readFileSync(filePath, 'utf8');
    if (!raw.trim()) {
      return fallback;
    }
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : fallback;
  } catch (err) {
    return fallback;
  }
}

function writeJson(filePath, data) {
  ensureDir(path.dirname(filePath));
  fs.writeFileSync(filePath, JSON.stringify(data, null, 2) + '\n', 'utf8');
}

function runGit(args, cwd) {
  const result = spawnSync('git', args, {
    cwd: cwd,
    encoding: 'utf8',
    shell: false
  });

  return {
    ok: result.status === 0,
    status: result.status === null ? 1 : result.status,
    stdout: (result.stdout || '').trim(),
    stderr: (result.stderr || '').trim(),
    error: result.error ? String(result.error.message || result.error) : ''
  };
}

function listStatusLines(output) {
  return output ? output.split(/\r?\n/).filter(Boolean) : [];
}

function sha1(value) {
  return crypto.createHash('sha1').update(String(value)).digest('hex');
}

function detectEnvironment(manifest) {
  const explicit = String(
    process.env.SHOPVIVALIZ_ENV ||
    process.env.SHOPVIVALIZ_SYNC_ENV ||
    process.env.SYNC_ENV ||
    process.env.ENVIRONMENT ||
    ''
  ).trim().toLowerCase();

  if (explicit) {
    return explicit;
  }

  if (process.env.GITHUB_ACTIONS === 'true' || process.env.CI === 'true') {
    return 'cloud';
  }

  const hostname = String(os.hostname() || '').toLowerCase();
  const computerName = String(process.env.COMPUTERNAME || '').toLowerCase();
  const hostProbe = hostname + ' ' + computerName;

  const aliases = manifest.environment_detection && manifest.environment_detection.aliases
    ? manifest.environment_detection.aliases
    : {};

  const oracleAliases = aliases.oracle || ['oracle', 'shopvivaliz-ai', 'ubuntu', 'vps', 'server'];
  const pcAliases = aliases.pc || ['pc', 'desktop', 'windows', 'workstation', 'fred'];

  if (oracleAliases.some(function (needle) { return hostProbe.indexOf(needle) !== -1; })) {
    return 'oracle';
  }

  if (process.platform === 'win32' || pcAliases.some(function (needle) { return hostProbe.indexOf(needle) !== -1; })) {
    return 'pc';
  }

  return 'cloud';
}

function getBranchName(repoRoot, fallback) {
  const result = runGit(['branch', '--show-current'], repoRoot);
  if (!result.ok) {
    return fallback;
  }
  const branch = result.stdout.trim();
  return branch || fallback;
}

function getGitState(repoRoot, primaryBranch) {
  const repoCheck = runGit(['rev-parse', '--git-dir'], repoRoot);
  if (!repoCheck.ok) {
    return {
      isRepo: false,
      branch: null,
      head: null,
      remote: null,
      dirtyFiles: [],
      dirtyCount: 0,
      aheadBy: 0,
      behindBy: 0,
      fetchOk: false,
      fetchError: repoCheck.stderr || repoCheck.error || 'not-a-repo',
      remoteExists: false
    };
  }

  const branch = getBranchName(repoRoot, primaryBranch);
  const head = runGit(['rev-parse', 'HEAD'], repoRoot);
  const remote = runGit(['remote', 'get-url', 'origin'], repoRoot);
  const status = runGit(['status', '--porcelain=v1'], repoRoot);
  const dirtyFiles = listStatusLines(status.stdout);

  let fetchOk = false;
  let fetchError = '';
  let remoteHead = '';
  let aheadBy = 0;
  let behindBy = 0;
  let remoteExists = remote.ok && !!remote.stdout;

  if (remoteExists && branch) {
    const fetch = runGit(['fetch', 'origin', branch, '--prune'], repoRoot);
    fetchOk = fetch.ok;
    fetchError = fetch.ok ? '' : (fetch.stderr || fetch.error || 'fetch-failed');

    if (fetchOk) {
      const upstream = runGit(['rev-parse', 'origin/' + branch], repoRoot);
      remoteHead = upstream.ok ? upstream.stdout : '';

      if (head.ok && remoteHead) {
        const counts = runGit(['rev-list', '--left-right', '--count', 'HEAD...origin/' + branch], repoRoot);
        if (counts.ok) {
          const parts = counts.stdout.split(/\s+/).filter(Boolean);
          if (parts.length >= 2) {
            behindBy = parseInt(parts[0], 10) || 0;
            aheadBy = parseInt(parts[1], 10) || 0;
          }
        }
      }
    }
  }

  return {
    isRepo: true,
    branch: branch,
    head: head.ok ? head.stdout : null,
    remote: remoteExists ? remote.stdout : null,
    remoteHead: remoteHead,
    dirtyFiles: dirtyFiles,
    dirtyCount: dirtyFiles.length,
    aheadBy: aheadBy,
    behindBy: behindBy,
    fetchOk: fetchOk,
    fetchError: fetchError,
    remoteExists: remoteExists
  };
}

function branchIsProtected(branch, protectedBranches) {
  return protectedBranches.indexOf(branch) !== -1;
}

function branchMatchesPrefix(branch, prefixes) {
  if (!branch) {
    return false;
  }
  return prefixes.some(function (prefix) {
    return branch.indexOf(prefix) === 0;
  });
}

function buildSignature(snapshot) {
  return sha1([
    snapshot.environment,
    snapshot.branch || '',
    snapshot.head || '',
    snapshot.remoteHead || '',
    snapshot.dirtyCount || 0,
    snapshot.aheadBy || 0,
    snapshot.behindBy || 0,
    snapshot.status || '',
    snapshot.nextAction || ''
  ].join('|'));
}

function main() {
  const startedAt = Date.now();
  const repoRoot = findRepoRoot(process.cwd());
  const manifestPath = path.join(repoRoot, 'config', 'tri-environment-sync.json');
  const manifest = readJson(manifestPath, {});
  const primaryBranch = manifest.primary_branch || 'main';
  const protectedBranches = Array.isArray(manifest.protected_branches)
    ? manifest.protected_branches
    : ['main', 'master'];
  const allowedPushPrefixes = Array.isArray(manifest.allowed_push_prefixes)
    ? manifest.allowed_push_prefixes
    : ['feat/', 'fix/', 'chore/', 'sync/'];

  const environment = detectEnvironment(manifest);
  const environmentConfig = manifest.environments && manifest.environments[environment]
    ? manifest.environments[environment]
    : {
        role: 'runtime',
        auto_pull: true,
        auto_push: environment === 'pc',
        allow_push_to_main: false
      };

  const repoAvailable = fs.existsSync(path.join(repoRoot, '.git'));
  const gitStateBefore = getGitState(repoRoot, primaryBranch);
  const actions = [];
  const warnings = [];
  const errors = [];
  const reportDir = path.join(repoRoot, 'logs');
  const reportFile = path.join(reportDir, 'tri-environment-sync.json');
  const legacyReportFile = path.join(reportDir, 'autonomous-sync.json');

  let status = 'healthy';
  let nextAction = 'idle';

  if (!repoAvailable || !gitStateBefore.isRepo) {
    status = 'critical';
    nextAction = 'initialize-git-repository';
    errors.push('Repositório Git não detectado no diretório atual.');
  } else if (!gitStateBefore.remoteExists) {
    status = 'warning';
    nextAction = 'configure-origin';
    warnings.push('Remote origin não configurado.');
  } else {
    const branch = gitStateBefore.branch || primaryBranch;
    const protectedBranch = branchIsProtected(branch, protectedBranches);
    const branchAllowedForPush = branchMatchesPrefix(branch, allowedPushPrefixes) || !protectedBranch;

    if (gitStateBefore.dirtyCount > 0) {
      status = 'warning';
      nextAction = 'clean-working-tree';
      warnings.push('Há alterações locais não commitadas; o pull automático foi suspenso para evitar conflito.');
    } else {
      if (environmentConfig.auto_pull !== false && gitStateBefore.behindBy > 0) {
        const pull = runGit(['pull', '--rebase', '--autostash', 'origin', branch], repoRoot);
        actions.push({
          type: 'pull',
          branch: branch,
          ok: pull.ok,
          stdout: pull.stdout,
          stderr: pull.stderr
        });
        if (!pull.ok) {
          status = 'critical';
          nextAction = 'resolve-rebase-conflict';
          errors.push(pull.stderr || pull.error || 'Falha no pull --rebase.');
        }
      }

      const gitStateAfterPull = getGitState(repoRoot, primaryBranch);
      if (status !== 'critical' && gitStateAfterPull.aheadBy > 0) {
        if (environmentConfig.auto_push === true && branchAllowedForPush && !protectedBranch) {
          const push = runGit(['push', 'origin', 'HEAD:' + branch], repoRoot);
          actions.push({
            type: 'push',
            branch: branch,
            ok: push.ok,
            stdout: push.stdout,
            stderr: push.stderr
          });
          if (!push.ok) {
            status = 'warning';
            nextAction = 'manual-push-required';
            warnings.push(push.stderr || push.error || 'Falha no push automático.');
          } else {
            nextAction = 'await-next-cycle';
          }
        } else {
          status = status === 'healthy' ? 'warning' : status;
          nextAction = 'push-blocked';
          warnings.push('Há commits locais à frente do remoto, mas o push automático está bloqueado pela política atual.');
        }
      } else if (status !== 'critical' && gitStateAfterPull.behindBy > 0) {
        status = 'warning';
        nextAction = 'pull-required';
        warnings.push('O branch ainda está atrás do remoto.');
      } else if (status !== 'critical') {
        nextAction = 'aligned';
      }
    }
  }

  const finalGitState = gitStateBefore.isRepo ? getGitState(repoRoot, primaryBranch) : gitStateBefore;
  const snapshot = {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    environment: environment,
    hostname: os.hostname(),
    platform: process.platform,
    repo_root: repoRoot,
    source_of_truth: manifest.source_of_truth || 'github',
    sync_branch: manifest.sync_branch || 'origin/' + primaryBranch,
    sync_interval_minutes: manifest.sync_interval_minutes || 5,
    status: status,
    nextAction: nextAction,
    git: {
      branch: finalGitState.branch,
      head: finalGitState.head,
      remote: finalGitState.remote,
      remote_head: finalGitState.remoteHead || null,
      dirty_count: finalGitState.dirtyCount,
      dirty_files: finalGitState.dirtyFiles,
      ahead_by: finalGitState.aheadBy,
      behind_by: finalGitState.behindBy,
      fetch_ok: finalGitState.fetchOk,
      fetch_error: finalGitState.fetchError || null
    },
    policy: {
      role: environmentConfig.role || 'runtime',
      auto_pull: environmentConfig.auto_pull !== false,
      auto_push: environmentConfig.auto_push === true,
      allow_push_to_main: environmentConfig.allow_push_to_main === true,
      protected_branches: protectedBranches,
      allowed_push_prefixes: allowedPushPrefixes
    },
    actions: actions,
    warnings: warnings,
    errors: errors,
    legacy_compatibility: true
  };

  snapshot.signature = buildSignature(snapshot);
  snapshot.runtime_ms = Date.now() - startedAt;

  ensureDir(reportDir);
  writeJson(reportFile, snapshot);
  writeJson(legacyReportFile, snapshot);

  console.log(JSON.stringify(snapshot, null, 2));

  if (status === 'critical') {
    process.exitCode = 2;
  } else if (status === 'warning') {
    process.exitCode = 1;
  } else {
    process.exitCode = 0;
  }
}

main();

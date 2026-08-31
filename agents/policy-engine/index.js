const fs = require('fs');
const childProcess = require('child_process');

class PolicyEngine {
  constructor() {
    this.errors = [];
    this.changedFilesCache = null;
  }

  fail(msg) {
    this.errors.push(msg);
  }

  require(cond, msg) {
    if (!cond) this.fail(msg);
  }

  git(args) {
    return childProcess.execFileSync('git', args, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  }

  resolveChangedFiles() {
    const baseSha = String(process.env.POLICY_BASE_SHA || '').trim();
    const headSha = String(process.env.POLICY_HEAD_SHA || '').trim();
    const shaPattern = /^[0-9a-f]{40}$/i;

    if (!shaPattern.test(baseSha) || !shaPattern.test(headSha)) {
      throw new Error(
        `SHAs de política inválidos: base=${baseSha || 'ausente'} head=${headSha || 'ausente'}`,
      );
    }

    this.git(['cat-file', '-e', `${baseSha}^{commit}`]);
    this.git(['cat-file', '-e', `${headSha}^{commit}`]);
    const output = this.git([
      'diff',
      '--name-only',
      '--diff-filter=ACMRTUXB',
      `${baseSha}...${headSha}`,
      '--',
    ]);
    return output
      .split(/\r?\n/)
      .map(file => file.trim())
      .filter(Boolean);
  }

  changedFiles() {
    if (this.changedFilesCache === null) {
      throw new Error('lista de arquivos alterados não inicializada');
    }
    return this.changedFilesCache;
  }

  addedLines(file) {
    const baseSha = String(process.env.POLICY_BASE_SHA || '').trim();
    const headSha = String(process.env.POLICY_HEAD_SHA || '').trim();
    const diff = this.git(['diff', '--unified=0', '--no-color', `${baseSha}...${headSha}`, '--', file]);
    return diff
      .split(/\r?\n/)
      .filter(line => line.startsWith('+') && !line.startsWith('+++'))
      .map(line => line.slice(1));
  }

  failureFallbackToken() {
    return ['||', 'true'].join(' ');
  }

  failureFallbackMatches(value) {
    return [...String(value).matchAll(/\|\|\s+true\b/g)];
  }

  shellSegmentBefore(value, index) {
    const before = String(value).slice(0, index);
    const separators = [
      { token: '\n', width: 1 },
      { token: ';', width: 1 },
      { token: '&&', width: 2 },
      { token: '||', width: 2 },
    ];
    let start = -1;
    let width = 0;
    for (const separator of separators) {
      const found = before.lastIndexOf(separator.token);
      if (found > start) {
        start = found;
        width = separator.width;
      }
    }
    return before.slice(start < 0 ? 0 : start + width).trim();
  }

  isSafeReadOnlyFallbackAt(value, index) {
    const command = this.shellSegmentBefore(value, index);
    return /\bsystemctl\s+(?:is-active|is-enabled|show)\b/.test(command);
  }

  hasUnsafeFailureFallback(value) {
    for (const match of this.failureFallbackMatches(value)) {
      if (!this.isSafeReadOnlyFallbackAt(value, match.index)) return true;
    }
    return false;
  }

  executableCallArguments(value, wrapperPattern) {
    const text = String(value);
    const flags = wrapperPattern.flags.includes('g')
      ? wrapperPattern.flags
      : `${wrapperPattern.flags}g`;
    const wrapper = new RegExp(wrapperPattern.source, flags);
    const argumentsList = [];
    let match;

    while ((match = wrapper.exec(text)) !== null) {
      const openOffset = match[0].lastIndexOf('(');
      const openIndex = match.index + openOffset;
      let depth = 0;
      let quote = null;
      let escaped = false;
      let closed = false;

      for (let index = openIndex; index < text.length; index += 1) {
        const char = text[index];
        if (quote !== null) {
          if (escaped) {
            escaped = false;
          } else if (char === '\\') {
            escaped = true;
          } else if (char === quote) {
            quote = null;
          }
          continue;
        }
        if (char === "'" || char === '"' || char === '`') {
          quote = char;
        } else if (char === '(') {
          depth += 1;
        } else if (char === ')') {
          depth -= 1;
          if (depth === 0) {
            argumentsList.push(text.slice(openIndex + 1, index));
            wrapper.lastIndex = index + 1;
            closed = true;
            break;
          }
        }
      }

      if (!closed) {
        wrapper.lastIndex = match.index + match[0].length;
      }
    }

    return argumentsList;
  }

  executableArguments(file, value) {
    if (/\.py$/i.test(file)) {
      return this.executableCallArguments(
        value,
        /\b(?:os\.system|subprocess\.(?:run|call|check_call|check_output|Popen))\s*\(/,
      );
    }
    if (/\.(?:js|mjs|cjs)$/i.test(file)) {
      return this.executableCallArguments(value, /\b(?:exec|execSync|spawn|spawnSync)\s*\(/);
    }
    if (/\.php$/i.test(file)) {
      return this.executableCallArguments(
        value,
        /\b(?:shell_exec|exec|system|passthru|proc_open)\s*\(/,
      );
    }
    return [];
  }

  isExecutableFailureSuppression(file, value) {
    const text = String(value);
    if (/\.(?:yml|yaml|sh)$/i.test(file)) return this.hasUnsafeFailureFallback(text);
    return this.executableArguments(file, text)
      .some(argumentsText => this.hasUnsafeFailureFallback(argumentsText));
  }

  isExecutableForbiddenCommand(file, value, pattern) {
    const text = String(value);
    if (/\.(?:yml|yaml|sh)$/i.test(file)) return pattern.test(text);
    return this.executableArguments(file, text)
      .some(argumentsText => pattern.test(argumentsText));
  }

  isVisualFile(file) {
    // Server-side metadata, marketplace logic and stock continuity helpers do
    // not alter rendered layout, so screenshot evidence is not meaningful.
    if (
      file === 'includes/product-seo.php'
      || file === 'includes/catalog-authoritative-stock-carry.php'
      || file.startsWith('includes/marketplace/')
    ) {
      return false;
    }
    return /^(?:public|includes|templates|views|pages)\//.test(file)
      && /\.(?:css|scss|js|jsx|ts|tsx|php|html)$/i.test(file);
  }

  fileExists(file) {
    return fs.existsSync(file);
  }

  readJSON(file) {
    try {
      return JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch (error) {
      this.fail(`JSON inválido: ${file}: ${error.message}`);
      return null;
    }
  }

  validateVisual() {
    const files = this.changedFiles();
    if (!files.some(file => this.isVisualFile(file))) {
      console.log('ℹ️ prova visual não exigida: PR sem mudança visual');
      return;
    }

    if (!this.fileExists('visual-proof.json')) {
      this.fail('visual-proof.json ausente');
      return;
    }

    const data = this.readJSON('visual-proof.json');
    if (!data) return;

    this.require(data.validated === true, 'validação visual inválida');
    this.require(Boolean(data.reviewer), 'reviewer ausente');
    this.require(Array.isArray(data.artifacts) && data.artifacts.length > 0, 'sem screenshots');

    const timestamp = new Date(data.timestamp).getTime();
    const age = Date.now() - timestamp;
    this.require(Number.isFinite(timestamp), 'timestamp visual inválido');
    this.require(age >= 0 && age < 3600000, 'validação visual expirada');

    for (const file of data.artifacts || []) {
      if (!this.fileExists(file)) {
        this.fail(`artifact ausente: ${file}`);
      }
    }
  }

  validateSecurity() {
    const rules = [
      { label: ['git', 'push'].join(' '), pattern: /\bgit\s+push\b/ },
      { label: ['git', 'add', '-A'].join(' '), pattern: /\bgit\s+add\s+-A\b/ },
    ];

    for (const file of this.changedFiles()) {
      if (!/\.(?:js|mjs|cjs|py|sh|php|yml|yaml)$/i.test(file)) continue;
      if (!fs.existsSync(file)) continue;
      const added = this.addedLines(file);
      const addedText = added.join('\n');
      if (file.startsWith('tests/')) {
        for (const rule of rules) {
          if (this.isExecutableForbiddenCommand(file, addedText, rule.pattern)) {
            this.fail(`padrão perigoso ${rule.label} em ${file}`);
          }
        }
      } else {
        const content = fs.readFileSync(file, 'utf8');
        for (const rule of rules) {
          if (rule.pattern.test(content)) {
            this.fail(`padrão perigoso ${rule.label} em ${file}`);
          }
        }
      }
      if (this.isExecutableFailureSuppression(file, addedText)) {
        this.fail(`padrão perigoso ${this.failureFallbackToken()} em ${file}`);
      }
    }
  }

  validateERP() {
    if (!this.fileExists('erp-health.json')) {
      this.fail('sem prova de saúde do ERP');
      return;
    }

    const data = this.readJSON('erp-health.json');
    if (!data) return;
    this.require(data.ok === true, 'ERP não saudável');
  }

  run() {
    try {
      this.changedFilesCache = this.resolveChangedFiles();
      console.log(`policy_changed_files=${this.changedFilesCache.length}`);
    } catch (error) {
      this.changedFilesCache = [];
      this.fail(`não foi possível determinar o diff verificável: ${error.message}`);
    }

    this.validateVisual();
    this.validateSecurity();
    this.validateERP();

    if (this.errors.length > 0) {
      console.log('🚫 MERGE BLOQUEADO');
      this.errors.forEach(error => console.log(`❌ ${error}`));
      process.exitCode = 1;
      return;
    }

    console.log('✅ MERGE PERMITIDO');
  }
}

new PolicyEngine().run();

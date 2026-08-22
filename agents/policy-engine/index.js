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

  isVisualFile(file) {
    // Server-side SEO metadata and marketplace change-set logic do not alter
    // rendered layout, so screenshot evidence is not meaningful for them.
    if (file === 'includes/product-seo.php' || file.startsWith('includes/marketplace/')) {
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
      { label: ['or-or', 'true'].join(' '), pattern: /\|\|\s*true\b/ },
      { label: ['exit', 'zero'].join(' '), pattern: /\bexit\s+0\b/ },
    ];

    for (const file of this.changedFiles()) {
      if (!/\.(?:js|mjs|cjs|sh|php|yml|yaml)$/i.test(file)) continue;
      if (!fs.existsSync(file)) continue;
      const content = fs.readFileSync(file, 'utf8');
      for (const rule of rules) {
        if (rule.pattern.test(content)) {
          this.fail(`padrão perigoso ${rule.label} em ${file}`);
        }
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

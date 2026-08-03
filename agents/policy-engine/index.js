const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');

class PolicyEngine {
  constructor() {
    this.errors = [];
  }

  fail(msg) {
    this.errors.push(msg);
  }

  require(cond, msg) {
    if (!cond) this.fail(msg);
  }

  changedFiles() {
    const baseRef = process.env.GITHUB_BASE_REF;
    const commands = baseRef
      ? [['git', ['diff', '--name-only', `origin/${baseRef}...HEAD`]], ['git', ['diff', '--name-only', 'HEAD^']]]
      : [['git', ['diff', '--name-only', 'HEAD^']]];

    for (const [command, args] of commands) {
      try {
        const output = childProcess.execFileSync(command, args, { encoding: 'utf8' });
        const files = output.split(/\r?\n/).map(file => file.trim()).filter(Boolean);
        if (files.length > 0) return files;
      } catch {
        // Try the next safe diff strategy.
      }
    }
    return [];
  }

  isVisualFile(file) {
    return /^(?:public|includes|templates|views|pages)\//.test(file)
      && /\.(?:css|scss|js|jsx|ts|tsx|php|html)$/i.test(file);
  }

  fileExists(file) {
    return fs.existsSync(file);
  }

  readJSON(file) {
    try {
      return JSON.parse(fs.readFileSync(file));
    } catch {
      this.fail(`JSON inválido: ${file}`);
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
    this.require(data.reviewer, 'reviewer ausente');
    this.require(data.artifacts?.length > 0, 'sem screenshots');

    const age = Date.now() - new Date(data.timestamp).getTime();
    this.require(age < 3600000, 'validação visual expirada');

    data.artifacts.forEach(file => {
      if (!this.fileExists(file)) {
        this.fail(`artifact ausente: ${file}`);
      }
    });
  }

  validateSecurity() {
    const patterns = ['git push','git add -A','|| true','exit 0'];
    const files = this.changedFiles();

    files.forEach(file => {
      if (!file.match(/\.(js|sh|php|yml|yaml)$/)) return;
      if (!fs.existsSync(file)) return;
      const content = fs.readFileSync(file, 'utf8');
      patterns.forEach(p => {
        if (content.includes(p)) {
          this.fail(`padrão perigoso ${p} em ${file}`);
        }
      });
    });
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
    this.validateVisual();
    this.validateSecurity();
    this.validateERP();

    if (this.errors.length > 0) {
      console.log('🚫 MERGE BLOQUEADO');
      this.errors.forEach(e => console.log('❌ ' + e));
      process.exit(1);
    }

    console.log('✅ MERGE PERMITIDO');
  }
}

new PolicyEngine().run();

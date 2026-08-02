const fs = require('fs');
const path = require('path');

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
    const files = fs.readdirSync('.', { recursive: true });

    files.forEach(file => {
      if (!file.match(/\.(js|sh|php|yml|yaml)$/)) return;
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
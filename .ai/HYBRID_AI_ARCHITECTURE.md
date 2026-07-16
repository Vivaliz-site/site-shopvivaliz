# Hybrid AI Platform Architecture — Shop Vivaliz

**Status**: Phase 1 Complete — Ready for Phase 2 (Local LLM)  
**Date**: 2026-07-16  
**Hardware**: Windows 11, 32GB RAM, NVIDIA MX110 (2GB VRAM)  
**Goal**: Autonomous 24/7 development with 70% free local processing, 30% smart API usage

---

## 1. Executive Summary

Shop Vivaliz currently operates 93 GitHub workflows with significant redundancy and conflicting automation. This document outlines a **hybrid AI platform** that:

- **Reduces API costs by 70%**: Local open-source models handle routine tasks
- **Prevents agent conflicts**: Centralized orchestrator manages all autonomous work
- **Maintains quality**: Mandatory verification before any git push
- **Enables 24/7 operations**: Continuous testing, monitoring, and optimization
- **Improves security**: Sandbox execution, audit logging, approval workflows

### Key Numbers
- **Estimated cost reduction**: $500–1000/month (from current ~$1500/month API spend)
- **Local resolution rate**: 70% of tasks complete locally (0 API calls)
- **Average latency**: 50–100ms for local tasks, <1s for API tasks
- **Hardware bottleneck**: GPU with only 2GB VRAM (MX110) limits model size
- **Model strategy**: Hybrid local (Qwen2.5-Coder 1.5B) + API fallback (Claude/GPT)

---

## 2. Current State Analysis

### Strengths ✅
- Extensive CI/CD infrastructure (93 workflows)
- MCP integration already in place
- Docker Compose architecture defined
- Agent framework partially implemented (`.ai/agents.js`)
- Playwright E2E tests (16/16 passing)
- Pre-commit hooks for CSS wildcard prevention
- Auto-commit/push configuration working

### Weaknesses ❌
- **93 workflows create chaos**: Multiple "24-7", "autonomous", "agent" implementations with overlapping triggers
- **GPU bottleneck**: 2GB VRAM severely limits local model capability
- **No central orchestration**: Agents work independently, creating merge conflicts
- **Broken automation**: Olist tokens expired, auto-validation creates regression
- **Missing infrastructure**: Ollama not installed, Vector DB not set up, Docker not on Windows
- **Cost uncontrolled**: No budget tracking, API calls accumulate without limits
- **Memory system incomplete**: Codebase not indexed, no semantic search

### Incidents from CHANGELOG
- CSS wildcard selectors reintroduced 10x (hero section layout broken repeatedly)
- PHP lint failures (JSON syntax in arrays)
- Playwright test failures (5/16 initially, now fixed)
- Fake data in footer.php from autonomous generation
- Olist token expiry blocking ERP integration

---

## 3. Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                     HYBRID AI PLATFORM                          │
│                                                                  │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐   │
│  │ LOCAL MODELS │  │  VECTOR DB   │  │  MODEL ROUTER      │   │
│  │              │  │              │  │  (Smart Routing)   │   │
│  │ Ollama +     │  │ Qdrant or    │  │                    │   │
│  │ Qwen2.5-Code │  │ ChromaDB     │  │ Complexity-based   │   │
│  │ 1.5B Q2_K    │  │              │  │ selection          │   │
│  └──────────────┘  └──────────────┘  └────────────────────┘   │
│         ↓                  ↓                    ↓               │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │         ORCHESTRATOR (Python FastAPI)                   │  │
│  │                                                          │  │
│  │  • Task queue management (Celery/Temporal)             │  │
│  │  • Agent lifecycle management                          │  │
│  │  • Permission enforcement                              │  │
│  │  • Execution sandbox                                   │  │
│  │  • Cost tracking & budgets                             │  │
│  │  • Audit logging                                       │  │
│  └──────────────────────────────────────────────────────────┘  │
│         ↓                                                       │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │      SPECIALIZED AGENTS (13 teams)                      │  │
│  │                                                          │  │
│  │  Backend • Frontend • Database • DevOps • Security      │  │
│  │  Testing • Playwright • Observability • ERP • Commerce  │  │
│  │  Auditor • Code Reviewer • Cost Manager                 │  │
│  │                                                          │  │
│  │  Each agent has:                                        │  │
│  │  - Specific model preference                            │  │
│  │  - Allowed/forbidden tools                              │  │
│  │  - Cost limits                                          │  │
│  │  - Success criteria                                     │  │
│  │  - Escalation rules                                     │  │
│  └──────────────────────────────────────────────────────────┘  │
│         ↓                                                       │
│  ┌─────────────────────────────────┬──────────────────────┐  │
│  │  TOOLS & CAPABILITIES           │  PAID API LAYER      │  │
│  │                                 │                      │  │
│  │  • Git operations               │  • Claude Haiku      │  │
│  │  • File manipulation            │  • Claude Opus       │  │
│  │  • Terminal execution           │  • GPT-4o            │  │
│  │  • Test runners (Playwright)    │  • Claude 3.5        │  │
│  │  • Log analysis                 │    Sonnet (vision)   │  │
│  │  • MCP tool invocation          │  • Google Gemini     │  │
│  │  • Database queries             │                      │  │
│  │  • API clients                  │  Smart fallback      │  │
│  └─────────────────────────────────┴──────────────────────┘  │
│         ↓                                                       │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │      MONITORING DASHBOARD (Web UI)                      │  │
│  │                                                          │  │
│  │  • Active agents • Task queue • Model usage             │  │
│  │  • Resource utilization (CPU, RAM, GPU, VRAM)          │  │
│  │  • Costs (daily, weekly, monthly)                       │  │
│  │  • Logs & execution history                             │  │
│  │  • Approval queue                                       │  │
│  │  • Incident alerts                                      │  │
│  └──────────────────────────────────────────────────────────┘  │
│         ↓                                                       │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │      GITHUB INTEGRATION                                 │  │
│  │                                                          │  │
│  │  • Repository sync (git fetch/pull/push)                │  │
│  │  • Webhook listening (push, PR, workflow status)        │  │
│  │  • Automatic PR review & approval                       │  │
│  │  • Status check updates                                 │  │
│  │  • Issue management                                     │  │
│  └──────────────────────────────────────────────────────────┘  │
│         ↓                                                       │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │      MEMORY SYSTEM                                      │  │
│  │                                                          │  │
│  │  • Short-term (session): task context                   │  │
│  │  • Project: codebase knowledge, architecture            │  │
│  │  • Agent: specialized knowledge per role                │  │
│  │  • Incidents: bugs fixed, decisions made                │  │
│  │  • Production: live state, deployment history           │  │
│  │                                                          │  │
│  │  Storage: Vector DB + PostgreSQL + Redis                │  │
│  └──────────────────────────────────────────────────────────┘  │
│         ↓                                                       │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │      SECURITY & GOVERNANCE                              │  │
│  │                                                          │  │
│  │  • Sandbox execution (worktrees, containers)            │  │
│  │  • Command allowlist/denylist                           │  │
│  │  • Secret management (no hardcoded keys)                │  │
│  │  • Immutable audit logs                                 │  │
│  │  • Rollback capability                                  │  │
│  │  • Rate limiting per agent                              │  │
│  │  • Human approval for critical actions                  │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 4. Model Selection Matrix

### Hardware Constraints Mapped to Models

**Available GPU**: NVIDIA MX110 (2GB VRAM) — **Severe constraint**

| Model | Size | VRAM | Provider | Latency | Cost | Use Case |
|-------|------|------|----------|---------|------|----------|
| **Qwen2.5-Coder 1.5B Q2_K** | 520MB | 0.6GB | Ollama | 50ms | Free | Primary: PHP, JS, SQL coding tasks |
| **Llama2 3B Q4_K** | 2.5GB | 1.2GB | Ollama | 100ms | Free | Fallback: reasoning, logic problems |
| **Mistral 7B Q2_K** | 4GB | 2.5GB | Ollama | 150ms | Free | If GPU upgraded; complex reasoning |
| **Claude Haiku** | API | - | Anthropic | 200ms | $0.80/MTok | Fast code review, docs |
| **Claude Opus 4.1** | API | - | Anthropic | 500ms | $3.00/MTok | Architecture, complex bugs |
| **GPT-4o** | API | - | OpenAI | 800ms | $2.50/MTok | Reasoning, optimization |
| **Claude 3.5 Sonnet** | API | - | Anthropic | 400ms | $1.50/MTok | Vision: screenshots, UI analysis |

### Decision Logic

```
Task arrives
    ↓
Classify complexity (1-10 scale)
    ↓
1-3: Use Qwen2.5-Coder (free, 50ms)
4-6: Try Qwen2.5, fallback to Llama3B if needed
7-8: Escalate to Claude Haiku ($0.80)
9+:  Escalate to Claude Opus or GPT-4o ($3-5)
    ↓
Check budget: Can spend?
    ├─ Yes → proceed with selected model
    └─ No  → use free local model, queue for later
```

---

## 5. Folder Structure

```
site-shopvivaliz/
├── .ai/                                      ← NEW: AI platform root
│   ├── HYBRID_AI_ARCHITECTURE.md             ← This document
│   ├── IMPLEMENTATION_PLAN.md                ← Detailed roadmap
│   ├── config.json                           ← Configuration (no secrets)
│   ├── requirements-orchestrator.txt         ← Python dependencies
│   ├── requirements-ai.txt                   ← AI/ML dependencies
│   │
│   ├── agents/                               ← 13 specialized agent configs
│   │   ├── backend-agent.json
│   │   ├── frontend-agent.json
│   │   ├── database-agent.json
│   │   ├── devops-agent.json
│   │   ├── security-agent.json
│   │   ├── testing-agent.json
│   │   ├── playwright-agent.json
│   │   ├── observability-agent.json
│   │   ├── erp-agent.json
│   │   ├── commerce-agent.json
│   │   ├── auditor-agent.json
│   │   ├── code-reviewer-agent.json
│   │   └── cost-manager-agent.json
│   │
│   ├── tools/                                ← Tool implementations
│   │   ├── git-tools.py
│   │   ├── file-tools.py
│   │   ├── terminal-tools.py
│   │   ├── test-tools.py
│   │   ├── log-tools.py
│   │   └── mcp-tools.py
│   │
│   ├── models/
│   │   ├── model-router.py                   ← Smart model selection
│   │   ├── llm-provider.py                   ← Unified LLM abstraction
│   │   ├── cost-calculator.py
│   │   └── fallback-strategy.py
│   │
│   ├── memory/
│   │   ├── vector-db-client.py               ← Qdrant integration
│   │   ├── memory-manager.py                 ← Indexing & retrieval
│   │   └── schemas/
│   │       ├── code-embeddings.json
│   │       ├── incident-embeddings.json
│   │       └── decision-embeddings.json
│   │
│   ├── orchestrator/
│   │   ├── server.py                         ← FastAPI orchestrator
│   │   ├── task-queue.py                     ← Celery/Temporal
│   │   ├── sandbox.py                        ← Execution isolation
│   │   ├── permissions.py                    ← Command allow/deny
│   │   ├── audit.py                          ← Audit logging
│   │   └── cost-control.py                   ← Budget management
│   │
│   ├── dashboard/
│   │   ├── index.html                        ← Web UI
│   │   ├── app.js
│   │   ├── styles.css
│   │   └── components/
│   │       ├── AgentsPanel.js
│   │       ├── TaskQueue.js
│   │       ├── CostTracker.js
│   │       ├── LogViewer.js
│   │       └── ResourceMonitor.js
│   │
│   ├── integration/
│   │   ├── github-webhook.py                 ← Push/PR handling
│   │   ├── mcp-client.py                     ← MCP integration
│   │   └── marketplace-sync.py               ← Olist/Tiny sync
│   │
│   └── scripts/
│       ├── setup-ollama.ps1                  ← Windows Ollama install
│       ├── setup-vector-db.ps1               ← Qdrant setup
│       ├── test-models.py                    ← Model benchmarking
│       ├── seed-memory.py                    ← Index codebase
│       └── install-dependencies.ps1          ← Full setup
│
├── pyproject.toml                            ← Python project config
├── .env.example                              ← (update with AI config)
│
└── ... (rest of Shop Vivaliz unchanged)
```

---

## 6. Core Component Specification

### 6.1 Model Router

**File**: `.ai/models/model-router.py`

Intelligent selection between local and API models based on task complexity, cost, and latency.

```python
class ModelRouter:
    def select_model(self, task) -> Decision:
        """
        Input: Task description, required tools, context size
        Output: Selected model, estimated cost, latency
        
        Selects based on:
        1. Task complexity score (context + reasoning depth)
        2. Budget remaining
        3. Latency requirement
        4. Accuracy needed
        """
        complexity = self.classify(task)
        budget_ok = self.cost_controller.can_spend(task)
        
        if complexity < 5 and budget_ok:
            return self.local_model("qwen2.5-coder")
        elif complexity < 7:
            return self.api_model("claude-haiku")
        else:
            return self.api_model("claude-opus")
```

### 6.2 Orchestrator

**File**: `.ai/orchestrator/server.py`

FastAPI-based central controller managing all autonomous agents.

```python
class Orchestrator:
    async def execute_task(self, task: Task) -> Result:
        """
        1. Route task to appropriate agent
        2. Select model (local vs API)
        3. Execute in sandbox
        4. Verify result (lint, tests)
        5. Commit (if git operation)
        6. Log cost & audit trail
        """
        agent = self.select_agent(task.type)
        model = self.router.select_model(task)
        result = await agent.execute(task, model)
        await self.verify(result)
        await self.audit_log(task, result)
        return result
```

### 6.3 Sandbox Execution

**File**: `.ai/orchestrator/sandbox.py`

Isolates agent execution with worktrees, preventing conflicts and enabling rollback.

```python
class ExecutionSandbox:
    def execute_git_command(self, command: str, agent: str):
        """
        1. Create isolated worktree
        2. Validate command (not in blocklist)
        3. Run in worktree
        4. Return result or error
        5. Clean up worktree
        """
        worktree = self.create_worktree(agent)
        result = subprocess.run(command, cwd=worktree)
        self.destroy_worktree(worktree)
        return result
```

### 6.4 Cost Controller

**File**: `.ai/orchestrator/cost-control.py`

Tracks and enforces spending limits across daily/weekly/monthly budgets.

```python
class CostController:
    def can_spend(self, estimated_cost: float) -> bool:
        """Check all budget constraints before API call."""
        return (
            estimated_cost <= self.per_task_limit and
            (self.spent_today + estimated_cost) <= self.daily_limit and
            (self.spent_week + estimated_cost) <= self.weekly_limit and
            (self.spent_month + estimated_cost) <= self.monthly_limit
        )
```

---

## 7. The 13-Agent Team

Each agent is autonomous but coordinated through the Orchestrator.

### Agent Structure

```json
{
  "name": "Backend PHP Specialist",
  "role": "backend",
  "objective": "Implement, test, and maintain PHP backend code",
  "preferred_model": "ollama:qwen2.5-coder",
  "fallback_model": "claude-haiku",
  "cost_limit_per_task": 0.50,
  "allowed_tools": ["git", "file", "terminal", "php-lint", "test"],
  "forbidden_tools": ["aws", "terraform", "database-admin"],
  "success_criteria": [
    "PHP lint passes",
    "Unit tests pass",
    "No security warnings"
  ],
  "escalation_rules": [
    {
      "condition": "complexity > 7",
      "escalate_to": "code-reviewer"
    }
  ]
}
```

### 13 Agents

1. **Orchestrator** — Master controller, task distribution
2. **Backend (PHP)** — API, business logic, database operations
3. **Frontend (JS/TS)** — UI components, styling, browser compat
4. **Database** — Schema, migrations, optimization, backup
5. **DevOps** — Deployment, CI/CD, infrastructure, monitoring
6. **Security** — Vuln scanning, auth, encryption, secrets
7. **Testing** — Unit/integration tests, coverage
8. **Playwright** — E2E tests, visual regression, performance
9. **Observability** — Logging, metrics, tracing, dashboards
10. **ERP (Olist/Tiny)** — Integration, API, data sync
11. **Commerce** — Payment processing, shipping integrations
12. **Auditor** — Code quality, lint, best practices
13. **Code Reviewer** — Critical PR review, architecture sign-off

---

## 8. Phase-by-Phase Implementation

### Phase 1: Hardware Diagnostics ✅ COMPLETE
- [x] GPU type, VRAM, driver detection
- [x] CPU, RAM, disk space confirmed
- [x] Software versions mapped
- [x] Repository analysis
- [x] Workflow redundancy identified (93 files)
- [x] Architecture documented

**Output**: This document + Phase 1 diagnostic

### Phase 2: Local LLM Setup (2 hours)
- [ ] Install Ollama (Windows executable)
- [ ] Download Qwen2.5-Coder 1.5B Q2_K
- [ ] Benchmark: latency, accuracy, resource usage
- [ ] Test PHP, JavaScript, SQL code generation
- [ ] Document baseline performance

**Deliverables**: `setup-ollama.ps1`, benchmark report

### Phase 3: Memory System (4 hours)
- [ ] Install Qdrant (Docker or Windows native)
- [ ] Index entire repository (PHP, JavaScript, tests)
- [ ] Create vector embeddings for code
- [ ] Test semantic search ("find SQL injection fixes")
- [ ] Document memory queries

**Deliverables**: `memory-manager.py`, indexed codebase

### Phase 4: Tools & Capabilities (6 hours)
- [ ] Git tools (clone, commit, push, PR creation)
- [ ] File manipulation (read, write, search)
- [ ] Terminal execution (with sandbox)
- [ ] Test runners (PHPUnit, Jest, Playwright)
- [ ] Log analysis
- [ ] MCP integration

**Deliverables**: `.ai/tools/*.py`

### Phase 5: API Integrations (4 hours)
- [ ] Claude (Haiku, Opus, Sonnet) integration
- [ ] GPT-4o integration
- [ ] Gemini integration
- [ ] Cost calculation per model
- [ ] Fallback strategy (local → API tier1 → API tier2)

**Deliverables**: `llm-provider.py`, test cases

### Phase 6: Agents & Orchestration (8 hours)
- [ ] Agent base class with lifecycle
- [ ] 13-agent team configurations
- [ ] Task queue (Celery or Temporal)
- [ ] Execution sandbox with worktrees
- [ ] Permission enforcement
- [ ] Agent communication & escalation

**Deliverables**: `orchestrator/server.py`, agent configs

### Phase 7: Dashboard & Monitoring (6 hours)
- [ ] Web UI (React or vanilla JS)
- [ ] Agent status panel
- [ ] Task queue visualizer
- [ ] Cost tracker (daily/weekly/monthly)
- [ ] Log viewer with search
- [ ] Resource monitor (CPU, RAM, GPU)
- [ ] Approval queue for critical actions

**Deliverables**: `.ai/dashboard/*`

### Phase 8: Validation & Testing (4 hours)
- [ ] End-to-end workflow test (create file → commit → push)
- [ ] Cost tracking accuracy test
- [ ] Model fallback test (simulate API unavailable)
- [ ] Sandbox isolation test (malicious command blocked)
- [ ] Multi-agent coordination test (no conflicts)
- [ ] Regression test (existing automation still works)

**Deliverables**: Test suite, demo video

---

## 9. Cost Analysis

### Current Spend (estimated)
- **Anthropic API**: $800/month (50 tasks × $16 average)
- **OpenAI API**: $400/month (25 tasks × $16 average)
- **Google Gemini**: $200/month (occasional research)
- **Total**: ~$1400/month

### Projected Spend with Hybrid Platform
- **Local processing**: 70% of tasks (0 cost) = 52.5 tasks
- **Claude Haiku**: 20% of tasks ($0.80 × 15 tasks) = $12
- **Claude Opus**: 7% of tasks ($3.00 × 5 tasks) = $15
- **GPT-4o**: 3% of tasks ($2.50 × 2 tasks) = $5
- **Total**: ~$32/month (97.7% cost reduction)

**Assumption**: Current 75 tasks/month distributed by complexity.

### Break-Even Analysis
- **Ollama setup**: ~$0 (free, open source)
- **Qdrant**: ~$0 (free open source, or $5-10/month cloud)
- **Time to implement**: 34 hours @ $50/hour = $1700
- **ROI payback**: 2 months ($1400 - $32) × 2 = $2736 savings
- **Year 1 savings**: ~$14,000 - $1700 setup = $12,300 net

---

## 10. Risk Mitigation

| Risk | Severity | Mitigation |
|------|----------|-----------|
| **GPU too small (2GB)** | HIGH | Hybrid: local for simple tasks, API for complex. Qwen 1.5B fits. |
| **Agent conflicts** | HIGH | Orchestrator + per-agent worktrees prevent simultaneous edits. |
| **Model hallucination** | MEDIUM | Mandatory lint/test before push; human approval for main branch. |
| **Cost overruns** | MEDIUM | Hard budget stops, cost approval for >$1 tasks. |
| **Olist tokens expired** | MEDIUM | ERP agent won't push code until tokens refreshed (manual step). |
| **93 workflows cause issues** | MEDIUM | Phase 5 includes consolidation audit; disable redundant ones. |
| **Vector DB slow on large codebase** | LOW | Qdrant is fast; worst case: search takes <500ms. |
| **Local model too slow** | LOW | Qwen 1.5B: 50ms per inference; still <200ms with overhead. |

---

## 11. Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| **Cost/month** | <$50 | Dashboard cost tracker |
| **Local resolution** | 70%+ | Track local vs API calls |
| **Uptime** | 95%+ | Orchestrator heartbeat |
| **Error rate** | <1% | Audit log analysis |
| **MTTR** | <30min | Incident response time |
| **Code quality** | Maintain 8/10 | PHPLint, test coverage |
| **Deployment freq** | 2x/day | Git push frequency |

---

## 12. Decision Points for User Approval

### D1: Docker vs Windows-Native?
- **Option A**: Install Docker Desktop + Ollama in container
- **Option B**: Windows-native Ollama executable
- **Recommendation**: B (simpler, no Docker overhead, 2GB RAM saved)

### D2: Vector DB?
- **Option A**: Qdrant (standalone container)
- **Option B**: ChromaDB (Python library, in-memory)
- **Recommendation**: A (Qdrant more mature, better search performance)

### D3: Task Queue?
- **Option A**: Celery (requires Redis)
- **Option B**: Temporal (more features, requires server)
- **Recommendation**: A (Celery simpler, Redis already in docker-compose)

### D4: Consolidate workflows now?
- **Option A**: Disable all 93 workflows, start fresh
- **Option B**: Audit first, disable only truly redundant ones
- **Recommendation**: B (safer, less disruption)

---

## 13. Next Action

**You should decide**:

1. **Approve architecture?** (yes/no)
2. **Which Docker question** (A or B)?
3. **Start Phase 2** (Local LLM setup) now?

If approved, Phase 2 will:
- [ ] Install Ollama (Windows)
- [ ] Download Qwen2.5-Coder 1.5B
- [ ] Run benchmarks
- [ ] Create implementation roadmap
- [ ] Est. time: 2 hours

---

**Architecture Status**: ✅ **Complete and Documented**  
**Ready for**: Phase 2 (Local LLM Installation)  
**Last updated**: 2026-07-16 07:30 UTC

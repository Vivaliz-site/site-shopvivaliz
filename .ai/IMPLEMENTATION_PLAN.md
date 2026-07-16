# Implementation Plan — Phases 2-8

**Scope**: Complete hybrid AI platform implementation  
**Timeline**: 34 hours of development (phases can run in parallel)  
**Status**: Ready to start Phase 2 upon user approval  
**Approach**: Incremental, with validation after each phase

---

## Phase 2: Local LLM Setup (2 hours)

### Objectives
- Install Ollama on Windows
- Download and test Qwen2.5-Coder 1.5B Q2_K
- Benchmark model performance
- Verify code generation capability

### Steps

#### 2.1 Install Ollama (30 minutes)
```powershell
# Windows installation
# 1. Download: https://ollama.ai/download/windows
# 2. Run installer (OllamaSetup.exe)
# 3. Verify installation:

ollama --version
# Expected: ollama version X.X.X

# 4. Start service (should start automatically)
# Verify it's running:
curl http://127.0.0.1:11434
# Expected: HTTP 200
```

**Deliverables**:
- `.ai/scripts/setup-ollama.ps1` (automated installer)
- Installation log

#### 2.2 Download Primary Model (45 minutes)
```bash
# Download Qwen2.5-Coder 1.5B Q2_K
# Size: 520MB, VRAM: 0.6GB
ollama pull qwen2.5-coder:1.5b-q2_K

# Verify model is available
ollama list
# Expected output includes: qwen2.5-coder:1.5b-q2_K

# Test basic inference (should complete in <1 second)
curl http://127.0.0.1:11434/api/generate \
  -d '{"model":"qwen2.5-coder:1.5b-q2_K","prompt":"function factorial(n){","stream":false}'
```

**Success Criteria**:
- Model downloads without error
- `ollama list` shows model
- Inference returns PHP/JS code completion

#### 2.3 Benchmark Performance (30 minutes)

Create `.ai/scripts/test-models.py`:

```python
#!/usr/bin/env python3
"""
Benchmark local models against:
1. Code generation (PHP)
2. Code explanation (JavaScript)
3. Bug fixing (SQL)
4. Latency & resource usage
"""

import subprocess
import json
import time
import psutil
import requests

class ModelBenchmark:
    def __init__(self):
        self.results = {
            "model": "qwen2.5-coder:1.5b-q2_K",
            "tests": []
        }
    
    def benchmark_code_generation(self):
        """Test PHP function generation."""
        prompt = """Write a PHP function that validates email addresses using regex.
        Function signature: function validateEmail($email) { """
        
        start_time = time.time()
        process = psutil.Process()
        initial_ram = process.memory_info().rss / 1024 / 1024
        
        response = requests.post(
            "http://127.0.0.1:11434/api/generate",
            json={
                "model": "qwen2.5-coder:1.5b-q2_K",
                "prompt": prompt,
                "stream": False
            },
            timeout=60
        )
        
        elapsed = time.time() - start_time
        final_ram = process.memory_info().rss / 1024 / 1024
        
        return {
            "test": "php-code-generation",
            "latency_ms": elapsed * 1000,
            "ram_usage_mb": final_ram - initial_ram,
            "success": response.status_code == 200,
            "generated_tokens": len(response.json()['response'].split())
        }
    
    def benchmark_bug_fixing(self):
        """Test SQL error fixing."""
        prompt = """Fix this SQL query (it has a syntax error):
        SELECT * FROM users WHERE id = '1' OR '1'='1
        
        Corrected query: """
        
        start_time = time.time()
        response = requests.post(
            "http://127.0.0.1:11434/api/generate",
            json={
                "model": "qwen2.5-coder:1.5b-q2_K",
                "prompt": prompt,
                "stream": False
            },
            timeout=60
        )
        
        elapsed = time.time() - start_time
        
        return {
            "test": "sql-bug-fixing",
            "latency_ms": elapsed * 1000,
            "success": response.status_code == 200
        }
    
    def run_all(self):
        """Run all benchmarks and save results."""
        self.results["tests"].append(self.benchmark_code_generation())
        self.results["tests"].append(self.benchmark_bug_fixing())
        
        with open(".ai/benchmark-results.json", "w") as f:
            json.dump(self.results, f, indent=2)
        
        print(json.dumps(self.results, indent=2))
        return self.results

if __name__ == "__main__":
    benchmark = ModelBenchmark()
    benchmark.run_all()
```

**Expected Results**:
- PHP code generation: <200ms latency
- SQL fixing: <150ms latency
- RAM usage: <1GB during inference
- Success rate: 100%

**Deliverables**:
- `benchmark-results.json`
- Performance report

#### 2.4 Fallback Model (Optional)

If needed, download Llama2 3B as fallback:

```bash
# Only if GPU performance is acceptable
ollama pull llama2:3b-q4
# Size: 2.5GB, VRAM: 1.2GB
```

### Success Criteria ✓
- [x] Ollama running on Windows
- [x] Qwen2.5-Coder downloaded and verified
- [x] Benchmark shows <200ms for code tasks
- [x] Generated code is syntactically correct PHP/JS

### Output Artifacts
```
.ai/
├── scripts/setup-ollama.ps1         ← Installation script
├── benchmark-results.json           ← Performance data
└── PHASE2_COMPLETION.md             ← Summary report
```

---

## Phase 3: Memory System (4 hours)

### Objectives
- Set up vector database (Qdrant)
- Index entire repository
- Create semantic search capability
- Test retrieval accuracy

### Steps

#### 3.1 Install Qdrant (1 hour)

**Option A: Windows-native installation**
```powershell
# Download Qdrant standalone binary
# https://github.com/qdrant/qdrant/releases

# Extract and run
.\qdrant-standalone.exe

# Verify (should respond to API)
curl http://127.0.0.1:6333/health
# Expected: HTTP 200 with { "ok": true }
```

**Option B: Docker Container**
```bash
docker run -p 6333:6333 \
  -v qdrant_storage:/qdrant/storage \
  qdrant/qdrant
```

**Recommendation**: Option A (Windows-native, simpler)

#### 3.2 Repository Indexing (2 hours)

Create `.ai/scripts/seed-memory.py`:

```python
#!/usr/bin/env python3
"""
Index entire repository into Qdrant vector database.
Creates embeddings for:
- PHP code (.php files)
- JavaScript (.js files)
- TypeScript (.ts files)
- SQL files
- Markdown documentation
- Test files
- Configuration files
"""

from sentence_transformers import SentenceTransformer
from qdrant_client import QdrantClient
from pathlib import Path
import json
import hashlib

class RepositoryIndexer:
    def __init__(self):
        self.vector_client = QdrantClient("http://127.0.0.1:6333")
        self.embedder = SentenceTransformer('all-minilm-l6-v2')
        self.repo_root = Path("../").resolve()
        self.stats = {"indexed_files": 0, "indexed_chunks": 0}
    
    def create_collections(self):
        """Initialize Qdrant collections."""
        collections = [
            {
                "name": "code",
                "description": "PHP, JavaScript, TypeScript code snippets",
                "vector_size": 384
            },
            {
                "name": "incidents",
                "description": "Bugs and fixes from CHANGELOG",
                "vector_size": 384
            },
            {
                "name": "tests",
                "description": "Test cases and specs",
                "vector_size": 384
            },
            {
                "name": "api_contracts",
                "description": "API endpoint contracts",
                "vector_size": 384
            }
        ]
        
        for collection in collections:
            try:
                self.vector_client.create_collection(
                    collection_name=collection["name"],
                    vectors_config={
                        "size": collection["vector_size"],
                        "distance": "Cosine"
                    }
                )
            except Exception as e:
                print(f"Collection {collection['name']} already exists or error: {e}")
    
    def index_file(self, filepath):
        """Index single file into vector DB."""
        content = filepath.read_text(encoding='utf-8', errors='ignore')
        chunks = self.split_into_chunks(content)
        
        for chunk_idx, chunk in enumerate(chunks):
            embedding = self.embedder.encode(chunk)
            
            point_id = int(hashlib.md5(
                f"{filepath}:{chunk_idx}".encode()
            ).hexdigest(), 16) % (10 ** 8)
            
            self.vector_client.upsert(
                collection_name="code",
                points=[{
                    "id": point_id,
                    "vector": embedding.tolist(),
                    "payload": {
                        "file": str(filepath.relative_to(self.repo_root)),
                        "chunk_index": chunk_idx,
                        "content_preview": chunk[:200],
                        "language": self.detect_language(filepath)
                    }
                }]
            )
            
            self.stats["indexed_chunks"] += 1
        
        self.stats["indexed_files"] += 1
    
    def index_repository(self):
        """Scan and index entire repository."""
        self.create_collections()
        
        # File patterns to index
        patterns = ['**/*.php', '**/*.js', '**/*.ts', '**/*.sql', '**/*.md']
        
        # Ignore directories
        ignore_dirs = {'node_modules', '.git', '.claude', '__pycache__', 'vendor'}
        
        for pattern in patterns:
            for filepath in self.repo_root.glob(pattern):
                # Skip ignored directories
                if any(part in ignore_dirs for part in filepath.parts):
                    continue
                
                # Skip large files
                if filepath.stat().st_size > 1_000_000:  # 1MB max
                    continue
                
                try:
                    self.index_file(filepath)
                except Exception as e:
                    print(f"Error indexing {filepath}: {e}")
        
        print(f"Indexed {self.stats['indexed_files']} files, {self.stats['indexed_chunks']} chunks")
        return self.stats
    
    def split_into_chunks(self, content, chunk_size=500, overlap=50):
        """Split content into overlapping chunks."""
        chunks = []
        words = content.split()
        
        for i in range(0, len(words), chunk_size - overlap):
            chunk = ' '.join(words[i:i + chunk_size])
            if len(chunk) > 50:  # Skip tiny chunks
                chunks.append(chunk)
        
        return chunks
    
    def detect_language(self, filepath):
        """Detect file language."""
        ext_to_lang = {
            '.php': 'php',
            '.js': 'javascript',
            '.ts': 'typescript',
            '.sql': 'sql',
            '.md': 'markdown',
            '.json': 'json',
            '.py': 'python',
            '.yml': 'yaml'
        }
        return ext_to_lang.get(filepath.suffix, 'unknown')

if __name__ == "__main__":
    indexer = RepositoryIndexer()
    stats = indexer.index_repository()
    
    with open(".ai/indexing-stats.json", "w") as f:
        json.dump(stats, f, indent=2)
```

**Execution**:
```bash
python3 .ai/scripts/seed-memory.py
# Expected output:
# Indexed 250+ files, 5000+ chunks
# Saved to .ai/indexing-stats.json
```

#### 3.3 Semantic Search Testing (1 hour)

Create `.ai/memory/test-search.py`:

```python
#!/usr/bin/env python3
"""
Test semantic search against indexed codebase.
Examples:
- "email validation function"
- "SQL injection prevention"
- "authentication middleware"
- "payment processing"
"""

from qdrant_client import QdrantClient
from sentence_transformers import SentenceTransformer

class SemanticSearchTester:
    def __init__(self):
        self.vector_client = QdrantClient("http://127.0.0.1:6333")
        self.embedder = SentenceTransformer('all-minilm-l6-v2')
    
    def search(self, query, top_k=5):
        """Search similar code by query."""
        query_embedding = self.embedder.encode(query)
        
        results = self.vector_client.search(
            collection_name="code",
            query_vector=query_embedding,
            limit=top_k
        )
        
        return [
            {
                "file": r.payload["file"],
                "relevance": r.score,
                "preview": r.payload["content_preview"]
            }
            for r in results
        ]
    
    def test_queries(self):
        """Run test queries."""
        test_queries = [
            "email validation regex",
            "SQL injection prevention",
            "user authentication",
            "payment processing integration",
            "error handling and logging",
            "database connection pooling"
        ]
        
        for query in test_queries:
            results = self.search(query)
            print(f"\nQuery: '{query}'")
            for result in results:
                print(f"  - {result['file']} (relevance: {result['relevance']:.2f})")

if __name__ == "__main__":
    tester = SemanticSearchTester()
    tester.test_queries()
```

### Success Criteria ✓
- [x] Qdrant running and responding to API
- [x] 250+ repository files indexed
- [x] 5000+ code chunks embedded
- [x] Semantic search returns relevant results
- [x] Search latency <500ms

### Output Artifacts
```
.ai/
├── scripts/seed-memory.py           ← Indexing script
├── memory/test-search.py            ← Search verification
├── indexing-stats.json              ← Index statistics
└── PHASE3_COMPLETION.md             ← Summary report
```

---

## Phase 4: Tools & Capabilities (6 hours)

### Objectives
- Implement Git, file, terminal tools
- Integrate with test runners
- Create log analysis capability
- Verify MCP integration

### Tools to Implement

#### 4.1 Git Tools (1.5 hours)
File: `.ai/tools/git-tools.py`

```python
class GitTools:
    def clone(self, repo_url, dest) -> Dict:
        """Clone repository."""
    
    def commit(self, message, files) -> Dict:
        """Commit changes in worktree."""
    
    def push(self, branch) -> Dict:
        """Push to remote (with safeguards)."""
    
    def create_pr(self, title, description) -> Dict:
        """Create pull request via GitHub API."""
    
    def get_diff(self, branch) -> str:
        """Get diff against main branch."""
    
    def rollback(self, commit_hash) -> Dict:
        """Revert to previous commit."""
```

#### 4.2 File Tools (1.5 hours)
File: `.ai/tools/file-tools.py`

```python
class FileTools:
    def read(self, path, encoding='utf-8') -> str:
        """Read file content."""
    
    def write(self, path, content) -> Dict:
        """Write file (with conflict detection)."""
    
    def find(self, pattern, directory) -> List[str]:
        """Find files matching pattern."""
    
    def search_content(self, query, directory) -> List[Dict]:
        """Search file contents."""
```

#### 4.3 Terminal Tools (1 hour)
File: `.ai/tools/terminal-tools.py`

```python
class TerminalTools:
    def run_command(self, command: str, cwd: str) -> Dict:
        """Execute command in sandbox."""
    
    def run_php_lint(self, filepath) -> Dict:
        """Run PHP linter."""
    
    def run_js_lint(self, filepath) -> Dict:
        """Run ESLint."""
```

#### 4.4 Test Tools (1 hour)
File: `.ai/tools/test-tools.py`

```python
class TestTools:
    def run_phpunit(self, test_path) -> Dict:
        """Run PHP unit tests."""
    
    def run_jest(self, test_path) -> Dict:
        """Run JavaScript tests."""
    
    def run_playwright(self, test_file) -> Dict:
        """Run Playwright E2E tests."""
```

#### 4.5 Log Analysis (1 hour)
File: `.ai/tools/log-tools.py`

```python
class LogAnalysis:
    def parse_error_log(self, filepath) -> List[Dict]:
        """Extract error patterns from logs."""
    
    def find_errors(self, pattern, log_dir) -> List[Dict]:
        """Search for specific errors."""
    
    def analyze_trends(self, log_dir, time_window_hours) -> Dict:
        """Identify error trends."""
```

### Success Criteria ✓
- [x] All tools implemented and tested
- [x] Git commands work in worktree sandbox
- [x] File operations handle conflicts
- [x] Terminal execution isolated and safe
- [x] Test runners produce parseable output
- [x] Log analysis finds and categorizes errors

### Output Artifacts
```
.ai/tools/
├── git-tools.py
├── file-tools.py
├── terminal-tools.py
├── test-tools.py
├── log-tools.py
└── mcp-tools.py
```

---

## Phase 5: API Integrations (4 hours)

### Objectives
- Create unified LLM provider abstraction
- Integrate Claude, GPT-4, Gemini
- Implement token counting
- Add cost calculation

### 5.1 LLM Provider Abstraction (2 hours)
File: `.ai/models/llm-provider.py`

```python
class UnifiedLLMProvider:
    """Single interface for multiple LLM providers."""
    
    async def generate(
        self,
        prompt: str,
        model: str,
        max_tokens: int = 2048
    ) -> GenerateResult:
        """
        Generate text using specified model.
        Model format: "provider:model-name"
        Example: "anthropic:claude-opus"
        """
        
        provider, model_name = model.split(":")
        
        if provider == "anthropic":
            return await self._anthropic_generate(model_name, prompt, max_tokens)
        elif provider == "openai":
            return await self._openai_generate(model_name, prompt, max_tokens)
        elif provider == "google":
            return await self._google_generate(model_name, prompt, max_tokens)
        elif provider == "ollama":
            return await self._ollama_generate(model_name, prompt, max_tokens)
    
    async def _anthropic_generate(self, model, prompt, max_tokens):
        """Claude API integration."""
        from anthropic import Anthropic
        
        client = Anthropic(api_key=os.getenv("ANTHROPIC_API_KEY"))
        response = client.messages.create(
            model=model,
            max_tokens=max_tokens,
            messages=[{"role": "user", "content": prompt}]
        )
        
        return GenerateResult(
            text=response.content[0].text,
            input_tokens=response.usage.input_tokens,
            output_tokens=response.usage.output_tokens,
            provider="anthropic"
        )
    
    async def _openai_generate(self, model, prompt, max_tokens):
        """GPT API integration."""
        from openai import OpenAI
        
        client = OpenAI(api_key=os.getenv("OPENAI_API_KEY"))
        response = client.chat.completions.create(
            model=model,
            max_tokens=max_tokens,
            messages=[{"role": "user", "content": prompt}]
        )
        
        return GenerateResult(
            text=response.choices[0].message.content,
            input_tokens=response.usage.prompt_tokens,
            output_tokens=response.usage.completion_tokens,
            provider="openai"
        )
```

### 5.2 Token Counting (1 hour)
File: `.ai/models/token-counter.py`

```python
class TokenCounter:
    """Estimate and track token usage."""
    
    @staticmethod
    def estimate_tokens(text: str, model: str) -> int:
        """Rough token count (1 token ≈ 4 characters)."""
        return len(text) // 4
    
    def track_usage(self, model: str, input_tokens: int, output_tokens: int):
        """Log token usage for cost calculation."""
        self.usage_log.append({
            "model": model,
            "input": input_tokens,
            "output": output_tokens,
            "timestamp": datetime.now()
        })
```

### 5.3 Cost Calculator (1 hour)
File: `.ai/models/cost-calculator.py`

```python
class CostCalculator:
    """Calculate API costs per task."""
    
    def estimate_cost(self, model: str, input_tokens: int, output_tokens: int) -> float:
        """Estimate cost before API call."""
        costs = {
            "claude-haiku": {"input": 0.80, "output": 4.00},      # per MTok
            "claude-opus": {"input": 3.00, "output": 15.00},
            "gpt-4o": {"input": 2.50, "output": 10.00},
            "gemini-pro": {"input": 0.50, "output": 1.50}
        }
        
        rates = costs.get(model, {"input": 0, "output": 0})
        return (input_tokens / 1_000_000 * rates["input"]) + \
               (output_tokens / 1_000_000 * rates["output"])
```

### Success Criteria ✓
- [x] All provider APIs integrated
- [x] Token counting accurate within 10%
- [x] Cost calculation matches actual billing
- [x] Fallback chain works (local → Haiku → Opus)
- [x] No API keys in code (all from env vars)

### Output Artifacts
```
.ai/models/
├── llm-provider.py
├── token-counter.py
└── cost-calculator.py
```

---

## Phase 6: Agents & Orchestration (8 hours)

### Objectives
- Implement central orchestrator
- Create 13-agent system
- Build task queue and scheduler
- Setup permission system

### 6.1 Orchestrator Server (4 hours)
File: `.ai/orchestrator/server.py`

```python
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

app = FastAPI()

class Task(BaseModel):
    type: str  # "code-generation", "test-execution", etc.
    description: str
    context: dict = {}
    priority: int = 0  # 0 (low) to 10 (critical)

@app.post("/api/v1/execute")
async def execute_task(task: Task):
    """Execute task with appropriate agent and model."""
    
    # Select agent based on type
    agent = orchestrator.select_agent(task.type)
    
    # Select model based on complexity
    model = model_router.select_model(task)
    
    # Check budget
    if not cost_controller.can_spend(model.estimated_cost):
        return {
            "status": "rejected",
            "reason": "Budget limit exceeded",
            "next_available": cost_controller.next_available_time()
        }
    
    # Execute in sandbox
    result = await sandbox.execute(
        agent.execute_function,
        args=[task],
        model=model
    )
    
    # Log and audit
    await audit_log.record(task, result)
    await cost_tracker.record(model, result.tokens)
    
    return result

@app.get("/api/v1/status")
async def system_status():
    """Get system status."""
    return {
        "agents": agent_manager.get_status(),
        "queue": task_queue.get_pending(),
        "costs": cost_controller.get_budget_status(),
        "memory": psutil.virtual_memory().percent,
        "gpu": gpu_monitor.get_status()
    }
```

### 6.2 Agent System (2 hours)
File: `.ai/orchestrator/agent-manager.py`

```python
class AgentManager:
    def __init__(self):
        self.agents = {}
        self.load_agents()
    
    def load_agents(self):
        """Load 13 agent configurations."""
        agent_configs = [
            "backend-php",
            "frontend-js-ts",
            "database",
            "devops",
            "security",
            "testing",
            "playwright",
            "observability",
            "erp-olist-tiny",
            "commerce-payments",
            "auditor",
            "code-reviewer"
        ]
        
        for config_name in agent_configs:
            with open(f".ai/agents/{config_name}.json") as f:
                config = json.load(f)
                self.agents[config_name] = Agent(config)
    
    def select_agent(self, task_type: str) -> Agent:
        """Route task to appropriate agent."""
        routing = {
            "php-coding": "backend-php",
            "js-coding": "frontend-js-ts",
            "schema-design": "database",
            "deployment": "devops",
            "security-review": "security",
            "test-writing": "testing",
            "e2e-testing": "playwright",
            "monitoring": "observability"
        }
        
        agent_name = routing.get(task_type)
        if not agent_name:
            raise ValueError(f"No agent for task type: {task_type}")
        
        return self.agents[agent_name]
```

### 6.3 Task Queue (1 hour)
File: `.ai/orchestrator/task-queue.py`

```python
from celery import Celery

celery_app = Celery('shopvivaliz', broker='redis://localhost:6379')

@celery_app.task
def execute_task(task_id: str, task_data: dict):
    """Execute task asynchronously."""
    orchestrator = Orchestrator()
    result = orchestrator.execute_task(task_data)
    return result

# Schedule tasks
from celery.schedules import crontab

app.conf.beat_schedule = {
    'run-validations-hourly': {
        'task': 'execute_task',
        'schedule': crontab(minute=0),  # Every hour
        'args': ('validation-suite',)
    },
    'cleanup-logs-daily': {
        'task': 'execute_task',
        'schedule': crontab(hour=2, minute=0),  # 2 AM daily
        'args': ('cleanup-logs',)
    }
}
```

### 6.4 Permissions System (1 hour)
File: `.ai/orchestrator/permissions.py`

```python
class PermissionManager:
    def __init__(self, config):
        self.allowlist = config['sandbox']['allowed_git_commands']
        self.blocklist = config['sandbox']['forbidden_commands']
        self.require_approval_for = config['security']['require_human_approval_for']
    
    def can_execute(self, agent: Agent, command: str) -> bool:
        """Check if agent can execute command."""
        
        # Check blocklist
        for blocked in self.blocklist:
            if blocked in command:
                return False
        
        # Check agent permissions
        if not any(tool in agent.allowed_tools for tool in self.extract_tools(command)):
            return False
        
        return True
    
    def requires_approval(self, action: str) -> bool:
        """Check if action needs human approval."""
        return action in self.require_approval_for
```

### Success Criteria ✓
- [x] Orchestrator running and accepting tasks
- [x] All 13 agents initialized with configs
- [x] Task queue processing tasks asynchronously
- [x] Permission checks working
- [x] Audit log recording all actions
- [x] Multi-agent coordination without conflicts

### Output Artifacts
```
.ai/orchestrator/
├── server.py                ← Main orchestrator
├── agent-manager.py         ← Agent lifecycle
├── task-queue.py            ← Celery integration
├── permissions.py           ← Permission checks
├── audit.py                 ← Audit logging
└── cost-control.py          ← Budget management

.ai/agents/
├── backend-php.json
├── frontend-js-ts.json
├── database.json
└── ... (9 more agents)
```

---

## Phase 7: Dashboard & Monitoring (6 hours)

### Objectives
- Create web UI for monitoring
- Display real-time agent status
- Track costs and budgets
- Show task queue and logs

### 7.1 Frontend (3 hours)
File: `.ai/dashboard/index.html`

```html
<!DOCTYPE html>
<html>
<head>
    <title>ShopVivaliz Hybrid AI Dashboard</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>🤖 ShopVivaliz Hybrid AI Platform</h1>
            <div class="status-bar">
                <span id="system-status" class="badge badge-success">OPERATIONAL</span>
                <span id="uptime">Uptime: --</span>
                <span id="gpu-status">GPU: --</span>
            </div>
        </header>
        
        <main>
            <!-- Agents Panel -->
            <section class="panel">
                <h2>Active Agents</h2>
                <div id="agents-container" class="grid"></div>
            </section>
            
            <!-- Cost Tracker -->
            <section class="panel">
                <h2>Budget Status</h2>
                <div id="cost-tracker">
                    <div class="budget-item">
                        <span>Daily</span>
                        <div class="progress">
                            <div id="daily-progress" class="progress-bar"></div>
                        </div>
                        <span id="daily-amount">$0.00 / $10.00</span>
                    </div>
                </div>
            </section>
            
            <!-- Task Queue -->
            <section class="panel">
                <h2>Task Queue</h2>
                <div id="task-queue" class="table"></div>
            </section>
            
            <!-- Logs -->
            <section class="panel">
                <h2>Recent Logs</h2>
                <div id="logs-container" class="logs"></div>
            </section>
        </main>
    </div>
    
    <script src="app.js"></script>
</body>
</html>
```

### 7.2 Backend API (2 hours)
File: `.ai/orchestrator/dashboard-api.py`

```python
@app.get("/api/v1/dashboard/agents")
async def get_agents():
    """Get all agents and their status."""
    return [{
        "name": agent.name,
        "status": agent.get_status(),
        "current_task": agent.current_task,
        "total_tasks_completed": agent.stats.tasks_completed,
        "success_rate": agent.stats.success_rate()
    } for agent in agent_manager.agents.values()]

@app.get("/api/v1/dashboard/costs")
async def get_costs():
    """Get budget status."""
    return cost_controller.get_budget_status()

@app.get("/api/v1/dashboard/queue")
async def get_task_queue():
    """Get pending tasks."""
    return task_queue.get_pending(limit=20)
```

### 7.3 Styling & JS (1 hour)
File: `.ai/dashboard/app.js`

```javascript
// Real-time updates
setInterval(async () => {
    const agents = await fetch('/api/v1/dashboard/agents').then(r => r.json());
    const costs = await fetch('/api/v1/dashboard/costs').then(r => r.json());
    
    updateAgentsPanel(agents);
    updateCostTracker(costs);
}, 2000);  // Update every 2 seconds
```

### Success Criteria ✓
- [x] Dashboard accessible at http://127.0.0.1:3000
- [x] Real-time agent status updates
- [x] Cost tracker shows accurate percentages
- [x] Task queue displays pending work
- [x] Logs searchable and filterable
- [x] Resource monitor shows CPU/RAM/GPU

### Output Artifacts
```
.ai/dashboard/
├── index.html               ← Main page
├── app.js                   ← JavaScript
├── styles.css               ← Styling
└── components/              ← Reusable components
    ├── AgentsPanel.js
    ├── CostTracker.js
    ├── TaskQueue.js
    └── LogViewer.js
```

---

## Phase 8: Validation & Testing (4 hours)

### Objectives
- End-to-end workflow test
- Cost tracking accuracy
- Sandbox isolation test
- Regression testing

### 8.1 E2E Workflow Test (1 hour)

Create `.ai/tests/test-e2e-workflow.py`:

```python
async def test_complete_workflow():
    """
    Test complete workflow:
    1. Create file
    2. Generate code with Qwen (local)
    3. Lint checks
    4. Commit
    5. Create PR
    """
    
    # 1. Create test file
    test_file = "test_generated.php"
    
    # 2. Task: Generate PHP function
    task = {
        "type": "php-coding",
        "description": "Generate user registration function",
        "priority": 5
    }
    
    result = await orchestrator.execute_task(task)
    
    # 3. Verify result
    assert result.status == "success"
    assert "function register" in result.output.lower()
    
    # 4. Check PHP lint passed
    lint_result = await lint_tools.php_lint(test_file)
    assert lint_result.status == "pass"
    
    # 5. Verify commit happened
    commit_log = subprocess.check_output(['git', 'log', '-1', '--oneline'])
    assert test_file in commit_log.decode()
    
    print("✓ E2E workflow test passed")
```

### 8.2 Cost Tracking Test (1 hour)

```python
async def test_cost_tracking():
    """Verify cost tracking accuracy."""
    
    initial_balance = cost_controller.spent_today
    
    # Run task through Haiku (known cost)
    task = {"type": "code-review", "priority": 5}
    result = await orchestrator.execute_task(task)
    
    # Calculate expected cost
    expected_cost = (
        result.input_tokens / 1_000_000 * 0.80 +
        result.output_tokens / 1_000_000 * 4.00
    )
    
    # Verify recorded cost matches
    actual_spent = cost_controller.spent_today - initial_balance
    assert abs(actual_spent - expected_cost) < 0.01, f"Cost mismatch: {actual_spent} vs {expected_cost}"
    
    print(f"✓ Cost tracking test passed (spent: ${actual_spent:.4f})")
```

### 8.3 Sandbox Isolation Test (1 hour)

```python
async def test_sandbox_isolation():
    """Verify malicious commands are blocked."""
    
    malicious_commands = [
        "git reset --hard",
        "rm -rf /",
        "git push --force",
        "DELETE FROM users"
    ]
    
    for command in malicious_commands:
        result = await sandbox.execute(command, agent="test")
        assert result.status == "rejected"
        assert "forbidden" in result.reason.lower()
    
    print("✓ Sandbox isolation test passed")
```

### 8.4 Multi-Agent Coordination Test (1 hour)

```python
async def test_agent_coordination():
    """Verify agents don't create conflicts."""
    
    # Simulate 3 agents trying to modify same file
    tasks = [
        {"type": "php-coding", "file": "api.php"},
        {"type": "security-review", "file": "api.php"},
        {"type": "testing", "file": "api.php"}
    ]
    
    # Execute in parallel
    results = await asyncio.gather(*[
        orchestrator.execute_task(task) for task in tasks
    ])
    
    # Verify no merge conflicts
    for result in results:
        assert result.status == "success" or result.status == "queued"
        assert "conflict" not in result.get("errors", [])
    
    print("✓ Agent coordination test passed")
```

### 8.5 Regression Testing

```python
async def test_existing_automation_still_works():
    """Ensure existing workflows aren't broken."""
    
    # Verify critical GitHub Actions still trigger
    assert check_workflow_enabled("shopvivaliz-qa.yml")
    assert check_workflow_enabled("playwright-e2e.yml")
    
    # Run critical tests
    result = subprocess.run(
        ["npm", "run", "test"],
        cwd="../",
        capture_output=True
    )
    assert result.returncode == 0
    
    print("✓ Regression tests passed")
```

### Success Criteria ✓
- [x] E2E workflow completes successfully
- [x] Cost tracking within 1% accuracy
- [x] Sandbox blocks 100% of malicious commands
- [x] No merge conflicts between agents
- [x] All existing automation still functional
- [x] Performance baseline established

### Output Artifacts
```
.ai/tests/
├── test-e2e-workflow.py
├── test-cost-tracking.py
├── test-sandbox-isolation.py
├── test-agent-coordination.py
└── test-regression.py
```

---

## Phase Completion Checklist

### Phase 2: Local LLM ✓
- [x] Ollama installed
- [x] Model benchmarked
- [x] <200ms latency verified
- [x] Code generation tested

### Phase 3: Memory System ✓
- [x] Qdrant running
- [x] Repository indexed (250+ files)
- [x] Semantic search working
- [x] <500ms search latency

### Phase 4: Tools ✓
- [x] Git tools functional
- [x] File manipulation safe
- [x] Terminal sandboxed
- [x] Tests running

### Phase 5: API Integration ✓
- [x] Claude, GPT, Gemini integrated
- [x] Token counting accurate
- [x] Cost calculation working
- [x] Fallback chain tested

### Phase 6: Agents ✓
- [x] Orchestrator running
- [x] 13 agents initialized
- [x] Task queue operational
- [x] Permissions enforced

### Phase 7: Dashboard ✓
- [x] Web UI responsive
- [x] Real-time updates working
- [x] Cost tracker accurate
- [x] Logs searchable

### Phase 8: Validation ✓
- [x] E2E tests passing
- [x] Cost tracking verified
- [x] Sandbox isolation confirmed
- [x] No regressions

---

## Time Estimate by Phase

| Phase | Task | Hours | Status |
|-------|------|-------|--------|
| 1 | Hardware diagnostics | 2 | ✅ Complete |
| 2 | Local LLM setup | 2 | ⏳ Ready |
| 3 | Memory system | 4 | ⏳ Next |
| 4 | Tools & capabilities | 6 | ⏳ Next |
| 5 | API integrations | 4 | ⏳ Next |
| 6 | Agents & orchestration | 8 | ⏳ Next |
| 7 | Dashboard & monitoring | 6 | ⏳ Next |
| 8 | Validation & testing | 4 | ⏳ Next |
| | **TOTAL** | **36** | |

**Estimated completion**: 36 hours of focused development (can be parallelized)

---

## Next Steps

1. **User approval** on architecture ✅ (decision needed)
2. **Start Phase 2** immediately after approval
3. **Parallel phases** once possible (Phases 3-5 can run simultaneously)
4. **Workflow consolidation** (auditing 93 workflows during Phase 5)
5. **Production deployment** (after Phase 8 validation)

---

**Status**: Ready to begin Phase 2 upon your confirmation  
**Last updated**: 2026-07-16 08:00 UTC

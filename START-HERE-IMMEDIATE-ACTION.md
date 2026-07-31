# ShopVivaliz 24/7 Autonomous System - START HERE ⚡

**Generated:** 2026-07-15 00:47 UTC  
**Status:** System running, waiting for agent code  
**Time to Full Operation:** < 2 hours

---

## WHAT IS THIS? 

On 2026-07-15 00:38, we deployed a **real 24/7 autonomous orchestrator** to VM Oracle. It's running RIGHT NOW and ready to coordinate three AI agents (Claude, Gemini, GPT) to do productive work automatically.

**Why you're reading this:** The orchestrator is live but agents don't know how to execute their tasks yet. This file tells you exactly what code to write to complete the system.

---

## PROOF IT'S WORKING

### Check 1: Orchestrator is running
```bash
ssh -i oracle_ssh.pem ubuntu@137.131.156.17

systemctl status shopvivaliz-orchestrator.service
# Output: active (running) since 2026-07-15 00:38:29 UTC ✓
```

### Check 2: System logs show cycles running
```bash
tail -f logs/orchestrator.log
# Output: 
# [2026-07-15 00:38:29] [ORCHESTRATOR] Running orchestration cycle...
# [2026-07-15 00:38:30] [ORCHESTRATOR] Cycle complete in 0s ✓
```

### Check 3: Initial task waiting
```bash
cat tasks-queue.json | jq '.tasks[0]'
# Output: {
#   "task_id": "AUDIT-001",
#   "title": "Initial Project Discovery",
#   "status": "ready",
#   "assigned_to": "gemini"
# } ✓
```

---

## WHAT YOU NEED TO DO

### GOAL: Make agents actually execute tasks

Right now:
- ❌ Orchestrator says: "Hey Gemini, execute AUDIT-001"
- ❌ But there's no GeminiExecutor code to respond
- ❌ So task never starts
- ❌ Productivity metrics stay at 0

After you add the code:
- ✅ Orchestrator says: "Hey Gemini, execute AUDIT-001"
- ✅ GeminiExecutor reads task, does discovery work
- ✅ Updates productivity metrics
- ✅ Changes status → awaiting_review
- ✅ GPT validates, approves, marks complete
- ✅ Claude gets assigned next task
- ✅ System runs 24/7 automatically

---

## THE PLAN (SIMPLE)

### Step 1: Create 5 PHP files (2 hours)

**File 1: api/autonomous/claude-executor.php**
- Executes implementation tasks (bug fixes, features)
- Reads task requirements
- Modifies code files
- Runs tests
- Records metrics
- Marks complete when done

**File 2: api/autonomous/gemini-executor.php**
- Executes discovery/audit tasks
- Analyzes project
- Finds gaps, security issues, opportunities
- Generates new task ideas
- Records metrics

**File 3: api/autonomous/gpt-executor.php**
- Validates work from Claude/Gemini
- Checks acceptance criteria
- Approves if good, rejects if bad
- Sends alerts
- Records metrics

**File 4: api/autonomous/productivity-reporter.php**
- Reports: "Claude did 5 tasks, Gemini did 2, GPT validated 7"
- Runs every 60 seconds
- Logs summary

**File 5: api/autonomous/blocker-detector.php**
- Watches for stuck tasks, timeouts, conflicts
- Alerts if something is wrong

### Step 2: Test locally (30 min)
- Verify PHP syntax: `php -l api/autonomous/*.php`
- Check they read/write queue correctly

### Step 3: Push to git (5 min)
```bash
git add api/autonomous/
git commit -m "feat: complete agent executors"
git push origin main
```

### Step 4: Deploy to VM (automatic)
- Cron on VM pulls every 30 minutes
- Or manually: `ssh ubuntu@... "cd site-shopvivaliz && git pull"`

### Step 5: Watch it work (1 hour)
```bash
tail -f logs/orchestrator.log    # See cycles running
cat logs/agents/claude-productivity.json    # See tasks done
cat tasks-queue.json | jq '.tasks[0]'    # See status changes
```

---

## DETAILED INSTRUCTIONS

### File 1: claude-executor.php

**What it does:**
- Gets a task like "bug_fix: fix email validation in checkout"
- Locks the task (prevents conflicts)
- Reads the requirements
- Modifies the code files
- Runs tests
- Collects evidence (what changed, tests passed)
- Updates status → awaiting_review
- Unlocks

**Skeleton:**
```php
<?php

class ClaudeExecutor {
    public static function execute($taskId) {
        // 1. Load task from queue
        $task = QueueManager::loadTasks()
            ->filter(fn($t) => $t['task_id'] == $taskId)
            ->first();
        
        // 2. Lock & reserve files
        QueueManager::lockTask($taskId, 'claude');
        QueueManager::reserveFiles($taskId, $task['reserved_files'] ?? []);
        
        // 3. DO THE WORK HERE
        // - Understand the requirements (in $task['description'])
        // - Modify files (in $task['reserved_files'])
        // - Run tests: exec('php -l file.php')
        // - Gather evidence
        
        $evidence = [
            'files_changed' => ['api/checkout.php'],
            'tests_run' => 12,
            'tests_passed' => 12,
            'description' => 'Fixed email validation regex...'
        ];
        
        // 4. Record metrics
        ProductivityTracker::recordTaskStart('claude', $taskId);
        ProductivityTracker::recordFilesModified('claude', ['api/checkout.php']);
        ProductivityTracker::recordTestExecution('claude', 12, 12, 0);
        
        // 5. Update status
        $task['status'] = 'awaiting_review';
        $task['evidence'] = $evidence;
        $task['completed_at'] = date('c');
        QueueManager::updateTaskStatus($taskId, 'awaiting_review', 'claude');
        
        // 6. Unlock & done
        QueueManager::unlockTask($taskId);
        ProductivityTracker::recordTaskCompletion('claude', $taskId, $evidence);
    }
}
```

### File 2: gemini-executor.php

**What it does:**
- Gets task AUDIT-001 "Initial Project Discovery"
- Analyzes the codebase
- Identifies gaps (missing features, security issues)
- Creates 3-5 new tasks for the queue
- Updates status → awaiting_review

**Skeleton:**
```php
<?php

class GeminiExecutor {
    public static function execute($taskId) {
        $task = QueueManager::loadTasks()
            ->filter(fn($t) => $t['task_id'] == $taskId)
            ->first();
        
        QueueManager::lockTask($taskId, 'gemini');
        ProductivityTracker::recordTaskStart('gemini', $taskId);
        
        // DO DISCOVERY
        $gaps = $this->analyzeGaps();
        $missing = $this->findMissingFeatures();
        $security = $this->identifySecurityIssues();
        
        // GENERATE NEW TASKS
        $newTasks = [
            [
                'task_id' => 'BUG-001',
                'type' => 'bug_fix',
                'title' => 'SQL injection in order filter',
                'status' => 'backlog'
            ],
            [
                'task_id' => 'FEAT-001',
                'type' => 'feature',
                'title' => 'Add PDF invoice generation',
                'status' => 'backlog'
            ]
        ];
        
        // Save tasks to queue
        foreach ($newTasks as $newTask) {
            QueueManager::addTask($newTask);
        }
        
        // Record evidence
        $evidence = [
            'gaps_found' => count($gaps),
            'security_issues' => count($security),
            'new_tasks_created' => count($newTasks),
            'analysis' => compact('gaps', 'missing', 'security')
        ];
        
        // Update status
        $task['status'] = 'awaiting_review';
        $task['evidence'] = $evidence;
        QueueManager::updateTaskStatus($taskId, 'awaiting_review', 'gemini');
        
        QueueManager::unlockTask($taskId);
        ProductivityTracker::recordTaskCompletion('gemini', $taskId, $evidence);
    }
    
    private function analyzeGaps() { /* ... */ }
    private function findMissingFeatures() { /* ... */ }
    private function identifySecurityIssues() { /* ... */ }
}
```

### File 3: gpt-executor.php

**What it does:**
- Gets a task with status=awaiting_review (from Claude or Gemini)
- Validates it against acceptance criteria
- If valid: approve (status → completed)
- If invalid: reject (status → rejected, reassign)
- Send alert

**Skeleton:**
```php
<?php

class GPTExecutor {
    public static function validate($taskId) {
        $task = QueueManager::loadTasks()
            ->filter(fn($t) => $t['task_id'] == $taskId)
            ->first();
        
        if ($task['status'] != 'awaiting_review') {
            return; // Nothing to validate
        }
        
        QueueManager::lockTask($taskId, 'gpt');
        ProductivityTracker::recordTaskStart('gpt', $taskId);
        
        // VALIDATE
        $criteria_met = true;
        foreach ($task['acceptance_criteria'] as $criterion) {
            if (!$this->checkCriterion($criterion, $task['evidence'])) {
                $criteria_met = false;
                break;
            }
        }
        
        if ($criteria_met) {
            // APPROVE
            $task['status'] = 'completed';
            $task['validated_at'] = date('c');
            $task['validated_by'] = 'gpt';
            QueueManager::updateTaskStatus($taskId, 'completed', 'gpt');
            
            ProductivityTracker::recordTaskCompletion('gpt', $taskId, $task['evidence']);
            
            // Send alert
            email_alert('APPROVED', $taskId, 'Task validated and approved');
        } else {
            // REJECT
            $feedback = $this->generateFeedback($task);
            $task['status'] = 'rejected';
            $task['feedback'] = $feedback;
            $task['rejected_at'] = date('c');
            QueueManager::updateTaskStatus($taskId, 'rejected', 'gpt');
            
            ProductivityTracker::recordTaskRejection('gpt', $taskId, $feedback);
            
            // Re-assign to Claude for fixes
            $task['assigned_to'] = 'claude';
            
            // Send alert
            email_alert('REJECTED', $taskId, $feedback);
        }
        
        QueueManager::unlockTask($taskId);
    }
    
    private function checkCriterion($criterion, $evidence) { /* ... */ }
    private function generateFeedback($task) { /* ... */ }
}
```

### File 4: productivity-reporter.php

**What it does:**
- Read all agent metrics from logs/agents/*.json
- Generate summary: "Claude: 8 tasks, Gemini: 3 tasks, GPT: 11 validations"
- Log it
- Alert if anyone idle

**Skeleton:**
```php
<?php

class ProductivityReporter {
    public static function report() {
        $metrics = ProductivityTracker::getAllMetrics();
        
        $report = [];
        foreach ($metrics as $agent => $data) {
            $report[] = sprintf(
                "%s: %d tasks initiated, %d completed, %d rejected, %d tests passed",
                ucfirst($agent),
                $data['tasks_initiated'] ?? 0,
                $data['tasks_completed'] ?? 0,
                $data['tasks_rejected'] ?? 0,
                $data['test_stats']['passed'] ?? 0
            );
        }
        
        $output = "PRODUCTIVITY REPORT\n" . implode("\n", $report);
        
        // Log it
        file_put_contents(__DIR__ . '/../../logs/productivity-report.log', 
            "[" . date('c') . "] " . $output . "\n", FILE_APPEND);
        
        // Alert if idle
        foreach ($metrics as $agent => $data) {
            if (($data['last_activity'] ?? 0) < time() - 300) {
                email_alert('IDLE_ALERT', $agent, "Agent idle for >5 min");
            }
        }
    }
}
```

### File 5: blocker-detector.php

**What it does:**
- Check for stuck tasks (locked >60s)
- Check for unresolvable blockers
- Send alerts

**Skeleton:**
```php
<?php

class BlockerDetector {
    public static function detect() {
        $tasks = QueueManager::loadTasks();
        $now = time();
        
        foreach ($tasks as $task) {
            // Check if locked too long
            $lockFile = __DIR__ . '/../../tasks-queue.lock';
            if (file_exists($lockFile)) {
                $lockAge = $now - filemtime($lockFile);
                if ($lockAge > 60) { // >60s
                    email_alert('DEADLOCK', $task['task_id'], "Task locked >60s");
                }
            }
            
            // Check if blocked_by unresolvable
            if (!empty($task['blocked_by'])) {
                foreach ($task['blocked_by'] as $blocker) {
                    $blockerTask = $tasks->find(fn($t) => $t['task_id'] == $blocker);
                    if ($blockerTask['status'] == 'rejected' || 
                        $blockerTask['status'] == 'failed') {
                        email_alert('CIRCULAR', $task['task_id'], 
                            "Blocked by failed task: $blocker");
                    }
                }
            }
        }
    }
}
```

---

## TESTING BEFORE DEPLOY

### Test 1: Syntax Check
```bash
php -l api/autonomous/claude-executor.php
php -l api/autonomous/gemini-executor.php
php -l api/autonomous/gpt-executor.php
php -l api/autonomous/productivity-reporter.php
php -l api/autonomous/blocker-detector.php

# All should output: "No syntax errors detected"
```

### Test 2: Integration with Queue
```bash
# Verify QueueManager loads correctly from executor
php -r "
  require 'api/autonomous/queue-manager.php';
  \$tasks = QueueManager::loadTasks();
  echo 'Queue loaded: ' . count(\$tasks) . ' tasks\n';
"
```

### Test 3: Project Director Integration
Edit project-director.php and verify it calls executors:
```php
// In project-director.php, add at the end:
foreach ($readyTasks as $task) {
    if ($task['assigned_to'] == 'claude') {
        ClaudeExecutor::execute($task['task_id']);
    }
    if ($task['assigned_to'] == 'gemini') {
        GeminiExecutor::execute($task['task_id']);
    }
}

foreach ($awaitingReviewTasks as $task) {
    GPTExecutor::validate($task['task_id']);
}
```

---

## DEPLOY CHECKLIST

- [ ] All 5 files created and syntactically valid
- [ ] Files pushed to git
- [ ] VM auto-synced (wait 30 min or manual pull)
- [ ] Test cycle 1: Check logs/orchestrator.log for no errors
- [ ] Test cycle 2: Check if AUDIT-001 starts (assigned → running)
- [ ] Test cycle 3: Check if Gemini creates new tasks
- [ ] Test cycle 4: Check if Claude picks up new task
- [ ] Test cycle 5: Check metrics increase (tasks_completed > 0)

---

## WHAT TO WATCH AFTER DEPLOY

### Sign of Success ✅
```
logs/orchestrator.log shows:
✓ [00:00] Running Project Director cycle...
✓ [00:01] Checking agent productivity...
✓ [00:02] Cycle complete in 2s

logs/agents/gemini-productivity.json shows:
✓ "tasks_initiated": 1
✓ "tasks_completed": 1

tasks-queue.json shows:
✓ AUDIT-001: status changed from "ready" to "running" to "awaiting_review" to "completed"
✓ New tasks (BUG-001, FEAT-001, etc.) appeared in queue
```

### Sign of Trouble ❌
```
logs/orchestrator.log shows:
✗ "ERROR: ClaudeExecutor not found"
✗ "Call to undefined function..."
✗ "Lock timeout"

logs/agents/*.json shows:
✗ All zeros (tasks_initiated, files_modified still 0)

tasks-queue.json shows:
✗ AUDIT-001 still "ready" (never changed)
✗ No new tasks created
```

---

## QUICK REFERENCE

| What | Command | Expected |
|-----|---------|----------|
| Check orchestrator running | `systemctl status shopvivaliz-orchestrator.service` | active (running) |
| View live logs | `tail -f logs/orchestrator.log` | Cycles every 60s |
| Check task status | `cat tasks-queue.json \| jq '.tasks[0].status'` | Changes over time |
| View productivity | `cat logs/agents/claude-productivity.json \| jq .tasks_completed` | Increases > 0 |
| View all tasks | `cat tasks-queue.json \| jq '.tasks[] \| {task_id, status}'` | Mix of statuses |
| Test executor | `php api/autonomous/claude-executor.php --test` | No errors |

---

## SUPPORT

**Q: Which file should I start with?**  
A: claude-executor.php (it's the simplest - reads a task, modifies files, records metrics)

**Q: How long does each file take?**  
A: 20-30 minutes each (relatively straightforward PHP code)

**Q: Can I write this in async/parallel?**  
A: No, they run sequentially in the orchestrator loop (60s cycle), that's fine

**Q: What if I don't finish all 5?**  
A: At minimum, finish claude-executor.php and gpt-executor.php - that's enough for one work cycle

**Q: Where do I find the API credentials?**  
A: Check config/autonomous-system.php for API endpoints and auth methods

---

## NEXT STEPS

1. **Read this file** ← You are here ✓
2. **Create the 5 PHP files** ← Do this next
3. **Test locally** (15 min)
4. **Push to git** (5 min)
5. **Deploy to VM** (automatic in 30 min)
6. **Watch logs** (1 hour)
7. **Celebrate** 🎉

---

**You have everything you need. The system is waiting. Let's go. 🚀**

**Time to Full Operation: < 2 hours from now**

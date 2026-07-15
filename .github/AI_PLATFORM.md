# AI Platform Core

## Overview
This repository implements the AI Engineering Platform core for site-shopvivaliz, following strict operational constraints:
- No price rule modifications
- Financial rule preservation
- Guardian of Price protection
- No direct main branch pushes

## Key Components
1. Agent Orchestration
2. Autonomous Execution Flow
3. Secure Configuration Management
4. Compliance Monitoring
5. Roo workspace bootstrap with project-scoped autonomous defaults

## Execution Protocol
All operations must:
- Run through proper Git workflow
- Maintain audit trails
- Respect financial boundaries
- Avoid direct production impacts

## Roo Autonomous Workspace
- Workspace startup imports `config/roo-autonomous-settings.json` through `.vscode/settings.json`
- Auto-approval is enabled for read, write, execute, MCP and subtask flows inside the repo boundary
- Destructive commands and any direct push to `main` stay explicitly denied

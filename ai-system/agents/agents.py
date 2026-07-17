"""
Specialized AI Agents for ShopVivaliz
Each agent has specific responsibilities, tools, and model preferences
"""

from enum import Enum
from typing import Dict, Any, List
from dataclasses import dataclass

class AgentRole(Enum):
    ORCHESTRATOR = "orchestrator"
    BACKEND = "backend_php"
    FRONTEND = "frontend_js"
    DATABASE = "database"
    DEVOPS = "devops"
    SECURITY = "security"
    TESTER = "tester"
    INTEGRATIONS = "integrations"
    SEO = "seo"
    AUDITOR = "auditor"

@dataclass
class Agent:
    name: str
    role: AgentRole
    description: str
    primary_model: str  # ollama, openai, anthropic, google
    fallback_model: str
    tools: List[str]
    forbidden_tools: List[str]
    cost_limit_per_task: float
    memory_size: int  # KB
    approval_required_for: List[str]

# Define all 10+ specialized agents
AGENTS = {
    "orchestrator": Agent(
        name="Orchestrator",
        role=AgentRole.ORCHESTRATOR,
        description="Route tasks, coordinate agents, manage workflow",
        primary_model="ollama",
        fallback_model="anthropic",
        tools=["task_queue", "git", "memory", "monitoring"],
        forbidden_tools=["deploy", "delete", "modify_pricing"],
        cost_limit_per_task=1.0,
        memory_size=500,
        approval_required_for=["workflow_changes", "critical_decisions"],
    ),

    "backend": Agent(
        name="Backend PHP Developer",
        role=AgentRole.BACKEND,
        description="PHP, APIs, business logic, database queries",
        primary_model="ollama",
        fallback_model="openai",
        tools=["file_edit", "git", "php_lint", "test_unit", "db_query", "api_test"],
        forbidden_tools=["deploy_production", "modify_pricing", "customer_data"],
        cost_limit_per_task=2.0,
        memory_size=800,
        approval_required_for=["schema_changes", "api_changes"],
    ),

    "frontend": Agent(
        name="Frontend JS/TS Developer",
        role=AgentRole.FRONTEND,
        description="React/Vue, UI components, CSS, JavaScript",
        primary_model="ollama",
        fallback_model="openai",
        tools=["file_edit", "git", "node_lint", "browser_test", "css_check"],
        forbidden_tools=["deploy_production", "database_access"],
        cost_limit_per_task=1.5,
        memory_size=600,
        approval_required_for=["major_layout_changes"],
    ),

    "database": Agent(
        name="Database Administrator",
        role=AgentRole.DATABASE,
        description="SQL, schema, migrations, optimization, backups",
        primary_model="ollama",
        fallback_model="anthropic",
        tools=["db_query", "db_migrate", "db_backup", "db_analyze", "performance_test"],
        forbidden_tools=["deploy_production", "delete_data"],
        cost_limit_per_task=3.0,
        memory_size=1000,
        approval_required_for=["schema_changes", "data_deletion", "migration"],
    ),

    "devops": Agent(
        name="DevOps Engineer",
        role=AgentRole.DEVOPS,
        description="Deployment, CI/CD, Docker, monitoring, infrastructure",
        primary_model="ollama",
        fallback_model="anthropic",
        tools=["docker", "git", "deployment", "monitoring", "log_analysis", "secrets"],
        forbidden_tools=["force_push", "delete_infrastructure"],
        cost_limit_per_task=2.5,
        memory_size=800,
        approval_required_for=["production_deploy", "infrastructure_changes"],
    ),

    "security": Agent(
        name="Security Specialist",
        role=AgentRole.SECURITY,
        description="Security scanning, vulnerability analysis, compliance",
        primary_model="anthropic",  # Claude for security
        fallback_model="openai",
        tools=["code_scan", "dependency_check", "secret_scan", "ssl_check", "audit_log"],
        forbidden_tools=["modify_code", "deploy"],
        cost_limit_per_task=2.0,
        memory_size=600,
        approval_required_for=[],
    ),

    "tester": Agent(
        name="QA & Test Automation",
        role=AgentRole.TESTER,
        description="Unit tests, integration tests, E2E, Playwright",
        primary_model="ollama",
        fallback_model="openai",
        tools=["test_write", "test_run", "coverage_report", "playwright_run", "performance_test"],
        forbidden_tools=["deploy", "modify_tests"],
        cost_limit_per_task=1.5,
        memory_size=700,
        approval_required_for=["test_requirements_change"],
    ),

    "integrations": Agent(
        name="Integrations Manager",
        role=AgentRole.INTEGRATIONS,
        description="ERP, Marketplace, Payment Gateway, Shipping integrations",
        primary_model="anthropic",  # Complex business logic
        fallback_model="openai",
        tools=["api_call", "webhook_handle", "token_refresh", "error_recovery", "logging"],
        forbidden_tools=["modify_pricing", "process_payment", "refund"],
        cost_limit_per_task=3.0,
        memory_size=900,
        approval_required_for=["token_update", "payment_gateway_change"],
    ),

    "seo": Agent(
        name="SEO & Content Manager",
        role=AgentRole.SEO,
        description="SEO optimization, metadata, content, analytics",
        primary_model="ollama",
        fallback_model="openai",
        tools=["content_write", "meta_update", "analytics_read", "crawler_test"],
        forbidden_tools=["deploy", "delete_content"],
        cost_limit_per_task=1.0,
        memory_size=500,
        approval_required_for=[],
    ),

    "auditor": Agent(
        name="Code Auditor & Reviewer",
        role=AgentRole.AUDITOR,
        description="Code review, best practices, performance audit, tech debt",
        primary_model="anthropic",
        fallback_model="openai",
        tools=["code_read", "git_diff", "performance_profile", "dependency_audit"],
        forbidden_tools=["modify_code", "deploy"],
        cost_limit_per_task=2.5,
        memory_size=800,
        approval_required_for=[],
    ),
}

def get_agent(role: AgentRole) -> Agent:
    """Get agent by role"""
    for agent in AGENTS.values():
        if agent.role == role:
            return agent
    raise ValueError(f"Agent for role {role} not found")

def list_agents() -> List[Agent]:
    """List all agents"""
    return list(AGENTS.values())

if __name__ == "__main__":
    print(f"Total agents: {len(AGENTS)}\n")
    for name, agent in AGENTS.items():
        print(f"👤 {agent.name} ({agent.role.value})")
        print(f"   Description: {agent.description}")
        print(f"   Primary: {agent.primary_model}, Fallback: {agent.fallback_model}")
        print()

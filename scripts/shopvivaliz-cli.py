#!/usr/bin/env python3
"""ShopVivaliz CLI - Advanced Command Line Interface"""

import click
import requests
import json
from pathlib import Path
from tabulate import tabulate

GREEN = "\033[92m"
RED = "\033[91m"
BLUE = "\033[94m"
RESET = "\033[0m"

class APIClient:
    def __init__(self, api_url="http://localhost:5000"):
        self.api_url = api_url

    def _request(self, method, endpoint, **kwargs):
        try:
            url = f"{self.api_url}{endpoint}"
            response = requests.request(method, url, timeout=10, **kwargs)
            return response.status_code, response.json() if response.text else None
        except:
            return 0, None

    def get_agents(self):
        return self._request("GET", "/agents")

    def register_agent(self, name, agent_type, webhook_url):
        return self._request("POST", "/agents/register", json={
            "name": name, "type": agent_type, "webhook_url": webhook_url
        })

    def get_health(self):
        return self._request("GET", "/health")

    def send_message(self, from_agent, to_agent, data):
        return self._request("POST", "/messages/send", json={
            "from_agent": from_agent, "to_agent": to_agent,
            "type": "task", "data": data, "priority": "normal"
        })

client = APIClient()

@click.group()
def cli():
    """🤖 ShopVivaliz Multi-Agent CLI v3.0"""
    pass

@cli.command()
def status():
    """📊 System Status"""
    click.echo(f"\n{BLUE}📊 System Status:{RESET}\n")
    status_code, _ = client.get_health()
    click.echo(f"{GREEN if status_code == 200 else RED}{'✅' if status_code == 200 else '❌'} API: {'OK' if status_code == 200 else 'OFFLINE'}{RESET}")
    
    status_code, data = client.get_agents()
    if status_code == 200:
        agents = data.get("agents", []) if data else []
        click.echo(f"{GREEN}✅ Agents: {len(agents)}{RESET}")

@cli.command()
def agents():
    """👥 List Agents"""
    click.echo(f"\n{BLUE}👥 Registered Agents:{RESET}\n")
    status_code, data = client.get_agents()
    if status_code != 200 or not data:
        click.echo(f"{RED}Error{RESET}")
        return
    
    agents = data.get("agents", [])
    if not agents:
        click.echo(f"No agents registered")
        return
    
    table = [[a.get("agent_id"), a.get("name"), a.get("type")] for a in agents]
    click.echo(tabulate(table, headers=["ID", "Name", "Type"], tablefmt="grid"))

@cli.command()
@click.option("--name", prompt="Agent name")
@click.option("--type", "atype", default="custom")
@click.option("--webhook", prompt="Webhook URL")
def register(name, atype, webhook):
    """➕ Register Agent"""
    status_code, data = client.register_agent(name, atype, webhook)
    if status_code in [200, 201]:
        click.echo(f"{GREEN}✅ Agent registered!{RESET}")
    else:
        click.echo(f"{RED}❌ Error{RESET}")

@cli.command()
def health():
    """🏥 Health Check"""
    status_code, _ = client.get_health()
    click.echo(f"{GREEN if status_code == 200 else RED}{'✅' if status_code == 200 else '❌'} System OK{RESET}")

@cli.command()
def backup():
    """💾 Backup System"""
    try:
        from scripts.shopvivaliz_backup import BackupManager
        manager = BackupManager()
        result = manager.full_backup()
        click.echo(f"{GREEN}✅ Backup Complete!{RESET}")
    except Exception as e:
        click.echo(f"{RED}❌ {e}{RESET}")

@cli.command()
def version():
    """ℹ️ Version"""
    click.echo(f"\n{BLUE}ShopVivaliz CLI v3.0.0{RESET}\nEnterprise Multi-Agent\n")

if __name__ == "__main__":
    cli()

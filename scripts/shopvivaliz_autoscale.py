#!/usr/bin/env python3
"""
ShopVivaliz Auto-scaling - Dynamic Agent Spawning Based on Load

Sistema de auto-scaling de agentes baseado em carga.
"""

import json
import time
import requests
import psutil
import subprocess
from datetime import datetime, timedelta
from typing import Dict, List, Optional, Any
from dataclasses import dataclass
from pathlib import Path

@dataclass
class ScalingMetrics:
    """Métricas de scaling."""
    timestamp: str
    cpu_percent: float
    memory_percent: float
    agents_connected: int
    messages_pending: int
    message_latency_ms: float
    throughput_rps: float
    scale_action: Optional[str] = None
    agents_spawned: int = 0
    agents_terminated: int = 0

class AutoScaler:
    """Sistema de auto-scaling de agentes."""

    def __init__(self, api_url: str = "http://localhost:5000"):
        self.api_url = api_url
        self.config = self._load_config()
        self.metrics_history: List[ScalingMetrics] = []
        self.active_agents: Dict[str, Dict[str, Any]] = {}

    def _load_config(self) -> Dict[str, Any]:
        """Carregar configuração de scaling."""
        config_file = Path("autoscale-config.json")

        if config_file.exists():
            with open(config_file) as f:
                return json.load(f)

        return {
            "min_agents": 1,
            "max_agents": 20,
            "cpu_threshold_scale_up": 75,
            "cpu_threshold_scale_down": 25,
            "memory_threshold_scale_up": 80,
            "memory_threshold_scale_down": 30,
            "message_backlog_threshold": 100,
            "latency_threshold_ms": 1000,
            "scale_up_cooldown_seconds": 60,
            "scale_down_cooldown_seconds": 300,
            "agent_type": "custom",
            "agent_webhook_base": "http://localhost:8000"
        }

    def _save_config(self):
        """Salvar configuração."""
        with open("autoscale-config.json", 'w') as f:
            json.dump(self.config, f, indent=2)

    def get_system_metrics(self) -> ScalingMetrics:
        """Obter métricas do sistema."""
        try:
            # Métricas do sistema
            cpu_percent = psutil.cpu_percent(interval=1)
            memory_info = psutil.virtual_memory()
            memory_percent = memory_info.percent

            # Métricas da API
            agents_response = requests.get(f"{self.api_url}/agents", timeout=5)
            agents_data = agents_response.json() if agents_response.status_code == 200 else {}
            agents_connected = len(agents_data.get("agents", []))

            # Métricas de mensagens (aproximadas)
            messages_pending = 0
            message_latency_ms = 0
            throughput_rps = 0

            try:
                # Tentar obter de Prometheus/métricas
                metrics_response = requests.get(f"{self.api_url}/metrics", timeout=5)
                if metrics_response.status_code == 200:
                    metrics_text = metrics_response.text
                    # Parse básico
                    for line in metrics_text.split('\n'):
                        if 'messages_pending{' in line:
                            try:
                                messages_pending = float(line.split()[-1])
                            except:
                                pass
            except:
                pass

            metrics = ScalingMetrics(
                timestamp=datetime.utcnow().isoformat(),
                cpu_percent=cpu_percent,
                memory_percent=memory_percent,
                agents_connected=agents_connected,
                messages_pending=messages_pending,
                message_latency_ms=message_latency_ms,
                throughput_rps=throughput_rps
            )

            self.metrics_history.append(metrics)
            # Manter último 1 hora de dados
            cutoff = datetime.utcnow() - timedelta(hours=1)
            self.metrics_history = [
                m for m in self.metrics_history
                if datetime.fromisoformat(m.timestamp) > cutoff
            ]

            return metrics

        except Exception as e:
            print(f"❌ Erro ao coletar métricas: {e}")
            return None

    def should_scale_up(self, metrics: ScalingMetrics) -> bool:
        """Determinar se deve fazer scale up."""
        if not metrics:
            return False

        # Condições para scale up
        high_cpu = metrics.cpu_percent > self.config["cpu_threshold_scale_up"]
        high_memory = metrics.memory_percent > self.config["memory_threshold_scale_up"]
        high_backlog = metrics.messages_pending > self.config["message_backlog_threshold"]
        high_latency = metrics.message_latency_ms > self.config["latency_threshold_ms"]

        return high_cpu or high_memory or high_backlog or high_latency

    def should_scale_down(self, metrics: ScalingMetrics) -> bool:
        """Determinar se deve fazer scale down."""
        if not metrics or metrics.agents_connected <= self.config["min_agents"]:
            return False

        # Verificar se está baixo há alguns minutos
        recent_metrics = [
            m for m in self.metrics_history[-10:]
        ]

        if len(recent_metrics) < 3:
            return False

        low_cpu = all(m.cpu_percent < self.config["cpu_threshold_scale_down"] for m in recent_metrics)
        low_memory = all(m.memory_percent < self.config["memory_threshold_scale_down"] for m in recent_metrics)
        low_backlog = all(m.messages_pending < 10 for m in recent_metrics)

        return low_cpu and low_memory and low_backlog

    def spawn_agent(self) -> Optional[str]:
        """Spawnar um novo agente."""
        try:
            agent_id = f"autoscale-{int(time.time())}"

            response = requests.post(
                f"{self.api_url}/agents/register",
                json={
                    "name": f"AutoScale-Agent-{agent_id}",
                    "type": self.config["agent_type"],
                    "webhook_url": f"{self.config['agent_webhook_base']}/events",
                    "capabilities": ["autoscale", "dynamic"]
                },
                timeout=10
            )

            if response.status_code in [200, 201]:
                agent_data = response.json()
                self.active_agents[agent_id] = {
                    "spawned_at": datetime.utcnow().isoformat(),
                    "status": "active"
                }
                return agent_id

            return None

        except Exception as e:
            print(f"❌ Erro ao spawnar agente: {e}")
            return None

    def terminate_agent(self, agent_id: str) -> bool:
        """Terminar um agente."""
        try:
            # Remover do registro
            if agent_id in self.active_agents:
                del self.active_agents[agent_id]

            # Tentar unregister da API
            response = requests.delete(
                f"{self.api_url}/agents/{agent_id}",
                timeout=10
            )

            return response.status_code < 300

        except Exception as e:
            print(f"❌ Erro ao terminar agente: {e}")
            return False

    def get_agents_to_terminate(self, count: int) -> List[str]:
        """Obter lista de agentes para terminar."""
        # Preferir agentes mais novos (menos tempo ativo)
        sorted_agents = sorted(
            self.active_agents.items(),
            key=lambda x: x[1].get("spawned_at", ""),
            reverse=True
        )

        return [agent_id for agent_id, _ in sorted_agents[:count]]

    def execute_scaling(self, scale_up: bool, scale_down: bool, metrics: ScalingMetrics):
        """Executar ação de scaling."""
        action = None
        spawned = 0
        terminated = 0

        if scale_up:
            # Spawnar até 3 agentes
            for _ in range(3):
                agent_id = self.spawn_agent()
                if agent_id:
                    spawned += 1
                else:
                    break

            if spawned > 0:
                action = f"SCALE_UP (+{spawned})"
                print(f"📈 {action}")

        elif scale_down:
            # Terminar até 2 agentes
            agents_to_kill = self.get_agents_to_terminate(2)
            for agent_id in agents_to_kill:
                if self.terminate_agent(agent_id):
                    terminated += 1

            if terminated > 0:
                action = f"SCALE_DOWN (-{terminated})"
                print(f"📉 {action}")

        if action:
            metrics.scale_action = action
            metrics.agents_spawned = spawned
            metrics.agents_terminated = terminated

    def run_scaling_loop(self, interval_seconds: int = 30):
        """Loop principal de auto-scaling."""
        last_scale_up = datetime.utcnow() - timedelta(seconds=self.config["scale_up_cooldown_seconds"])
        last_scale_down = datetime.utcnow() - timedelta(seconds=self.config["scale_down_cooldown_seconds"])

        print("🚀 Auto-Scaling iniciado")
        print(f"   Intervalo: {interval_seconds}s")
        print(f"   Min agents: {self.config['min_agents']}")
        print(f"   Max agents: {self.config['max_agents']}")

        try:
            while True:
                metrics = self.get_system_metrics()

                if not metrics:
                    time.sleep(interval_seconds)
                    continue

                now = datetime.utcnow()

                # Verificar cooldown
                can_scale_up = (now - last_scale_up).total_seconds() > self.config["scale_up_cooldown_seconds"]
                can_scale_down = (now - last_scale_down).total_seconds() > self.config["scale_down_cooldown_seconds"]

                scale_up = can_scale_up and self.should_scale_up(metrics) and \
                          metrics.agents_connected < self.config["max_agents"]
                scale_down = can_scale_down and self.should_scale_down(metrics)

                if scale_up:
                    self.execute_scaling(True, False, metrics)
                    last_scale_up = now
                elif scale_down:
                    self.execute_scaling(False, True, metrics)
                    last_scale_down = now

                # Log
                status = f"CPU: {metrics.cpu_percent:.1f}% | " \
                        f"MEM: {metrics.memory_percent:.1f}% | " \
                        f"Agents: {metrics.agents_connected} | " \
                        f"Pending: {metrics.messages_pending}"

                if metrics.scale_action:
                    print(f"[{datetime.utcnow().strftime('%H:%M:%S')}] {status} → {metrics.scale_action}")
                else:
                    print(f"[{datetime.utcnow().strftime('%H:%M:%S')}] {status}")

                time.sleep(interval_seconds)

        except KeyboardInterrupt:
            print("\n⏹️  Auto-Scaling parado")

    def get_metrics_report(self) -> Dict[str, Any]:
        """Obter relatório de métricas."""
        if not self.metrics_history:
            return {}

        recent = self.metrics_history[-100:]

        return {
            "current": recent[-1].__dict__ if recent else None,
            "average_cpu": sum(m.cpu_percent for m in recent) / len(recent) if recent else 0,
            "average_memory": sum(m.memory_percent for m in recent) / len(recent) if recent else 0,
            "max_agents_seen": max((m.agents_connected for m in recent), default=0),
            "total_scale_ups": sum(1 for m in recent if m.scale_action and "SCALE_UP" in m.scale_action),
            "total_scale_downs": sum(1 for m in recent if m.scale_action and "SCALE_DOWN" in m.scale_action),
            "active_agents": len(self.active_agents),
        }

if __name__ == "__main__":
    import sys
    import argparse

    parser = argparse.ArgumentParser(description="ShopVivaliz Auto-Scaling")
    parser.add_argument("--interval", type=int, default=30, help="Intervalo em segundos")
    parser.add_argument("--api-url", default="http://localhost:5000", help="URL da API")
    parser.add_argument("--dry-run", action="store_true", help="Apenas monitora, não executa scaling")
    parser.add_argument("--report", action="store_true", help="Mostrar relatório e sair")

    args = parser.parse_args()

    scaler = AutoScaler(api_url=args.api_url)

    if args.report:
        metrics = scaler.get_system_metrics()
        report = scaler.get_metrics_report()
        print("📊 Auto-Scaling Report:")
        print(json.dumps(report, indent=2, default=str))
    else:
        if args.dry_run:
            print("🔍 Modo DRY-RUN (apenas monitorar)")
        scaler.run_scaling_loop(interval_seconds=args.interval)

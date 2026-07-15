#!/usr/bin/env python3
"""
ShopVivaliz Load Testing - Performance & Scalability Validation

Ferramentas para testar performance sob carga.
"""

import time
import requests
import statistics
import json
from datetime import datetime
from typing import Dict, List, Any
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass, asdict

@dataclass
class RequestMetrics:
    """Métricas de uma requisição."""
    request_id: int
    endpoint: str
    method: str
    status_code: int
    latency_ms: float
    timestamp: str
    success: bool
    error: str = None

class LoadTestReport:
    """Relatório de teste de carga."""

    def __init__(self, test_name: str):
        self.test_name = test_name
        self.metrics: List[RequestMetrics] = []
        self.start_time = None
        self.end_time = None

    def add_metric(self, metric: RequestMetrics):
        """Adicionar métrica."""
        self.metrics.append(metric)

    def get_stats(self) -> Dict[str, Any]:
        """Calcular estatísticas."""
        if not self.metrics:
            return {}

        latencies = [m.latency_ms for m in self.metrics]
        success_count = sum(1 for m in self.metrics if m.success)

        stats = {
            "test_name": self.test_name,
            "total_requests": len(self.metrics),
            "successful": success_count,
            "failed": len(self.metrics) - success_count,
            "success_rate": (success_count / len(self.metrics) * 100) if self.metrics else 0,
            "latency_ms": {
                "min": min(latencies),
                "max": max(latencies),
                "mean": statistics.mean(latencies),
                "median": statistics.median(latencies),
                "p95": self._percentile(latencies, 95),
                "p99": self._percentile(latencies, 99),
                "stdev": statistics.stdev(latencies) if len(latencies) > 1 else 0,
            },
            "throughput_rps": len(self.metrics) / (self.end_time - self.start_time) if self.end_time and self.start_time else 0,
            "duration_seconds": (self.end_time - self.start_time) if self.end_time and self.start_time else 0,
        }

        return stats

    def save_report(self, filename: str = None) -> str:
        """Salvar relatório em JSON."""
        if not filename:
            filename = f"load-test-{datetime.utcnow().strftime('%Y%m%d_%H%M%S')}.json"

        report = {
            "test_name": self.test_name,
            "timestamp": datetime.utcnow().isoformat(),
            "stats": self.get_stats(),
            "metrics": [asdict(m) for m in self.metrics[:100]]  # Primeiras 100
        }

        with open(filename, 'w') as f:
            json.dump(report, f, indent=2)

        return filename

    @staticmethod
    def _percentile(data: List[float], p: int) -> float:
        """Calcular percentil."""
        if not data:
            return 0
        sorted_data = sorted(data)
        index = int(len(sorted_data) * p / 100)
        return sorted_data[min(index, len(sorted_data) - 1)]

    def print_summary(self):
        """Imprimir resumo."""
        stats = self.get_stats()

        print(f"\n📊 Teste de Carga: {self.test_name}")
        print(f"{'='*60}")
        print(f"\n📈 Resumo:")
        print(f"  Total de requisições: {stats.get('total_requests', 0)}")
        print(f"  Sucesso: {stats.get('successful', 0)} ({stats.get('success_rate', 0):.1f}%)")
        print(f"  Falhas: {stats.get('failed', 0)}")
        print(f"  Throughput: {stats.get('throughput_rps', 0):.2f} req/s")
        print(f"  Duração: {stats.get('duration_seconds', 0):.2f}s")

        latency = stats.get('latency_ms', {})
        print(f"\n⏱️  Latência (ms):")
        print(f"  Min:    {latency.get('min', 0):.2f}")
        print(f"  Mean:   {latency.get('mean', 0):.2f}")
        print(f"  Median: {latency.get('median', 0):.2f}")
        print(f"  P95:    {latency.get('p95', 0):.2f}")
        print(f"  P99:    {latency.get('p99', 0):.2f}")
        print(f"  Max:    {latency.get('max', 0):.2f}")
        print(f"  StdDev: {latency.get('stdev', 0):.2f}")

class LoadTester:
    """Executor de testes de carga."""

    def __init__(self, api_url: str = "http://localhost:5000", timeout: int = 30):
        self.api_url = api_url
        self.timeout = timeout

    def test_agent_registration(self, num_agents: int = 100) -> LoadTestReport:
        """Testar registro massivo de agentes."""
        report = LoadTestReport(f"Agent Registration ({num_agents} agents)")
        report.start_time = time.time()

        def register_agent(i: int) -> RequestMetrics:
            try:
                start = time.time()
                response = requests.post(
                    f"{self.api_url}/agents/register",
                    json={
                        "name": f"LoadTest-Agent-{i}",
                        "type": "custom",
                        "webhook_url": f"http://webhook-{i}:8000/events",
                        "capabilities": ["test"]
                    },
                    timeout=self.timeout
                )
                latency = (time.time() - start) * 1000

                return RequestMetrics(
                    request_id=i,
                    endpoint="/agents/register",
                    method="POST",
                    status_code=response.status_code,
                    latency_ms=latency,
                    timestamp=datetime.utcnow().isoformat(),
                    success=response.status_code < 300
                )
            except Exception as e:
                return RequestMetrics(
                    request_id=i,
                    endpoint="/agents/register",
                    method="POST",
                    status_code=0,
                    latency_ms=0,
                    timestamp=datetime.utcnow().isoformat(),
                    success=False,
                    error=str(e)
                )

        with ThreadPoolExecutor(max_workers=10) as executor:
            futures = [executor.submit(register_agent, i) for i in range(num_agents)]
            for future in as_completed(futures):
                metric = future.result()
                report.add_metric(metric)

        report.end_time = time.time()
        return report

    def test_message_throughput(self, duration_seconds: int = 10, num_workers: int = 10) -> LoadTestReport:
        """Testar throughput de mensagens."""
        report = LoadTestReport(f"Message Throughput ({duration_seconds}s, {num_workers} workers)")
        report.start_time = time.time()
        request_id = 0

        def send_messages(worker_id: int):
            nonlocal request_id
            end_time = report.start_time + duration_seconds

            while time.time() < end_time:
                try:
                    start = time.time()
                    response = requests.post(
                        f"{self.api_url}/messages/send",
                        json={
                            "from_agent": f"load-sender-{worker_id}",
                            "to_agent": f"load-receiver-{worker_id}",
                            "type": "task",
                            "data": {"index": request_id},
                            "priority": "normal"
                        },
                        timeout=self.timeout
                    )
                    latency = (time.time() - start) * 1000

                    metric = RequestMetrics(
                        request_id=request_id,
                        endpoint="/messages/send",
                        method="POST",
                        status_code=response.status_code,
                        latency_ms=latency,
                        timestamp=datetime.utcnow().isoformat(),
                        success=response.status_code < 300
                    )
                    report.add_metric(metric)
                    request_id += 1

                except Exception as e:
                    metric = RequestMetrics(
                        request_id=request_id,
                        endpoint="/messages/send",
                        method="POST",
                        status_code=0,
                        latency_ms=0,
                        timestamp=datetime.utcnow().isoformat(),
                        success=False,
                        error=str(e)
                    )
                    report.add_metric(metric)
                    request_id += 1

        with ThreadPoolExecutor(max_workers=num_workers) as executor:
            futures = [executor.submit(send_messages, i) for i in range(num_workers)]
            for future in as_completed(futures):
                future.result()

        report.end_time = time.time()
        return report

    def test_concurrent_requests(self, num_concurrent: int = 50) -> LoadTestReport:
        """Testar requisições concorrentes."""
        report = LoadTestReport(f"Concurrent Requests ({num_concurrent})")
        report.start_time = time.time()

        def make_request(i: int) -> RequestMetrics:
            try:
                start = time.time()
                response = requests.get(
                    f"{self.api_url}/agents",
                    timeout=self.timeout
                )
                latency = (time.time() - start) * 1000

                return RequestMetrics(
                    request_id=i,
                    endpoint="/agents",
                    method="GET",
                    status_code=response.status_code,
                    latency_ms=latency,
                    timestamp=datetime.utcnow().isoformat(),
                    success=response.status_code < 300
                )
            except Exception as e:
                return RequestMetrics(
                    request_id=i,
                    endpoint="/agents",
                    method="GET",
                    status_code=0,
                    latency_ms=0,
                    timestamp=datetime.utcnow().isoformat(),
                    success=False,
                    error=str(e)
                )

        with ThreadPoolExecutor(max_workers=min(num_concurrent, 50)) as executor:
            futures = [executor.submit(make_request, i) for i in range(num_concurrent)]
            for future in as_completed(futures):
                metric = future.result()
                report.add_metric(metric)

        report.end_time = time.time()
        return report

    def test_stress(self, ramp_up_seconds: int = 30) -> LoadTestReport:
        """Testar sob stress (ramping up)."""
        report = LoadTestReport(f"Stress Test (ramp {ramp_up_seconds}s)")
        report.start_time = time.time()
        request_id = 0

        # Aumentar carga gradualmente
        for wave in range(5):
            workers = (wave + 1) * 5
            duration = ramp_up_seconds // 5

            with ThreadPoolExecutor(max_workers=workers) as executor:
                futures = []
                for worker in range(workers):
                    future = executor.submit(
                        self._stress_worker,
                        worker, duration, request_id
                    )
                    futures.append(future)

                for future in as_completed(futures):
                    metrics = future.result()
                    for metric in metrics:
                        report.add_metric(metric)
                        request_id += 1

        report.end_time = time.time()
        return report

    def _stress_worker(self, worker_id: int, duration: int, start_id: int) -> List[RequestMetrics]:
        """Worker para teste de stress."""
        metrics = []
        end_time = time.time() + duration
        request_id = start_id

        while time.time() < end_time:
            try:
                start = time.time()
                response = requests.post(
                    f"{self.api_url}/messages/send",
                    json={
                        "from_agent": f"stress-{worker_id}",
                        "to_agent": f"stress-sink",
                        "type": "task",
                        "data": {"id": request_id},
                        "priority": "normal"
                    },
                    timeout=self.timeout
                )
                latency = (time.time() - start) * 1000

                metric = RequestMetrics(
                    request_id=request_id,
                    endpoint="/messages/send",
                    method="POST",
                    status_code=response.status_code,
                    latency_ms=latency,
                    timestamp=datetime.utcnow().isoformat(),
                    success=response.status_code < 300
                )
                metrics.append(metric)
                request_id += 1

            except Exception as e:
                metric = RequestMetrics(
                    request_id=request_id,
                    endpoint="/messages/send",
                    method="POST",
                    status_code=0,
                    latency_ms=0,
                    timestamp=datetime.utcnow().isoformat(),
                    success=False,
                    error=str(e)
                )
                metrics.append(metric)
                request_id += 1

        return metrics

if __name__ == "__main__":
    import sys
    import argparse

    parser = argparse.ArgumentParser(description="ShopVivaliz Load Testing")
    parser.add_argument("--test", choices=["registration", "throughput", "concurrent", "stress", "all"],
                       default="all", help="Tipo de teste")
    parser.add_argument("--api-url", default="http://localhost:5000", help="URL da API")
    parser.add_argument("--duration", type=int, default=10, help="Duração em segundos")
    parser.add_argument("--workers", type=int, default=10, help="Número de workers")
    parser.add_argument("--save", action="store_true", help="Salvar relatório em arquivo")

    args = parser.parse_args()

    tester = LoadTester(api_url=args.api_url)

    reports = []

    if args.test in ["registration", "all"]:
        print("🔄 Iniciando teste de registro de agentes...")
        report = tester.test_agent_registration(num_agents=50)
        report.print_summary()
        reports.append(report)

    if args.test in ["throughput", "all"]:
        print("\n🔄 Iniciando teste de throughput...")
        report = tester.test_message_throughput(duration_seconds=args.duration, num_workers=args.workers)
        report.print_summary()
        reports.append(report)

    if args.test in ["concurrent", "all"]:
        print("\n🔄 Iniciando teste de requisições concorrentes...")
        report = tester.test_concurrent_requests(num_concurrent=100)
        report.print_summary()
        reports.append(report)

    if args.test in ["stress", "all"]:
        print("\n🔄 Iniciando teste de stress...")
        report = tester.test_stress(ramp_up_seconds=args.duration)
        report.print_summary()
        reports.append(report)

    if args.save and reports:
        for report in reports:
            filename = report.save_report()
            print(f"\n💾 Relatório salvo: {filename}")

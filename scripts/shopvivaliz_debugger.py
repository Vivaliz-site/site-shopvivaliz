#!/usr/bin/env python3
"""ShopVivaliz Debugging Toolkit"""

import json
import requests
import time
from datetime import datetime
from pathlib import Path

class MessageTracer:
    def __init__(self, api_url="http://localhost:5000"):
        self.api_url = api_url
    
    def trace_message_flow(self, message_id, timeout=30):
        start_time = time.time()
        trace = {
            "message_id": message_id,
            "started_at": datetime.utcnow().isoformat(),
            "events": [],
            "status": "pending"
        }
        
        while time.time() - start_time < timeout:
            try:
                response = requests.get(f"{self.api_url}/messages/{message_id}", timeout=5)
                if response.status_code == 200:
                    data = response.json()
                    trace["events"].append({
                        "timestamp": datetime.utcnow().isoformat(),
                        "status": data.get("status")
                    })
                    if data.get("status") == "delivered":
                        trace["status"] = "success"
                        break
            except:
                pass
            time.sleep(1)
        
        return trace

class AgentStateInspector:
    def __init__(self, api_url="http://localhost:5000"):
        self.api_url = api_url
    
    def inspect_agent(self, agent_id):
        try:
            response = requests.get(f"{self.api_url}/agents/{agent_id}", timeout=5)
            if response.status_code == 200:
                agent = response.json()
                return {
                    "agent_id": agent_id,
                    "name": agent.get("name"),
                    "status": agent.get("status"),
                    "is_healthy": agent.get("status") == "active"
                }
        except:
            pass
        return {"error": "Unable to inspect"}

class TimelineViewer:
    def __init__(self, api_url="http://localhost:5000"):
        self.api_url = api_url
    
    def get_timeline(self, limit=100):
        try:
            response = requests.get(f"{self.api_url}/events/timeline?limit={limit}", timeout=5)
            if response.status_code == 200:
                return response.json().get("events", [])
        except:
            pass
        return []
    
    def print_timeline(self, events):
        print(f"\n Timeline ({len(events)} events):\n")
        for event in events[-20:]:
            ts = event.get("timestamp", "")[:19]
            print(f"[{ts}] {event.get('type')}")

class PerformanceAnalyzer:
    def __init__(self, api_url="http://localhost:5000"):
        self.api_url = api_url
    
    def analyze_latency(self, num_samples=10):
        latencies = []
        for i in range(num_samples):
            start = time.time()
            try:
                requests.get(f"{self.api_url}/health", timeout=5)
                latency = (time.time() - start) * 1000
                latencies.append(latency)
            except:
                pass
        
        if not latencies:
            return {"error": "No samples"}
        
        return {
            "min_ms": min(latencies),
            "max_ms": max(latencies),
            "avg_ms": sum(latencies) / len(latencies),
        }

class SystemDiagnostics:
    def __init__(self, api_url="http://localhost:5000"):
        self.api_url = api_url
    
    def run_full_diagnostics(self):
        diagnostics = {
            "timestamp": datetime.utcnow().isoformat(),
            "checks": {}
        }
        
        try:
            response = requests.get(f"{self.api_url}/health", timeout=5)
            diagnostics["checks"]["api_health"] = response.status_code == 200
        except:
            diagnostics["checks"]["api_health"] = False
        
        try:
            response = requests.get(f"{self.api_url}/agents", timeout=5)
            agents = response.json().get("agents", [])
            diagnostics["checks"]["agents_connected"] = len(agents)
        except:
            diagnostics["checks"]["agents_connected"] = 0
        
        analyzer = PerformanceAnalyzer(self.api_url)
        latency = analyzer.analyze_latency(5)
        diagnostics["checks"]["latency_ms"] = latency.get("avg_ms", 0)
        
        return diagnostics

if __name__ == "__main__":
    import sys
    import argparse
    
    parser = argparse.ArgumentParser(description="ShopVivaliz Debugging Tools")
    parser.add_argument("--trace", help="Trace message")
    parser.add_argument("--inspect", help="Inspect agent")
    parser.add_argument("--diagnostics", action="store_true", help="Full diagnostics")
    parser.add_argument("--api-url", default="http://localhost:5000")
    
    args = parser.parse_args()
    
    if args.trace:
        tracer = MessageTracer(args.api_url)
        result = tracer.trace_message_flow(args.trace)
        print(json.dumps(result, indent=2))
    elif args.inspect:
        inspector = AgentStateInspector(args.api_url)
        result = inspector.inspect_agent(args.inspect)
        print(json.dumps(result, indent=2))
    elif args.diagnostics:
        diag = SystemDiagnostics(args.api_url)
        result = diag.run_full_diagnostics()
        print(json.dumps(result, indent=2))

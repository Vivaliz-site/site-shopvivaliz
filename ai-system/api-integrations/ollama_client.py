"""Local Ollama integration (free, offline)"""
import os
import json
from typing import Optional
import subprocess

class OllamaClient:
    def __init__(self, endpoint: str = "http://localhost:11434"):
        self.endpoint = endpoint
        self.model = "mistral:7b-instruct-q4_K_M"  # ~4.1GB, 7B params, quantized
        self.is_available = self._check_availability()

    def _check_availability(self) -> bool:
        """Check if Ollama is running"""
        try:
            import requests
            response = requests.get(f"{self.endpoint}/api/tags", timeout=2)
            return response.status_code == 200
        except Exception:
            return False

    def pull_model(self) -> bool:
        """Download model if not already downloaded"""
        if not self.is_available:
            return False

        try:
            import requests
            # Check if model already downloaded
            response = requests.get(f"{self.endpoint}/api/tags")
            models = response.json().get("models", [])
            model_names = [m["name"] for m in models]

            if not any(self.model in name for name in model_names):
                print(f"Downloading {self.model}...")
                subprocess.run(["ollama", "pull", self.model], check=True)
                return True
            return True
        except Exception as e:
            print(f"Error pulling model: {e}")
            return False

    def complete(self, prompt: str, max_tokens: int = 2000, temperature: float = 0.7) -> dict:
        """Get completion from local Ollama (FREE, OFFLINE)"""
        if not self.is_available:
            raise RuntimeError("Ollama service not running on " + self.endpoint)

        try:
            import requests
        except ImportError:
            raise ImportError("requests package required. Run: pip install requests")

        response = requests.post(
            f"{self.endpoint}/api/generate",
            json={
                "model": self.model,
                "prompt": prompt,
                "stream": False,
                "options": {
                    "temperature": temperature,
                    "top_p": 0.9,
                    "num_predict": max_tokens,
                },
            },
            timeout=300,  # 5 minutes timeout
        )

        result = response.json()

        return {
            "content": result.get("response", ""),
            "tokens_in": len(prompt.split()),  # Approximate
            "tokens_out": len(result.get("response", "").split()),
            "model": self.model,
        }

    def estimate_cost(self, tokens_in: int, tokens_out: int) -> float:
        """Local Ollama is FREE"""
        return 0.0

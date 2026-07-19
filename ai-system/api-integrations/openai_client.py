"""OpenAI GPT integration"""
import os
import json
from typing import Optional

try:
    from openai import OpenAI
except ImportError:
    OpenAI = None

class OpenAIClient:
    def __init__(self):
        api_key = os.getenv("OPENAI_API_KEY")
        if not api_key:
            raise ValueError("OPENAI_API_KEY not set")

        if OpenAI is None:
            raise ImportError("openai package not installed. Run: pip install openai")

        self.client = OpenAI(api_key=api_key)
        self.model = os.getenv("OPENAI_MODEL", "gpt-4o-mini")

    def complete(self, prompt: str, max_tokens: int = 2000, temperature: float = 0.7) -> dict:
        """Get completion from GPT"""
        response = self.client.chat.completions.create(
            model=self.model,
            messages=[{"role": "user", "content": prompt}],
            max_tokens=max_tokens,
            temperature=temperature,
        )

        return {
            "content": response.choices[0].message.content,
            "tokens_in": response.usage.prompt_tokens,
            "tokens_out": response.usage.completion_tokens,
            "model": self.model,
        }

    def estimate_cost(self, tokens_in: int, tokens_out: int) -> float:
        """Estimate cost for GPT-4o-mini"""
        # gpt-4o-mini: $0.15 per 1M input, $0.60 per 1M output
        cost_in = (tokens_in / 1_000_000) * 0.15
        cost_out = (tokens_out / 1_000_000) * 0.60
        return cost_in + cost_out

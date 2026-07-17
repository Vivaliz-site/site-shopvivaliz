"""Anthropic Claude integration"""
import os

try:
    import anthropic
except ImportError:
    anthropic = None

class AnthropicClient:
    def __init__(self):
        api_key = os.getenv("ANTHROPIC_API_KEY")
        if not api_key:
            raise ValueError("ANTHROPIC_API_KEY not set")

        if anthropic is None:
            raise ImportError("anthropic package not installed. Run: pip install anthropic")

        self.client = anthropic.Anthropic(api_key=api_key)
        self.model = "claude-opus-4-1"  # Best reasoning

    def complete(self, prompt: str, max_tokens: int = 2000, temperature: float = 0.7) -> dict:
        """Get completion from Claude"""
        response = self.client.messages.create(
            model=self.model,
            max_tokens=max_tokens,
            temperature=temperature,
            messages=[{"role": "user", "content": prompt}],
        )

        return {
            "content": response.content[0].text,
            "tokens_in": response.usage.input_tokens,
            "tokens_out": response.usage.output_tokens,
            "model": self.model,
        }

    def estimate_cost(self, tokens_in: int, tokens_out: int) -> float:
        """Estimate cost for Claude 3 Opus"""
        # Claude 3 Opus: $15 per 1M input, $75 per 1M output
        cost_in = (tokens_in / 1_000_000) * 15
        cost_out = (tokens_out / 1_000_000) * 75
        return cost_in + cost_out

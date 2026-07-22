from __future__ import annotations

import argparse
import asyncio
import os

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from agent import run_agent


app = FastAPI(title="ShopVivaliz Agents", version="0.1.0")


class AgentRequest(BaseModel):
    message: str = Field(min_length=3, max_length=12000)


class AgentResponse(BaseModel):
    ok: bool
    output: str


@app.get("/health")
def health() -> dict[str, object]:
    return {
        "ok": True,
        "service": "shopvivaliz-agents",
        "openai_configured": bool(os.getenv("OPENAI_API_KEY")),
    }


@app.post("/run", response_model=AgentResponse)
async def run(request: AgentRequest) -> AgentResponse:
    try:
        output = await run_agent(request.message)
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=500, detail=type(exc).__name__) from exc
    return AgentResponse(ok=True, output=output)


async def cli(message: str) -> None:
    print(await run_agent(message))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("message", nargs="?", default="Execute uma auditoria pública do ShopVivaliz e reporte evidências.")
    args = parser.parse_args()

    port = os.getenv("PORT")
    if port:
        import uvicorn

        uvicorn.run("main:app", host="0.0.0.0", port=int(port))
        return

    asyncio.run(cli(args.message))


if __name__ == "__main__":
    main()

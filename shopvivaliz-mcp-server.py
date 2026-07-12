#!/usr/bin/env python3
import json, sys, logging
from pathlib import Path

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def get_status():
    return {
        "status": "running",
        "project": "ShopVivaliz",
        "version": "2.0"
    }

def main():
    logger.info("MCP Server ShopVivaliz started")
    print(json.dumps({"ready": True}))
    sys.stdout.flush()
    for line in sys.stdin:
        try:
            req = json.loads(line)
            resp = get_status()
            print(json.dumps(resp))
            sys.stdout.flush()
        except:
            pass

if __name__ == "__main__":
    main()

#!/usr/bin/env python3
import json, sys, logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def main():
    logger.info("MCP Server ShopVivaliz started")
    print(json.dumps({"ready": True}))
    sys.stdout.flush()
    
    try:
        while True:
            line = sys.stdin.readline()
            if not line:
                break
            try:
                req = json.loads(line)
                resp = {"status": "running", "project": "ShopVivaliz", "version": "2.0"}
                print(json.dumps(resp))
                sys.stdout.flush()
            except json.JSONDecodeError:
                pass
    except Exception as e:
        logger.error(f"Error: {e}")

if __name__ == "__main__":
    main()

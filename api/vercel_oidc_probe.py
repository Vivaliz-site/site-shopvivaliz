import json
import os
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler

PROJECT_ID = "prj_hxV5YR2srW8thdYXtwcKAIBDZSn7"
TEAM_ID = "team_SIKJDRk7Q2CTvJRJdttmJBeJ"


class handler(BaseHTTPRequestHandler):
    def do_GET(self):
        token = os.environ.get("VERCEL_OIDC_TOKEN", "")
        result = {
            "oidc_present": bool(token),
            "api_status": None,
            "project_match": False,
        }

        if token:
            url = f"https://api.vercel.com/v9/projects/{PROJECT_ID}?teamId={TEAM_ID}"
            req = urllib.request.Request(
                url,
                headers={"Authorization": f"Bearer {token}"},
                method="GET",
            )
            try:
                with urllib.request.urlopen(req, timeout=15) as response:
                    result["api_status"] = response.status
                    payload = json.loads(response.read().decode("utf-8"))
                    result["project_match"] = payload.get("id") == PROJECT_ID
            except urllib.error.HTTPError as exc:
                result["api_status"] = exc.code
            except Exception:
                result["api_status"] = "error"

        body = json.dumps(result, separators=(",", ":")).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

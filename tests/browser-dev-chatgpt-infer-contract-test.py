import importlib.util
import os
from pathlib import Path
from unittest import mock
import json

ROOT=Path(__file__).resolve().parents[1]
WRAPPER=ROOT/"remote-control-browser-mcp/server.py"
BASE=ROOT/"remote-control-mcp/server.py"

def load(session, cdp):
    old={k:os.environ.get(k) for k in (
        "SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER",
        "SHOPVIVALIZ_BROWSER_SESSION_NAME",
        "SHOPVIVALIZ_BROWSER_CDP_URL",
    )}
    os.environ["SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER"]=str(BASE)
    os.environ["SHOPVIVALIZ_BROWSER_SESSION_NAME"]=session
    os.environ["SHOPVIVALIZ_BROWSER_CDP_URL"]=cdp
    try:
        spec=importlib.util.spec_from_file_location(f"browser_mcp_{session}",WRAPPER)
        module=importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(module)
        return module
    finally:
        for k,v in old.items():
            if v is None: os.environ.pop(k,None)
            else: os.environ[k]=v

def test_chatgpt_infer_tool_is_exposed_only_by_dev_mcp():
    dev=load("dev","http://127.0.0.1:9559")
    atendimento=load("atendimento","http://127.0.0.1:9556")
    dev_names={x["name"] for x in dev.tool_specs()}
    atendimento_names={x["name"] for x in atendimento.tool_specs()}
    assert "browser_chatgpt_infer" in dev_names
    assert "browser_chatgpt_infer" not in atendimento_names
    spec={x["name"]:x for x in dev.tool_specs()}["browser_chatgpt_infer"]
    assert spec["inputSchema"]["required"]==["model","effort","prompt"]
    assert spec["annotations"]["readOnlyHint"] is False
    assert spec["annotations"]["destructiveHint"] is False

def test_dev_infer_owns_and_releases_maintenance_runtime_lock():
    dev=load("dev","http://127.0.0.1:9559")
    dev.CHATGPT_BROWSER_INFER_SCRIPT=str(ROOT/"scripts/chatgpt-continuity/chatgpt-browser-infer.mjs")
    lease={"lease_id":"lease-1","fencing_token":7}
    completed={"exit_code":0,"stdout":json.dumps({
        "ok":True,"text":"{}","model":"gpt-5.6-sol","effort":"xhigh",
        "transport":"chatgpt_browser","profile":"dev"
    }),"stderr":""}
    with mock.patch.object(dev.base.runtime_lock,"acquire_runtime_lock",return_value=lease) as acquire,          mock.patch.object(dev.base.runtime_lock,"release_runtime_lock") as release,          mock.patch.object(dev.base,"run_local_command_with_stdin",return_value=completed) as run:
        out=dev.browser_chatgpt_infer({"model":"gpt-5.6-sol","effort":"xhigh","prompt":"Return JSON"})
    assert out["transport"]=="chatgpt_browser"
    acquire.assert_called_once()
    args=acquire.call_args.args
    assert args[0]=="maintenance"
    assert "browser_chatgpt_infer" in args[3]
    run.assert_called_once()
    release.assert_called_once_with("lease-1",7,"browser_chatgpt_infer_complete")

def test_dev_infer_rejects_non_sol_or_non_xhigh_before_browser_side_effects():
    dev=load("dev","http://127.0.0.1:9559")
    with mock.patch.object(dev.base.runtime_lock,"acquire_runtime_lock") as acquire:
        for payload in (
            {"model":"gpt-5.6-terra","effort":"xhigh","prompt":"x"},
            {"model":"gpt-5.6-sol","effort":"high","prompt":"x"},
        ):
            try: dev.browser_chatgpt_infer(payload)
            except ValueError: pass
            else: raise AssertionError("invalid inference config accepted")
    acquire.assert_not_called()


def test_browser_mcp_installer_deploys_chatgpt_inference_helper():
    setup=(ROOT/"scripts/setup-remote-control-browser-mcp.sh").read_text(encoding="utf-8")
    assert "scripts/chatgpt-continuity/chatgpt-browser-infer.mjs" in setup
    assert "/opt/shopvivaliz-remote-control-browser/chatgpt-browser-infer.mjs" in setup

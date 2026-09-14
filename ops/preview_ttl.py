#!/usr/bin/env python3
import argparse
import json
import re
import subprocess
import time
import urllib.request
from datetime import datetime, timezone

REPO = "fredmourao-ai/solange-rolla-consultorio"
MIN_AGE = 48 * 3600
PROTECTED = {"solange-rolla-consultorio", "solange-client-demo"}
PATTERNS = [
    re.compile(r"^(solange-pr(?P<pr>\d+)(?:-[a-z0-9-]+)?)$"),
    re.compile(r"^(solange-chat-[a-z0-9-]*-pr(?P<pr>\d+))$"),
]


def extract_pr_number(project):
    if project in PROTECTED:
        return None
    for pattern in PATTERNS:
        match = pattern.match(project)
        if match:
            return int(match.group("pr"))
    return None


def should_cleanup(project, pr_state, age_seconds, min_age=MIN_AGE):
    return (
        extract_pr_number(project) is not None
        and pr_state == "closed"
        and age_seconds >= min_age
    )


def run(*args):
    return subprocess.check_output(args, text=True).strip()


def pr_state(number):
    url = f"https://api.github.com/repos/{REPO}/pulls/{number}"
    req = urllib.request.Request(url, headers={"User-Agent": "shopvivaliz-preview-ttl"})
    with urllib.request.urlopen(req, timeout=10) as response:
        return json.load(response)["state"]


def project_candidates():
    names = run("docker", "ps", "-a", "--format", "{{.Names}}").splitlines()
    projects = set()
    for name in names:
        for pattern in PATTERNS:
            match = re.search(pattern.pattern.strip("^$"), name)
            if match:
                projects.add(match.group(1))
    return sorted(projects)


def project_age(project):
    ids = run("docker", "ps", "-aq", "--filter", f"name={project}").splitlines()
    if not ids:
        return 0
    timestamps = []
    for cid in ids:
        raw = run("docker", "inspect", "-f", "{{.Created}}", cid)
        timestamps.append(datetime.fromisoformat(raw.replace("Z", "+00:00")).timestamp())
    return time.time() - min(timestamps)


def matching_resources(kind, project):
    fmt = "{{.Names}}" if kind == "container" else "{{.Name}}"
    args = ["docker", f"{kind}", "ls"]
    if kind == "container":
        args += ["-a", "--format", fmt]
    else:
        args += ["--format", fmt]
    return [name for name in run(*args).splitlines() if project in name]


def remove_project(project, apply=False):
    containers = matching_resources("container", project)
    volumes = matching_resources("volume", project)
    networks = matching_resources("network", project)
    print(f"cleanup project={project} containers={len(containers)} volumes={len(volumes)} networks={len(networks)} apply={apply}")
    if not apply:
        return
    if containers:
        subprocess.run(["docker", "rm", "-f", *containers], check=True)
    for volume in volumes:
        subprocess.run(["docker", "volume", "rm", volume], check=False)
    for network in networks:
        subprocess.run(["docker", "network", "rm", network], check=False)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--min-age", type=int, default=MIN_AGE)
    args = parser.parse_args()
    for project in project_candidates():
        number = extract_pr_number(project)
        try:
            state = pr_state(number)
        except Exception as exc:
            print(f"skip project={project} reason=github_error error={type(exc).__name__}")
            continue
        age = project_age(project)
        print(f"project={project} pr={number} state={state} age={int(age)}")
        if should_cleanup(project, state, age, args.min_age):
            remove_project(project, apply=args.apply)


if __name__ == "__main__":
    main()

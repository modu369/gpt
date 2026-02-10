import subprocess
from pathlib import Path
from typing import Tuple


ACME_SH = "/root/.acme.sh/acme.sh"
WWWROOT = "/var/www/acme-challenge"
CERT_DIR = "/etc/haproxy/certs"


def _run(cmd: list[str]) -> Tuple[bool, str]:
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode == 0:
        return True, p.stdout.strip() or "ok"
    return False, (p.stderr.strip() or p.stdout.strip() or "unknown error")


def ensure_acme() -> Tuple[bool, str]:
    if Path(ACME_SH).exists():
        return True, "acme.sh exists"
    Path(WWWROOT).mkdir(parents=True, exist_ok=True)
    ok, out = _run(["bash", "-lc", "curl -fsSL https://get.acme.sh | sh"])
    return ok, out


def ensure_certificate(domain: str, verify_mode: str = "http") -> Tuple[bool, str, str]:
    ok, out = ensure_acme()
    if not ok:
        return False, "failed", f"install acme.sh failed: {out}"

    Path(WWWROOT).mkdir(parents=True, exist_ok=True)
    Path(CERT_DIR).mkdir(parents=True, exist_ok=True)

    if verify_mode == "http":
        issue_cmd = [ACME_SH, "--issue", "-d", domain, "--webroot", WWWROOT, "--keylength", "ec-256"]
    else:
        return False, "failed", "dns mode requires provider-specific env; not configured"

    ok, issue_out = _run(issue_cmd)
    if not ok:
        return False, "failed", issue_out

    pem_path = f"{CERT_DIR}/{domain}.pem"
    install_cmd = [
        ACME_SH,
        "--install-cert",
        "-d",
        domain,
        "--ecc",
        "--fullchain-file",
        pem_path,
        "--key-file",
        f"{CERT_DIR}/{domain}.key",
        "--reloadcmd",
        "systemctl reload haproxy",
    ]
    ok, install_out = _run(install_cmd)
    if not ok:
        return False, "failed", install_out

    return True, "issued", pem_path

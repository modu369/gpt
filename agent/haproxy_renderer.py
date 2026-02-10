from typing import Dict, List


def render(cfg: Dict) -> str:
    whitelist: List[str] = cfg.get("whitelist", [])
    cf_ips: List[Dict] = cfg.get("cf_ips", [])

    acl_lines = [f"  acl allowed_host hdr(host) -i {d}" for d in whitelist]
    deny_line = "  http-request deny unless allowed_host" if whitelist else ""

    backend_servers = [
        f"  server cf{i} {x['ip']}:{x['port']} check inter 3000 rise 2 fall 2"
        for i, x in enumerate(cf_ips, start=1)
    ]

    return f"""
global
  log /dev/log local0
  maxconn 200000
  daemon
  tune.ssl.default-dh-param 2048

defaults
  mode http
  option httplog
  timeout connect 5s
  timeout client  60s
  timeout server  60s
  option forwardfor

frontend fe_http
  bind *:80
{chr(10).join(acl_lines)}
{deny_line}
  default_backend be_cf

backend be_cf
  balance roundrobin
  http-request set-header X-Forwarded-For %[src]
  http-request set-header X-Real-IP %[src]
{chr(10).join(backend_servers)}

frontend fe_https
  bind *:443 ssl crt /etc/haproxy/certs/ strict-sni
{chr(10).join(acl_lines)}
{deny_line}
  default_backend be_cf
""".strip() + "\n"

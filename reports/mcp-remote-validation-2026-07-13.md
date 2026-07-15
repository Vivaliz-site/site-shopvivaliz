# MCP Remote Validation

- Timestamp: `2026-07-13T19:31:29.486798`
- Online: `1`
- Total: `4`

## Servers
- `windows-local`
  - enabled: `True`
  - status: `online`
  - url: `http://localhost:5555`
  - health_error: ``
- `fred-win`
  - enabled: `False`
  - status: `offline`
  - url: `http://192.168.1.100:5557`
  - health_error: `HTTPConnectionPool(host='192.168.1.100', port=5557): Max retries exceeded with url: /health (Caused by ConnectTimeoutError(<HTTPConnection(host='192.168.1.100', port=5557) at 0x13f60ac3360>, 'Connection to 192.168.1.100 timed out. (connect timeout=3)'))`
- `ubuntu-vm`
  - enabled: `False`
  - status: `offline`
  - url: `http://137.131.156.17:5556`
  - health_error: `HTTPConnectionPool(host='137.131.156.17', port=5556): Max retries exceeded with url: /health (Caused by ConnectTimeoutError(<HTTPConnection(host='137.131.156.17', port=5556) at 0x13f60b783e0>, 'Connection to 137.131.156.17 timed out. (connect timeout=3)'))`
- `github-actions`
  - enabled: `False`
  - status: `offline`
  - url: `http://github-actions-runner:5558`
  - health_error: `HTTPConnectionPool(host='github-actions-runner', port=5558): Max retries exceeded with url: /health (Caused by NameResolutionError("HTTPConnection(host='github-actions-runner', port=5558): Failed to resolve 'github-actions-runner' ([Errno 11001] getaddrinfo failed)"))`

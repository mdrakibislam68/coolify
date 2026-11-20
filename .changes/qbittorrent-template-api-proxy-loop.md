---
type: fixed
---
Login failure and request loops in the qBittorrent one-click service, because `vuetorrent-backend` proxied the Web API to its own public URL

- Set `QBIT_BASE` to `http://qbit:${WEBUI_PORT:-8080}` over the internal compose network instead of the public domain `SERVICE_URL_QBITORRENT`

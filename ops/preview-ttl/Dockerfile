FROM python:3.12-alpine
RUN apk add --no-cache docker-cli
COPY ops/preview_ttl.py /usr/local/bin/preview-ttl
RUN chmod 0755 /usr/local/bin/preview-ttl
ENV INTERVAL_SECONDS=3600
CMD ["sh", "-c", "while true; do /usr/local/bin/preview-ttl --apply; sleep ${INTERVAL_SECONDS}; done"]

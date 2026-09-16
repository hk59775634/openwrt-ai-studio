FROM node:22-alpine

WORKDIR /app
COPY docker/web/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 5173
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

FROM node:22-alpine AS build

WORKDIR /workspace
RUN corepack enable
COPY package.json pnpm-workspace.yaml ./
COPY apps/accounting-web/package.json apps/accounting-web/package.json
COPY packages/akunta-ui/package.json packages/akunta-ui/package.json
RUN pnpm config set dangerouslyAllowAllBuilds true \
    && pnpm install --no-frozen-lockfile
COPY apps/accounting-web apps/accounting-web
COPY packages/akunta-ui packages/akunta-ui
RUN pnpm --dir apps/accounting-web build

FROM nginx:1.27-alpine
COPY docker/accounting-web/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /workspace/apps/accounting-web/build /usr/share/nginx/html
EXPOSE 80

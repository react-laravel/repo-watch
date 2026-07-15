# DogeOW Repo Watch

独立的 GitHub 仓库依赖监控服务：Next.js 前端与 Laravel API 共处一个仓库，使用 DogeOW 一次性 SSO，业务数据存放在独立 `repo_watch` 数据库。

- Web: `web/`
- API: `api/`
- Production: `https://repo-watch.dogeow.com`

## 边界

- 账号与权限由 `next.dogeow.com` 签发的一次性票据提供。
- API 只保存只读身份快照，不连接中央账号数据库。
- `watched_packages` 存在独立 PostgreSQL 数据库 `repo_watch`。
- 前端与 API 通过同域 `/api` 通信，浏览器不跨域共享 Cookie。

## 本地开发

```bash
cd api && composer install && php artisan migrate && php artisan serve --port=8012
cd web && npm ci && npm run dev
```

## 首次上线

服务器上按顺序执行：

```bash
sudo scripts/migrate-production-database.sh
sudo scripts/provision-production.sh
```

随后推送 `main`，self-hosted runner 会先部署 API，再部署 Web，并执行健康检查与失败回滚。

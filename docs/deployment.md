# 生产部署与升级

业务发布使用已审查提交、composer.lock 与 js/dist；生产镜像在 Composer install 前复制本地扩展，运行镜像无需 Node。开发升级顺序：备份、同步锁文件/构建、forum.py setup、campus.py enable（首次）、campus.py migrate、健康与业务回归。迁移只补齐缺失配置，不覆盖已有内容。

正式 2.x 并获得生产迁移授权后，先在独立暂存环境验证全部扩展和恢复，再在维护窗口执行官方 migrate、campus:setup、cache:clear。当前 RC 生产门禁仍生效，正式环境不能 seed 或运行测试。升级失败停止写入并保留现场，用旧代码和备份恢复到新工作区/新数据库，核对个人删除申请，经授权切换；不能直接降级数据库或删除卷。

本次未连接真实服务器、未发布域名、未配置真实证书。当前沿用仓库 Flarum 2.0.0-rc.8，仅用于开发。生产启动、环境桥接与生产镜像构建均拒绝 RC/beta/dev 核心；下述流程是正式 2.x 发布并通过暂存验证后使用的部署骨架，本阶段不能执行 RC 生产部署。PHP 内置服务器禁止用于生产。

## 上线前条件

- 等待 Flarum 正式 2.x 发布，核实[维护政策](https://github.com/flarum/framework/security)，将 minimum-stability 调整为 stable 并重新解析兼容扩展与锁文件、实测后再上线。不能仅修改版本字符串绕过发布检查。
- 准备 Linux、Docker Engine、Compose v2、Python 3.11+、Git 和足够磁盘。
- 确定域名、论坛名称、SMTP、管理员及值班维护者、数据保留期和备份目的地。
- 准备可信的 TLS 证书目录，包含 `fullchain.pem`、`privkey.pem`。证书签发与自动续期由运维配置，不把私钥提交仓库。当前没有内置 ACME 自动续期。
- 服务器防火墙只对公网开放 80/443；SSH 由运维限制来源；3306/9000 不发布。

## 配置独立的生产环境

复制 `.env.example` 到 `.env.production`，保存在服务器私有目录或本工作区忽略路径，权限 600。填写：

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<实际域名>
FORUM_TITLE=<正式论坛名称>
FORUM_HOST=<实际域名，不带协议和路径>
TLS_CERT_DIR=<服务器证书目录绝对路径>
COMPOSE_PROJECT_NAME=campus-forum-production
DB_NAME=campus_forum_production
HTTP_BIND=127.0.0.1
HTTP_PORT=8080
BACKUP_DIR=<服务器私有备份目录绝对路径>
```

其余数据库参数使用示例中的有效用户标识符，DB_PASSWORD/DB_ROOT_PASSWORD 留空交给 setup 生成。不要复制本地开发密码。`HTTP_*` 仅用于下述回环初始化环境；生产固定开放 80/443。域名和证书由部署者提供，此处没有有效生产默认值。

## 全新安装：先私有初始化，再切换正式服务

所有操作使用同一个 `.env.production` 和 Compose 项目名，确保复用同一组命名卷。不得在生产实例上运行这段初始化流程。

```console
python scripts/forum.py --env-file .env.production doctor
python scripts/forum.py --env-file .env.production setup
python scripts/forum.py --env-file .env.production start
python scripts/forum.py --env-file .env.production install
python scripts/forum.py --env-file .env.production stop
python scripts/forum.py --profile production --env-file .env.production setup
python scripts/forum.py --profile production --env-file .env.production start
python scripts/forum.py --profile production --env-file .env.production healthcheck
```

第一组使用开发定义临时在 `127.0.0.1:8080` 初始化**生产独立数据库**，不对公网暴露向导，实际 Flarum URL 仍是配置的 HTTPS 正式地址。CLI 安全询问管理员信息。初始化后停止服务，改为不可写源代码镜像和 HTTPS Nginx；不删除数据卷，不把本地开发数据带进生产。

如果服务器上已安装论坛，跳过初始化：先备份并核对项目名、锁文件、数据卷与配置，再构建生产镜像。`APP_ENV=production` 时脚本和 PHP 桥接都要求 HTTPS 和 debug=false。

检查 Nginx：

```console
docker compose --env-file .env.production -f compose.production.yaml exec -T web nginx -t
docker compose --env-file .env.production -f compose.production.yaml ps
```

应用以 www-data 运行，代码只读，只有 assets/storage 卷和 `/tmp` 可写。Web 只读挂载 assets 和证书目录。数据库卷只供 MariaDB 使用。生产 Web 的 `/healthz` 仅证明 Nginx 工作，PHP 健康检查验证已安装数据库，管理脚本再验证外部 HTTPS 首页/API。

完成 [安全检查](security.md)，在后台配置 SMTP、关闭未经评估的公开注册、审核默认用户权限。确认 HTTPS、头像和登录正常后再公开地址。确认告警和备份周期后由运维定期执行 backup；当前没有自动调度或通知实现。

## 日志和日常操作

使用 `python scripts/forum.py --profile production --env-file .env.production status`、`logs`、`healthcheck`、`backup`、`backups`、`stop` 和 `start`。

容器日志每份最多 10MB，轮转三份；Nginx 访问日志省略查询参数。Flarum 日志在 storage 卷中，应由运维制定轮转与保留期；当前仅配置了容器日志轮转。证书续期后执行 `nginx -t` 再 reload。管理终端和 Docker socket 有高权限，只开放给部署维护者。

## 版本升级

1. 在独立开发/暂存环境重新核实官方发行版、PHP/数据库支持期、扩展兼容性和漏洞；参考 [Flarum 官方升级说明](https://docs.flarum.org/update/)。
2. 修改 Composer 约束并执行明确的 `composer update --with-all-dependencies --no-dev --no-plugins --no-scripts`，审查锁文件。原生可用 `.tools/php/php.exe .tools/composer.phar`；Docker 可用 `docker compose run --rm --no-deps app composer`。正常同步仍使用 install。
3. 修改 Dockerfile/Compose 的明确版本与 `runtime-lock.json` 的官方 URL 和 SHA256，记录 CHANGELOG。发布时进一步锁定已验证镜像 digest，保留回滚镜像。
4. 执行完整 smoke、备份恢复演练与审计，确认已有数据迁移正确。RC 升正式版时重新核实扩展约束、迁移与配置变化；当前 MariaDB driver 已为 mariadb，没有漏洞忽略例外。
5. 生产先备份，停止应用，构建新镜像。按新版本官方要求通过一次性容器运行迁移与清缓存，再启动服务：

```console
python scripts/forum.py --profile production --env-file .env.production backup
python scripts/forum.py --profile production --env-file .env.production stop
python scripts/forum.py --profile production --env-file .env.production setup
docker compose --env-file .env.production -f compose.production.yaml up -d --wait db
docker compose --env-file .env.production -f compose.production.yaml run --rm --no-deps app php flarum migrate
docker compose --env-file .env.production -f compose.production.yaml run --rm --no-deps app php flarum cache:clear
python scripts/forum.py --profile production --env-file .env.production start
python scripts/forum.py --profile production --env-file .env.production healthcheck
```

迁移前只启动数据库，应用保持停止。不要在应用仍可写入时升级。

6. 检查登录、权限、资源和业务记录，记录运维结果。数据库迁移后的回滚可能需要将备份恢复到新环境，不能仅启动旧镜像或默认覆盖数据库。

## 尚待生产环境验证

开发镜像构建、Compose 运行、Nginx 配置与 Docker 备份恢复已通过 GitHub Actions。生产镜像、只读卷权限、证书读取与续期、HTTPS、外发邮件、生产恢复和告警仍需正式版发布后的暂存环境验证。没有生产服务器凭据不妨碍完成本地基础设施。

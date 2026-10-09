# Phase 0 环境检查与实际验证报告

验证日期：2026-10-09（Asia/Shanghai）。Phase 0 开发工程已完成；生产上线等待正式 Flarum 2.x。此报告区分实际执行与尚待验证事项。

## 环境与版本

- 工作区：`E:\Admin\Documents\ChatGPT\UCASSer`；Windows 11 专业工作站版 10.0.26100，x64。
- 原仓库默认分支 `2.x`，基线提交 `3d0a0c2`；初始为未安装的官方 Composer 骨架，无既有用户数据，无 AGENTS.md 或 CI。
- Git 2.49.0.windows.1、Python 3.13.5、Node 22.18.0 可用。系统 PHP、Composer、Docker、Compose 不可用；WSL 组件/发行版未安装。
- 本机与交付清单统一使用 **Flarum 2.0.0-rc.8**。核心 `^2.0.0-rc.8`、全部官方扩展约束、beta + prefer-stable 及原有 Composer 设置与上游逐项比对一致；仅新增 PHP `^8.3` 要求和精确锁文件。
- 实际安装 166 个锁定依赖包。便携 PHP 8.3.35、Composer 2.10.3、MariaDB 11.4.13 的官方下载和 SHA256 固定于 `scripts/runtime-lock.json`，已校验；未安装系统服务。
- Compose 固定 PHP 8.3.35 / Composer 2.10.3 / MariaDB 11.4.13 / Nginx 1.30.1，官方 Registry 标签检查均返回 200。标签存在不能代替容器运行验证。

版本依据与官方安装资料见 [ADR 001](decisions/001-runtime.md)。原仓库没有指定 PHP、数据库或 Web 运行时版本，因此根据 Flarum 2.x 要求补充这些版本。本机数据库驱动与配置桥接已适配 2.x 的 `mariadb` 和同步队列。

此前临时 1.x 的本机测试配置、日志、storage/assets 已私下保存在 `.runtime/phase0-v1-preserved/`，旧数据库 `campus_forum` 保留。当前使用独立新数据库 `campus_forum_v2`，没有删除旧数据、自动迁移或向旧库覆盖安装。1.x 不是当前交付基线。

## 实际检查与结果

| 执行项 | 实际结果 |
|---|---|
| 官方运行时下载及校验、PHP doctor | 通过，必需扩展存在，数据库仅回环监听 |
| `composer update --no-dev --prefer-dist --no-interaction --no-plugins --no-scripts` | 按上游清单解析锁文件；两个官方 ZIP 下载写入失败，改用官方地址下载、校验 ZIP 后补充本机缓存 |
| `composer install --prefer-dist --no-dev --no-interaction --no-plugins --no-scripts` | 缓存补充后通过，全部依赖安装完成 |
| `composer validate --strict --no-check-all` | 通过；仅跳过上游扩展通配约束的风格警告，保留锁文件一致性检查 |
| `composer check-platform-reqs --no-dev` | 通过，PHP 8.3.35 满足安装平台要求 |
| `python scripts/audit.py --backend native` | 通过；锁定依赖无已报告漏洞或停止维护依赖，没有忽略项 |
| `python -m unittest discover -s tests -p "test_*.py" -v` | 14 项通过：环境解析、归档路径/符号链接、备份失败处理、恢复保护、生产版本/HTTPS/调试限制与审计行为 |
| `python scripts/check.py` | Python AST、JSON、YAML、空秘密示例与 Git 排除检查通过 |
| PHP `-l`：environment、release、db、router、release CLI、index、site、extend、flarum | 9 个入口/配置语法检查通过 |
| `php scripts/release.php` | 按预期非零，明确拒绝 RC 生产构建；不计为失败的开发测试 |
| `python tests/smoke.py --backend native` | 全新 2.x 安装、登录、首页/API、重启、持久化、备份、隔离恢复及重复恢复拒绝全部通过 |
| 实际首页引用的 CSS / JS HTTP 请求 | forum.css、forum.js、forum-en.js 均 200 且非空 |
| `python scripts/forum.py --backend native versions` | core 2.0.0-rc.8、PHP 8.3.35、MariaDB 11.4.13、debug off；Flarum 提示推荐 MariaDB 11.8，当前 11.4.13 已完成实际安装/恢复验证 |
| core/扩展清单与 `origin/2.x` 比对 | 上游约束与设置全部保留；无降级 |
| Git 排除与交付文件秘密扫描 | 私有配置、运行时、依赖、数据库和备份不交付；生成秘密未出现在交付文件 |

## 备份与隔离恢复证据

- 备份：`backups/forum-campus_forum_v2-20261009T143131Z-ec982a.zip`，同目录包含外层 `.sha256`，归档含逐文件校验和。
- 隔离恢复目录：`.runtime/restore-verification-2b19d03e/`。
- 原数据库 `campus_forum_v2`：3307；恢复数据库 `phase0_restore_2fe8345b`：3308，独立数据目录与随机新数据库密码。
- 真实 Flarum settings 测试记录和上传文件字节在重启、SQL 导出/导入与文件恢复后保持一致。
- 原实例和恢复实例均验证管理员 API 登录、首页/API，以及配置、隐藏路径、storage 与已存在的上传 PHP 文件不可访问。
- 再次向恢复实例导入被拒绝；原实例继续健康。恢复实例已停止，原实例保留运行于 `http://127.0.0.1:8080`。
- 机器可读证据 `.runtime/verification.json` 标记 `flarum=v2.0.0-rc.8`、`result=passed`。测试数据库、备份和报告均被 Git 忽略。

备份覆盖全部数据库、assets/storage、扩展入口、配置桥接及依赖版本，不含 `.env` 或明文数据库密码。SQL 含用户密码哈希，日志可能含个人信息，备份仍按敏感数据私有保存。Windows 的文件模式不能替代 NTFS ACL 管理。

## 未实测与后续边界

本机没有 Docker Engine/Compose；容器构建、Compose 服务、Nginx `-t`、Docker 备份恢复不能声称已本机通过。GitHub Actions 定义了上述真实 Docker 检查，执行结果以 PR 的 Checks 为准。生产 HTTPS、证书续期、只读卷权限、SMTP、生产恢复与告警需后续暂存/生产验证；本阶段没有连接生产服务器。

生产受到三层发布限制：Python 校验、PHP 环境桥接和生产 Dockerfile 构建都要求锁定的正式 2.x，当前 RC 被拒绝。等待上游正式发布后重新解析依赖、验证迁移、扩展和恢复，再上线。

## 交接与文件范围

新开发者按 [development.md](development.md) 使用同一 Git 提交、composer.lock 与运行时版本建立独立环境。尚未合并时检出 `codex/phase0-infrastructure`。日常本机启动：

```console
python scripts/forum.py --backend native start
python scripts/forum.py --backend native healthcheck
```

本机测试管理员 `phase0_admin` 的随机密码仅在 `.runtime/admin-initial-password.txt`，未打印或上传。其他开发者应自行安全初始化，不共享该账号文件。生产域名、服务器、证书和 SMTP 尚未提供，待正式版上线阶段处理。

本次交付包含环境配置、Docker/生产骨架、管理/检查脚本、真实测试和中文开发运维文档；保持上游核心入口和许可证。具体文件清单：

- `.gitignore`
- `CHANGELOG.md`
- `README.md`
- `composer.json`
- `.dockerignore`
- `.env.example`
- `.github/workflows/ci.yaml`
- `compose.production.yaml`
- `compose.yaml`
- `composer.lock`
- `config/environment.php`
- `config/release.php`
- `docker/php/Dockerfile`
- `docker/php/Dockerfile.production`
- `docker/php/php.ini`
- `docker/php/zz-forum.conf`
- `docker/web/Dockerfile.production`
- `docker/web/default.conf`
- `docker/web/production.conf.template`
- `docs/architecture.md`
- `docs/backup-recovery.md`
- `docs/decisions/001-runtime.md`
- `docs/deployment.md`
- `docs/development.md`
- `docs/permissions.md`
- `docs/roadmap.md`
- `docs/security.md`
- `docs/upstream-README.md`
- `docs/verification.md`
- `scripts/audit.py`
- `scripts/check.py`
- `scripts/db.php`
- `scripts/forum.py`
- `scripts/release.php`
- `scripts/router.php`
- `scripts/runtime-lock.json`
- `tests/requirements.txt`
- `tests/smoke.py`
- `tests/test_launcher.py`

本机生成且不上传：`.env`、`config.php`、`vendor/`、`.tools/`、`.runtime/`、数据库文件、运行缓存、上传资源与 `backups/`。校园业务、中文语言包、匿名机制、课程算法和复杂资料库尚未实现；下一阶段前三项见 [roadmap.md](roadmap.md)。

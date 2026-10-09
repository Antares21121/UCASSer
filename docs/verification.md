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
| GitHub Actions：Ubuntu Docker 全流程 | 提交 `f3b1503` 的镜像构建、Compose/PHP/Nginx 检查、真实安装/重启/备份/隔离恢复与严格审计通过，见下方远程记录 |

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

本机没有 Docker Engine/Compose；不能声称容器已本机运行。上述 Docker 检查已在 GitHub Actions 的 Ubuntu 24.04 环境实际通过：[成功执行记录](https://github.com/Antares21121/UCASSer/actions/runs/37946896525)，对应提交 `f3b1503`。PR 为 [#1](https://github.com/Antares21121/UCASSer/pull/1)，目标分支 `2.x`；最新提交结果仍以 PR 的 Checks 为准。

首轮 Docker smoke 在安装后返回 500，脱敏日志定位到新 storage 卷缺少 formatter/sessions 子目录；已通过镜像预建和 setup 幂等补齐目录修复，没有删卷或清空数据。修复后 Docker smoke 全流程和审计通过。CI 仅上传不含凭据的 verification.json，失败诊断会脱敏数据库/邮件/测试管理员密码，不上传数据库、备份或完整环境配置。

生产 HTTPS、证书续期、只读卷权限、SMTP、生产恢复与告警需后续暂存/生产验证；本阶段没有连接生产服务器。

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

本机生成且不上传：`.env`、`config.php`、`vendor/`、`.tools/`、`.runtime/`、数据库文件、运行缓存、上传资源与 `backups/`。上述内容为 Phase 0 历史记录；当前校园业务状态以下面的阶段 1–12 验收为准。

## 阶段 1–12 开发验收（2026-10-10）

本机沿用 Flarum 2.0.0-rc.8、PHP 8.3.35、MariaDB 11.4.13，新增本地校园扩展。Composer 共 167 包，原有 166 包版本未改变；Node 22.18.0/npm 10.9.3 使用新 package-lock.json 构建自己的前端。不修改 vendor，不向正式环境写入。

| 实际执行 | 结果 |
|---|---|
| campus enable/migrate/setup、重复 seed | 七项迁移和缺失配置补齐，正常；种子只补齐标注示例，不清空已有数据 |
| `python tests/campus_smoke.py --backend native --phase 11` | 阶段 1–11 全部通过；真实数据库、邮件、账号、权限、四类内容、样本、通知、搜索、举报与治理 |
| 集中安全及个人数据回归 | CSRF、封禁/解封、危险 URL、数组筛选、重复提交、登录限流、原生编辑历史、GDPR 归属及材料清除通过 |
| 受限分区的通知/个人中心/导出 | 列表与直接通知、回复邮件、收藏、本人记录及校园导出均过滤；实际 ZIP 检查通过 |
| `npm run test:browser`（Chrome，串行） | 6 项全部通过，2.3 分钟；发布/预览/编辑/评论、课程/评价/资料/采纳、个人中心、搜索、移动、后台验证、举报/申诉/独立复核/公开治理；课程受限不阻断搜索 |
| `python tests/smoke.py --backend native --resume` | 已有 smoke 实例重启、真实业务 SQL/文件/源码备份、新数据库恢复及业务统计一致；源码不匹配和重复恢复拒绝，原实例健康 |
| `python scripts/dev_mail.py --backend native --check` | SMTP 本地收信、纯文本邮箱读取、旧设置恢复通过；未向外部投递 |
| 静态、PHP 语法、管理脚本行为 | Python AST/JSON/YAML/Git 排除与 PHP 语法通过；14 项行为测试全部通过 |
| Composer validate / audit、npm audit | 通过，无已报告漏洞或停止维护包；没有忽略公告 |
| GitHub 新业务 PR CI | 待实际远程运行确认；流程已包含 Linux Docker 新安装、业务隔离恢复、阶段 1–11、Chromium 与依赖审计 |

尚未上线：正式 HTTPS、外发 SMTP、只读生产卷、异地加密备份/调度/告警和稳定 2.x 升级。匿名评价按路线图允许的方案关闭，后端拒绝匿名请求；原生语言仍为已安装英文包，校园模块为中文。当前搜索验收包含 23 条匹配记录的真实分页，规模扩大后需另行压测。完整命令见 [testing.md](testing.md)，新开发者环境同步见 [development.md](development.md)。

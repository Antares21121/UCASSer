# 校园公共论坛 · UCASSer

以校园生活参与者共同维护的公益公共论坛。永久免费，已实现校园信息、资料索引、问答、课程评价、搜索、个人中心和举报治理。入口见 [功能清单](docs/features.md)，状态见 [路线图](docs/roadmap.md)。论坛名称、正式域名、邮件服务和生产服务器均由部署者配置。

技术栈：Flarum 2.0.0-rc.8、PHP 8.3.35、Composer 2.10.3、MariaDB 11.4.13、Nginx 1.30.1；管理脚本使用 Python 3.11+ 标准库。依赖版本由 `composer.lock` 固定。

**版本边界**：沿用仓库 `2.x` 的 `^2.0.0-rc.8` 核心与官方扩展清单，本机与团队使用同一锁文件。当前锁定核心为 RC，仅开发使用；生产启动、配置桥接和镜像构建均拒绝 RC/beta/dev 核心，升级为正式 2.x 后再验证上线。见 [版本决策](docs/decisions/001-runtime.md)。

## 快速开始：团队首选 Docker Compose

安装 Git、Python 3.11+、Docker Engine/Desktop 和 Compose v2（建议 2.24+）。Windows Docker Desktop 需要可用的 WSL2 或 Hyper-V 后端，Linux 容器模式。

本轮 PR 合并前，克隆后先执行 `git switch codex/phases1-12`；合并后使用默认 `2.x` 分支。

```console
git clone https://github.com/Antares21121/UCASSer.git
cd UCASSer
python scripts/forum.py doctor
python scripts/forum.py setup
python scripts/forum.py start
python scripts/forum.py install
python scripts/forum.py healthcheck
```

打开 `http://127.0.0.1:8080`。setup 仅在 `.env` 不存在时初始化配置并生成本机随机数据库密码；install 在终端安全询问管理员账号、邮箱和密码，拒绝再次安装或使用非空数据库。不要让安装向导直接暴露于公网。

安装后初始化业务（Node 22.18.0、npm 10.x）：

```console
npm ci
npm run build
python scripts/campus.py enable
python scripts/campus.py migrate
python scripts/campus.py seed
```

打开 `http://127.0.0.1:8080/campus`。seed 仅用于开发，重复执行只补齐标注「【示例】」的数据。已有数据库升级前先备份。

## Windows 无 Docker 的替代方案

```console
python scripts/forum.py --backend native bootstrap-native
python scripts/forum.py --backend native setup
python scripts/forum.py --backend native start
python scripts/forum.py --backend native install
python scripts/forum.py --backend native healthcheck
```

下载版本和校验值由 `scripts/runtime-lock.json` 固定，所有工具和数据库均在工作区，不安装系统服务。只绑定 `127.0.0.1`，不能用于生产。首次下载需连接官方 PHP、MariaDB、Composer 和 GitHub/Packagist。

业务初始化同上，Python 命令加 `--backend native`。另一终端运行 `python scripts/dev_mail.py --backend native`，在 `http://127.0.0.1:8026` 查看本地注册确认和重置邮件。

## 常用命令

| 行为 | 命令 |
|---|---|
| 检查工具 | `python scripts/forum.py doctor` |
| 初始化 / 同步锁定依赖 | `python scripts/forum.py setup` |
| 启动 | `python scripts/forum.py start` |
| 停止（保留数据） | `python scripts/forum.py stop` |
| 状态 / 日志 | `python scripts/forum.py status` / `python scripts/forum.py logs` |
| 应用与数据库健康 | `python scripts/forum.py healthcheck` |
| 备份 / 列出备份 | `python scripts/forum.py backup` / `python scripts/forum.py backups` |
| 依赖与运行版本 | `python scripts/forum.py versions` |

Windows 替代方案在脚本名之后加 `--backend native`，例如 `python scripts/forum.py --backend native stop`。恢复必须使用新工作区、新数据库和明确目标，操作见 [备份恢复](docs/backup-recovery.md)。

## 协作与验证

- [开发环境同步手册](docs/development.md)：新开发者按文档安装相同工具、依赖和配置，不复制队友密码或数据库。
- [架构](docs/architecture.md)、[部署](docs/deployment.md)、[安全](docs/security.md)、[权限](docs/permissions.md)。
- [本次环境与验证报告](docs/verification.md)：Windows 实际安装、重启持久化、备份及隔离恢复已经验证；Docker/HTTPS 未在本机运行。
- 自动检查：`python -m pip install -r tests/requirements.txt`，然后 `python scripts/check.py` 和 `python -m unittest discover -s tests -p "test_*.py" -v`。
- `python tests/smoke.py --backend docker` 是会真实安装和重启服务的集成检查，只用于全新、无用户数据的开发环境。

保留 Flarum 原有 `site.php`、`public/`、`storage/`、`extend.php` 和许可证。上游说明存于 [upstream-README.md](docs/upstream-README.md)。不提交 `.env`、管理员凭据、`vendor/`、工具、运行时数据或备份。

业务交接：[功能](docs/features.md)、[权限](docs/permissions.md)、[隐私](docs/privacy.md)、[治理](docs/moderation.md)、[测试](docs/testing.md)。匿名评价关闭；生产等待稳定 2.x 及正式 HTTPS/SMTP/备份配置验收。

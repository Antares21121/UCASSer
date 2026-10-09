# 本地开发环境与团队同步

新开发者从相同 Git 提交、相同 `composer.lock` 和 `scripts/runtime-lock.json` 建立环境，不复制其他人的 `.env`、数据库、管理员密码或 `vendor/`。

## 统一版本

| 工具 / 组件 | 项目基线 | 获取与用途 |
|---|---|---|
| Git | 2.x，本次为 2.49.0 | 克隆与分支协作 |
| Python | 3.11+，CI 3.13，本次 3.13.5 | 管理脚本使用标准库 |
| Docker + Compose | Linux 容器；Compose v2，建议 2.24+ | 团队首选，不需要宿主 PHP |
| PHP | 8.3.35 | 镜像固定标签 / Windows 固定下载与 SHA256 |
| Composer | 2.10.3 | 镜像 / 固定 PHAR 与 SHA256 |
| MariaDB | 11.4.13 LTS | Compose 私有网络 / Windows 便携版 |
| Nginx | 1.30.1 | Compose Web 服务；Windows 替代方案不使用 Nginx |
| Flarum | 核心 2.0.0-rc.8 | `^2.0.0-rc.8` + 精确 `composer.lock`，沿用上游 beta + prefer-stable |

Flarum 官方安装要求见 [2.x 安装指南](https://docs.flarum.org/install/)。项目启用 curl、dom、fileinfo、gd、json、mbstring、openssl、pdo_mysql、tokenizer、zip，并增加 intl、OPcache（容器）。官方 Composer 安装及现有骨架兼容，Web 根目录固定为 `public/`。RC 开发与正式生产边界见 [ADR 001](decisions/001-runtime.md)。

## 方案一：Docker（Windows / macOS / Linux）

1. 安装 Git、Python 和 Docker；Windows 启用 Docker Desktop 的 Linux 容器后端。启动 Docker 引擎并确认当前账号有权限。无需安装宿主 PHP 或 MariaDB。
2. 克隆仓库并进入根目录。Phase 0 尚未合并时，执行 `git switch codex/phase0-infrastructure`；合并后使用默认 `2.x` 分支。
3. 检查和初始化：

```console
python scripts/forum.py doctor
python scripts/forum.py setup
```

`setup` 生成本机 `.env` 和随机密码，构建固定版本镜像，运行 `composer install --no-dev --no-plugins --no-scripts`。已有 `.env` 不重置字段或密码；已有 `composer.lock` 不做依赖升级。生成密码只保存在私有文件中，终端不打印。

核心和官方扩展约束沿用仓库原始清单，扩展 `*` 的实际版本由 composer.lock 固定。Composer 清单检查使用 `validate --strict --no-check-all`，仅跳过上游通配符/精确约束的风格警告，仍验证配置和锁文件一致性。

4. 检查 `.env` 中的端口、名称和 `APP_URL`。默认 `http://127.0.0.1:8080`。修改端口时同时修改 `HTTP_PORT` 和 `APP_URL`。不要把 `HTTP_BIND` 改为公网地址来分享安装向导。
5. 启动并通过官方 CLI 初始化：

```console
python scripts/forum.py start
python scripts/forum.py install
python scripts/forum.py healthcheck
python scripts/forum.py status
```

管理员密码至少 16 字符，在隐藏输入提示中填写；它不会写入仓库或长期保留在安装 JSON 中。安装临时文件成功或失败后均删除。CLI 完成后 `config.php` 改为环境桥接。不要再运行网页安装向导。安装失败时保留数据库并查看错误，不删除数据重试。

6. 打开地址，登录后台设置论坛名称、注册政策、邮件和权限。邮件未配置时不能把找回密码、注册确认等业务视为可用。

## 方案二：Windows 便携环境（本机已实测）

要求 Windows x64、Python 3.11+、Git、联网下载权限以及可运行 PHP 的 Visual C++ 2015–2022 x64 运行库。若出现 DLL 缺失，使用微软官方运行库安装包；管理脚本不安装系统组件。

```console
python scripts/forum.py --backend native bootstrap-native
python scripts/forum.py --backend native setup
python scripts/forum.py --backend native start
python scripts/forum.py --backend native install
python scripts/forum.py --backend native healthcheck
```

`bootstrap-native` 下载并校验运行时到 `.tools/`，不修改系统 PATH、不安装服务、不创建公网数据库监听。重复运行验证缓存，版本不符时拒绝覆盖已安装工具。PHP CA 文件从操作系统信任库导出，不关闭 TLS 校验。

PHP 监听 `127.0.0.1:8080`，MariaDB 监听 `127.0.0.1:3307`。脚本将 `DB_HOST/DB_PORT` 转换为本机值，因此 `.env.example` 中 Docker 的 `db:3306` 不会误用于 Windows。修改数据库端口用 `NATIVE_DB_PORT`。停止等待 MariaDB 进程真实退出，避免重启时文件锁冲突。

Windows PHP 不在 PATH 属于正常情况，所有命令由脚本选择 `.tools/php/php.exe`。不要在此方案与 Docker 之间切换同一工作区的运行数据；它们使用不同的数据库和文件存储。需要迁移时参照 [备份恢复](backup-recovery.md)。

## 环境变量如何生效

`.env` 使用 `KEY=value`、单行文本，可带一对单/双引号；不支持 shell 展开、变量插值、多行值或行尾注释。秘密值中不要使用换行。原生脚本显式加载文件，Compose 显式传递应用环境。

| 变量 | 实际作用 |
|---|---|
| `APP_ENV`, `APP_DEBUG` | 安全校验、Flarum `debug`；生产强制 false |
| `APP_URL` | Flarum `url`；必须与访问地址一致 |
| `FORUM_TITLE` | 仅首次 CLI 安装写入数据库，之后由后台维护 |
| `DB_*` | Flarum `database` 配置；2.x 对 MariaDB 使用 mariadb driver |
| `DB_ROOT_PASSWORD` | 数据库初始化；应用容器内清空，不给 PHP 运行进程 root 凭据 |
| `HTTP_BIND`, `HTTP_PORT` | 开发 Web 发布地址；native 强制回环地址 |
| `COMPOSE_PROJECT_NAME` | 隔离 Compose 网络与数据卷；已有环境不得随意改名 |
| `TZ` | PHP / 容器时区；备份文件名固定 UTC |
| `BACKUP_DIR` | 本机备份目录，不自动上传第三方 |
| `MAIL_*` | 交接占位项，尚未同步到 Flarum；在后台配置 SMTP |
| `LOG_LEVEL` | 运维占位项；当前 Nginx warn、PHP 排除弃用警告，非动态日志级别 |

其他配置文件可用 `--env-file .env.team-local` 指定。Docker 脚本会同时设置 Compose 应用使用该文件。禁止把 `docker compose config` 的完整输出发到聊天或工单：其中可能包含密码；检查使用 `config --quiet`。

## 日常协作和依赖同步

```console
git pull --ff-only
python scripts/forum.py setup
python scripts/forum.py start
python scripts/forum.py healthcheck
```

Windows 给这些命令加 `--backend native`。更换 PHP/数据库镜像前阅读变更说明；不要自动删除数据卷以修复启动问题。上游依赖新增迁移时先备份，再按部署文档手动运行 `php flarum migrate` 和清缓存。

业务开发放在独立 Flarum 扩展或 `extend.php`，禁止改 `vendor/flarum/core`。自定义扩展先说明包名、支持的 Flarum 版本、存储路径与权限，再使用 Composer path repository 开发；本阶段未创建业务扩展，Node/npm 构建也未引入。

提交前执行：

```console
python -m pip install -r tests/requirements.txt
python scripts/check.py
python -m unittest discover -s tests -p "test_*.py" -v
```

依赖安全检查：Docker `python scripts/audit.py --backend docker`，Windows `--backend native`。审计不忽略漏洞，任何已报告漏洞或停止维护依赖都会使检查失败；实际结果见验证报告。

集成测试 `python tests/smoke.py --backend docker` 或 `--backend native` 仅针对全新开发实例。会创建测试管理员、真实数据库、备份以及独立恢复环境；不会删除已有数据。失败后 `--resume` 只允许继续该测试创建的实例。成功后原实例保持运行、恢复实例停止。

## 常见故障

- Docker 找不到或引擎未运行：启动 Docker Desktop / Engine；没有 Docker 的 Windows 使用便携方案。当前机器 WSL 未安装，脚本不会自动安装 WSL 或重启机器。
- 端口已占用：停止本项目服务后修改 `.env` 的端口与访问地址；不要终止未知进程。
- 下载慢：本次 GitHub zip 下载曾超时，Composer 最终完成。保留缓存和锁文件重试 `setup`，不更换未核实的镜像源、不关闭 HTTPS。
- MariaDB 无法连接：检查 `.runtime/db.log`、端口和 PID 状态；查看 `.runtime/last-error.log`。已有数据库的密码不会因修改 `.env` 自动变化，应恢复原配置或明确执行凭据轮换。
- HTTP 500：先查看 `storage/logs/`、PHP 日志、环境桥接、文件权限和数据库。默认关闭向浏览器显示详细错误。
- `.runtime/last-error.log` 只记录最近一次错误，已知秘密会脱敏；转发日志前仍应审查个人信息。Windows 原始 Web 日志可能包含查询参数，`logs` 命令会隐藏它们。

本次机器检查与已验证范围详见 [verification.md](verification.md)。

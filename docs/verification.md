# 工程与校园业务实际验证报告

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

## 阶段 1–12 开发验收（2026-10-10，原生重构前）

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
| 全新 Windows 业务安装 | 独立工作区、新数据库、新端口：安装、七项迁移、分区配置及 14 条标注示例创建通过；原开发实例保留且健康 |
| GitHub 新业务 PR CI | `a40bec6` 全流程通过；Linux Docker 新安装、重启、业务隔离恢复、阶段 1–11、六项 Chromium 流程及依赖审计 |

尚未上线：正式 HTTPS、外发 SMTP、只读生产卷、异地加密备份/调度/告警和稳定 2.x 升级。匿名评价按路线图允许的方案关闭，后端拒绝匿名请求；该轮原生界面使用英文包，当前简体中文与原生深化结果见下节。当前搜索验收包含 23 条匹配记录的真实分页，规模扩大后需另行压测。完整命令见 [testing.md](testing.md)，新开发者环境同步见 [development.md](development.md)。

远程证据：[PR #2 验收](https://github.com/Antares21121/UCASSer/actions/runs/37979625114)、[分支推送验收](https://github.com/Antares21121/UCASSer/actions/runs/37979619401)。两次运行使用业务修复提交 `a40bec6`；后续仅交接文档变更的提交，其 checks 仍以对应 GitHub 记录为准。

全新环境最初发现两项目录准备问题：便携实例缺少 formatter 缓存目录；Linux 的锁定审计扩展声明空的 common 前端目录，但 Composer 包不包含空目录，运行账户无法在依赖路径创建它。`scripts/prepare-runtime.php` 在安装阶段补齐目录，setup 使用一次性 Docker 容器准备，不依赖服务已启动。生产镜像也在构建阶段准备；没有修改依赖源码、依赖版本或授予运行账户 vendor 写权限。上述 Windows 全新安装与 Linux 全流程均在修复后通过。

## 原生深度定制验收（2026-10-10）

原生首页、顶栏、十个分区信息流、讨论详情、发布/编辑及个人空间已改为核心组件扩展。统一蓝色，注册简体中文与中文时间，分区默认图标各有对应含义。课程、资料、问答、活动和机会使用真实字段及不同阅读结构；保持原生回复、权限、分页和互动。实现与环境同步说明见 [native-ui.md](native-ui.md)。

| 本机实际执行 | 结果 |
|---|---|
| `npm run build` | 成功，源码及 forum/admin 构建产物同步；未修改两个依赖锁文件 |
| 原生界面浏览器验收（2026-10-10） | Chrome 串行 10 项全部通过，5.1 分钟；含访客登录引导、手机原生菜单、两种 503 草稿保留与实际重试 |
| 阶段 1–11 API 完整回归 | 全部通过；新增原生检索 20+3 分页、公开字段边界、无效筛选拒绝及受限分区；举报队列与身份授权接口隔离通过 |
| 14 项管理脚本、静态、PHP、Composer validate/platform | 全部通过 |
| Composer 与 npm 审计 | 通过，无已报告漏洞或停止维护依赖，没有忽略项 |
| 桌面与移动视觉检查 | 蓝色、图标字体实际渲染、相对时间中文；首页及课程/资料/问答/活动无横向溢出、无脚本错误 |

浏览器覆盖十个板块、320/390/768px、中文原生操作、深色主题、硬刷新摘要、板块筛选及发布默认值；课程维护与正常账号评价、资料、问答采纳和答案定位、收藏、私有纠错处理与历史；注册验证及举报→处理→申诉→独立复核；503 后保留草稿及重试、北京时间转 UTC。

错误重试发现原生底部 AlertManager 遮挡回复按钮，已保留原生提示与关闭控件，将其置于内容上方；实际 503 重试保存成功。访客页面先显示登录引导，不发送多余的受限请求；手机菜单层级高于提示层，避免权限提示遮挡导航。课程保存后自动定位该课程，避免新课程落在后续分页而难以找到。中文标题输入与正文「标题格式」工具提示消除辅助标签歧义。

本节的远程状态以更新后 PR #2 的对应提交 checks 为准；上面的旧 CI 链接只证明旧提交。未合并代码或部署生产。生产仍等待稳定 Flarum 2.x，并配置正式 HTTPS、SMTP 与运维措施。

## 原生界面与 PR #3 集成验收（2026-10-11）

整合 PR #3 提交 `3037105` 的资料分类目录，保留其提交历史，处理六处合并冲突。目录改用原生页面、侧栏、按钮、弹窗和中文提示，补齐子分类筛选、分页、管理员修改记录与反馈处理。Composer 共 168 包，新增第二个本地扩展；原有 166 个官方包版本未改变。

| 本机实际执行 | 结果与边界 |
|---|---|
| 前端构建、静态与 PHP 语法、14 项管理脚本测试 | 通过；源码与构建产物同步 |
| 阶段 1–11 完整 API 验收 | 通过，含权限、原生列表分页、搜索、个人数据、举报与独立复核 |
| 资料目录 HTTP 验收 | 通过：子分类、管理员修改历史、反馈处理、权限与 UTC 更新时间 |
| 14 个浏览器场景 | 分次回归覆盖全部场景：首次全量 12 项通过；修复分页刷新和目录测试定位后，定向 6 项中 5 项通过，目录单项再次通过。当前提交的全量统一运行交给远程 CI，不能将这些分次结果称为一次全量通过 |
| 首页与主题视觉检查 | 校园广场背景透明并跟随外层；首页返回隐藏。320/390/720px 无横向溢出，DPR 1.25 桌面与手机截图已检查；自动场景覆盖 DPR 2、浅色/深色/高对比度与系统强制颜色，代表性正文对比度至少 4.5:1 |
| 真实重启、SQL/文件/源码备份与隔离恢复 | 通过；新数据库业务统计一致，源码不匹配与重复恢复拒绝，原开发实例保持健康 |
| 开发演示注入 | 十板块共 50 篇模拟内容，使用普通演示账号与真实原生互动；重复执行创建 0 篇，已识别的旧测试帖先备份后隐藏，不清空账号或审计记录 |
| 依赖校验与审计 | Composer validate/platform/audit 与 npm audit 通过，无忽略公告 |
| UTC 时间修正后的原生发布流程 | Chrome 单项再次通过（26.3 秒），真实发布、回复、编辑、反馈与修改记录；新帖时间与当前时间差小于 60 秒 |

列表改为页码导航；切换板块显示对应骨架并隔离旧请求，避免旧内容覆盖新页面。列表元数据改为按权限批量读取。统一页面背景、表面色、字体和辅助文字；首页校园广场保留外层背景，白色只用于独立内容表面。PHP 应用统一使用 UTC 解析数据库时间，浏览器转换本地显示，避免环境 TZ 导致原生帖子时间偏移。

远程 CI 与合入状态请查看 [PR #2](https://github.com/Antares21121/UCASSer/pull/2) 最新提交；提交时尚未确认当前提交的远程结果。后续人工可访问性、真机缩放、性能测量与上线条件见 [下一轮计划](next-iteration.md)。

### Actions 失败修复与 PC 信息流调整（2026-10-11）

提交 `d4fd479` 的 [Actions](https://github.com/Antares21121/UCASSer/actions/runs/38113856885) 已确认失败，失败步骤为阶段接口验收，阶段 4 连续发布触发 Flarum 核心十秒防刷并返回 429；前面的构建、安装、隔离恢复、资料目录 API 和阶段 1–3 已通过。旧提交 `9c00a8f` 的成功不能证明 `d4fd479`。核心防刷在统一 UTC 后正确生效，验收不再依赖请求耗时自然跨过限制。

本轮修正验收客户端：仅遇到 `too_many_requests` 且不是预期 429 时等待 10.1 秒，最多重试一次；不重试校园账号或登录 `rate_limit`。新增三项客户端专项测试，连同原管理脚本共 17 项通过。本机阶段 4–7 与阶段 8–11 分次实际通过；检索测试自行创建普通讨论，避免安全隐藏旧测试示例后依赖失效。

后续提交 `b66e322` 的 Linux Docker 验收已通过阶段 1–11，浏览器为 13 项通过、1 项分页等待时序失败：URL 先改变，测试读取到了旧列表或重载瞬间的空列表。当前测试等待新页无重叠数据、刷新/返回后等待完整目标列表，不以旧 DOM 可见代替新页完成。本机完整 14 项同样 13 项通过，修复后针对导航与颜色的 3 项再次全部通过；手机抽屉调整后继续回归首页/导航共 5 项。

PC 信息流把原生标签移入标题弹性布局，取消浮动定位和固定标题留白；手机自然换行，辅助信息、字段和摘要间距统一，标签采用柔和蓝色。讨论页的浅蓝背景与标签扩展遗留白色标题不匹配，现同时重设标题前景、背景和标签变量，覆盖浅色、深色与高对比度主题。新增标签对齐及标签/讨论标题 4.5:1 对比度回归断言。本轮完整 14 项浏览器与最新远程 CI 的结果以本轮后续实际运行与 PR checks 为准。

对首页、十板块、资料目录和讨论页的四种主题执行 52 组只读检查，采样顶栏、标题、标签、辅助信息、分区筛选、目录描述和更新时间；修复顶栏与深色分类信息后，没有抽样文字低于 4.5:1。这是代表性颜色检查，不能替代完整可访问性审计。手机原生抽屉统一 14px、500 字重、46px 控件和 16px 图标，发布按钮保持 600 字重；登录和注册补上同套图标，原生导航、设置和登录行为保留。560px 浅深色截图已检查，320px 无横向溢出或脚本错误。

### 中文统一与本地 PHP 编译缓存（2026-10-11）

提交 `a379f0c` 的 [Linux Docker Actions](https://github.com/Antares21121/UCASSer/actions/runs/38128309472) 已完整成功：新安装、重启与隔离恢复、资料目录 HTTP、阶段 1–11、14 个浏览器场景及依赖审计均通过。以下新增修改需要以之后的 PR checks 为准。

前台原生语言切换入口移除，论坛和 API 统一简体中文；旧英文 Cookie 和账号偏好不影响前台，原英文依赖保留用于回退，后台保持原生设置。浏览器首页场景注入英文 Cookie，检查文档语言及桌面/手机菜单无语言入口。首页、检索、分页、延迟请求及主题适配共 5 个 Chrome 场景全部通过（36.6 秒）；构建、静态检查、17 项 Python 测试及 PHP 语法均通过。

Windows 便携 PHP 启用官方 OPcache，并使用 `validate_timestamps=1`、`revalidate_freq=0` 保留开发热更新。独立回环探针确认脚本已进入编译缓存，修改后下一请求返回新内容；探针只暴露专用临时目录，结束后关闭独立进程。主开发服务器重启和健康检查通过。

只读预热后各取 5 次请求：资料目录接口中位数由 207ms 降至 101ms；20 条讨论列表由 1344ms 变为 1792ms，没有观察到列表加速。这是本机单串行样本，不能归因全部耗时或代表生产性能；列表的数据库查询和冷缓存/弱网测量仍按计划推进。

### 检索与课程目录统一（2026-10-11）

语言统一提交 `36061d2` 的 [完整 CI](https://github.com/Antares21121/UCASSer/actions/runs/38129242225) 已成功。后续界面修改复用分区 Hero，收紧手机搜索与常用筛选，折叠高级条件，并移除前后台重复社区名称小标。筛选目录复用请求，浏览器已确认详细检索首访仅请求一次目录。

本机分区、首页、检索、课程维护/评价、分页、旧请求隔离及主题共 8 个 Chrome 场景通过（1.8 分钟）。检索回归包含精确标题查询、清空条件、未来日期空结果及恢复；课程回归包含手机首屏课程可见、真实搜索、添加课程后定位与评价发布。课程类型筛选与评价发布类型独立，侧栏使用正确发布文案，课程分页保留筛选。

三个页面（校园信息检索、详细检索、课程目录）、五种宽度（320/475/507/768/1440px）、四种主题共 60 组只读适配检查通过，代表性标题、说明、筛选标签、输入/选择控件和评分文字对比度至少 4.5:1，无横向溢出或脚本错误。手机与桌面截图已检查；高级条件展开不溢出。检索控件取消单独的背景颜色过渡，让主题切换时前景与背景同步更新。

### 分区详情与二手／出行区分（2026-10-11）

十板块详情分别组织日程、申请、学术时间线、互助、交易、资料、课程评分、问答和治理内容。闲聊直接呈现原生正文与回复，登录后仍可收藏、反馈和查看历史。二手采用物品与交接卡片，出行采用行程时间、路线与同行卡片；折叠区按明确字段集合生成，不把旧活动的主办单位或报名链接放入交易、互助和公告。原数据和修改记录保留。

本机详情、十板块列表、机会发布默认值、课程与问答业务、信息发布／编辑／反馈共 5 个 Chrome 场景通过（2.4 分钟）。进一步修正二手／出行折叠区后，新增详情场景再次通过（33.9 秒），包含十板块与两种交易分类的真实持久化帖子、展开后字段排除、原生正文和已登录收藏。课程类型筛选后的评价编辑器专项复跑也已通过（31.2 秒）。这些是分次定向结果，不代表一次全量 15 场景执行。

十一种详情组合、320/507/1440px、四种主题共 132 组只读适配与代表性文字颜色检查通过，对比度至少 4.5:1，没有横向溢出或脚本错误。浅深色手机截图已人工查看。前端构建、静态检查、17 项 Python 测试和演示脚本 PHP 语法通过；最新提交的完整 Linux Docker、15 场景浏览器和依赖审计以 [PR #2 checks](https://github.com/Antares21121/UCASSer/pull/2/checks) 为准。

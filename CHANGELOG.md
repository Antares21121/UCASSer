# Changelog

## 2026-10-24 深度审查修复（P1×1、P2×8、P3 活条目）

依据 2026-10-24 深度审查报告（三轮审查 + 隔离环境验收取证）修复基线 820ae29 上仍然存活的缺陷：

- 启动器：子进程 stdin 隔离与 EOFError 友好报错；备份不再被 git 工作区缺失阻断（git_commit 记 `unavailable`）；失败备份不再残留 `.partial`；开发档校验 APP_URL 与 HTTP_PORT/HTTP_BIND 一致性；新增 `migrate` 子命令统一两后端的 `php flarum migrate`；与论坛无关的子进程不再继承数据库/邮件密码。
- 资料目录扩展：服务端中文错误现在能到达前端（改读 `error.response.error`）；搜索词超长/全空白返回 422 而非静默忽略；分类 (parent_id, name) 唯一索引兜底并发重名；修订行锁入事务、前后快照键集一致；校外根判定改用显式 `is_campus` 标记（新迁移）；重命名根分类 400、重复处理反馈 409；Unicode 感知 trim 拒绝全角/不可见空白名称；列表请求令牌丢弃过期响应；prompt 空输入给出反馈；时间显示截断到分钟；种子迁移可安全重跑。
- 脚本与 CI：`enable_resources.py` 凭据参数化（删除硬编码 phase0_admin 与误导报错）；`audit.py` 与启动器同构的 compose 上下文（`--env-file`/`-f`）；CI 四个 action 锁定 commit SHA；CI 启用扩展步骤显式传参。
- 基础设施：`.dockerignore` 补齐证书/私钥/SQL/日志/auth.json（check.py 断言）；nginx 不再重复下发 Referrer-Policy（应用层 same-origin 为唯一来源）；生产镜像追加不可变 opcache 与 `session.cookie_secure` 覆盖；生产 80 端口预留 ACME HTTP-01 验证位；IIS 样例修正模式并注明未启用；`.env.example` 移除无消费方的 `MAIL_*`。
- 文档：删除已合并分支的切换指引；启用脚本与 migrate 命令的文档同步实际参数；安全文档更新 Referrer-Policy/IIS/SMTP 边界；资料文档补分页上限与搜索词约束。

## 2026-10-11 列表体验与资料目录整合

- 接入 PR #3 的分层资料目录、来源链接与维护反馈，改为 Flarum 原生页面和弹窗，并提供修订对比。
- 原生信息流改为页码列表，修复刷新第二页预载第一页的问题；按板块显示骨架并隔离过时请求。
- 批量读取公开列表字段，减少逐帖重复查询；首页隐藏返回按钮，侧栏按用途分组。
- 统一背景层次、浅深色文字、系统字体、高 DPI 图标和高对比度模式，校园广场背景与页面衔接。
- 补齐前台中文，提供 50 条完整演示帖子与可恢复的测试内容整理工具，开发命令与验收同步文档。
- PC 信息流采用标题与标签弹性布局，移除原生浮动标签与固定标题留白，改善标签下沉、辅助信息间距和明暗主题对比度。
- 验收客户端对核心十秒防刷仅等待重试一次，保留账号和登录限流；普通讨论检索使用独立测试数据，兼容演示实例的安全整理。

## 2026-10-10 原生界面深化

- 首页、顶栏、信息流、讨论、个人空间与发布复用 Flarum 2.x 原生组件。
- 统一蓝色与简体中文，十个校园板块定制阅读结构、筛选与发布默认值。
- 资料访问、问答采纳定位、课程分项评分与来源分组；保留原生评论与互动。
- 修复硬刷新摘要、原生筛选分页、身份/举报队列权限与草稿错误恢复；补充浏览器和接口验收。
- 同步开发环境与架构文档，提交源码、语言、LESS 与构建产物。

## 2026-10-09 · 校园论坛 Phase 0

- 沿用仓库原有 Flarum 2.x 核心、官方扩展清单与 beta/prefer-stable 策略，加入 PHP 8.3+ 要求和可同步的 composer.lock；不降级框架。
- 增加固定版本 Docker 开发环境、独立 HTTPS 生产部署骨架和 SHA256 校验的 Windows 便携运行时。
- RC 仅用于开发；Python 启动、PHP 配置桥接和生产镜像构建拒绝非正式 2.x 核心，生产等待稳定版。
- 增加环境桥接（MariaDB driver、sync 队列）、安全管理员初始化、启动/停止/状态/日志/版本及应用健康命令。
- 增加停写备份、外层和逐文件校验、明确确认的新环境恢复，拒绝已配置/非空库覆盖。
- 修复 Windows MariaDB 停止等待和 Linux 安装配置的属主/读取权限，临时安装凭据在成功或失败后删除。
- 增加真实脚本行为测试、敏感文件排除检查、严格依赖审计和 GitHub Actions Docker 集成检查。
- 提供完整中文开发环境同步、架构、部署、备份、安全、权限、路线图与验证报告；保留原 README。
- 本次实际验证结果与尚未验证项目统一记录于 docs/verification.md；没有生产部署或业务模块开发。

## [2.0.0-beta.3](https://github.com/flarum/flarum/compare/v2.0.0-beta.2...v2.0.0-beta.3)

No changes.

## [2.0.0-beta.2](https://github.com/flarum/flarum/compare/v2.0.0-beta.1...v2.0.0-beta.2)

No changes.

## [2.0.0-beta.1](https://github.com/flarum/flarum/compare/v1.8.0...v2.0.0-beta.1)

### Added
* Messages extension
* GDPR extension

## [1.8.0](https://github.com/flarum/flarum/compare/v1.7.0...v1.8.0)

No changes.

## [1.7.0](https://github.com/flarum/flarum/compare/v1.6.0...v1.7.0)

No changes.

## [1.6.0](https://github.com/flarum/flarum/compare/v1.5.0...v1.6.0)

No changes.

## [1.5.0](https://github.com/flarum/flarum/compare/v1.4.0...v1.5.0)

### Changed

- Update copyright [#85]
- Link logo to official website [#84]

## [1.4.0](https://github.com/flarum/flarum/compare/v1.3.0...v1.4.0)

No changes.

## [1.3.0](https://github.com/flarum/flarum/compare/v1.2.0...v1.3.0)

No changes.

## [1.2.0](https://github.com/flarum/flarum/compare/v1.1.0...v1.2.0)

No changes.

## [1.1.0](https://github.com/flarum/flarum/compare/v1.0.0...v1.1.0)

No changes.

## [1.0.0](https://github.com/flarum/flarum/compare/v0.1.0-beta.16...v1.0.0)

### Changed
- Updated constraints of core and bundled extensions for v1.0.0 stable release (https://github.com/flarum/flarum/pull/74)

## [0.1.0-beta.16](https://github.com/flarum/flarum/compare/v0.1.0-beta.15...v0.1.0-beta.16)

### Changed
- Remove list of developers and refer to https://flarum.org/team (https://github.com/flarum/flarum/pull/71)

### Fixed
- Missing image on README.md (https://github.com/flarum/flarum/pull/72)

## [0.1.0-beta.15](https://github.com/flarum/flarum/compare/v0.1.0-beta.14...v0.1.0-beta.15)

### Added
- Nicknames added to our bundled list (https://github.com/flarum/flarum/pull/70)

## [0.1.0-beta.14](https://github.com/flarum/flarum/compare/v0.1.0-beta.13...v0.1.0-beta.14)

### Added
- Nginx rules to prevent access to sensitive files (#65)
- IIS configuration added (#66)

### Changed
- Minimum PHP requirement is now 7.2+

### Fixed
- Logo path in readme didn't resolve correctly (#68) 

### Removed
- Social auth drivers removed (#67)

## [0.1.0-beta.13](https://github.com/flarum/flarum/compare/v0.1.0-beta.12...v0.1.0-beta.13)

### Changed
- Prevent access to authorisation tokens saved by composer next to the composer.json file ([adada64](https://github.com/flarum/flarum/commit/adada6456f210ea5c94a805a39d88fa613a9e4a2)).

## [0.1.0-beta.12](https://github.com/flarum/flarum/compare/v0.1.0-beta.8.1...v0.1.0-beta.12)

### Changed
- Consolidate site setup into shared file (#63).

## [0.1.0-beta.8.1](https://github.com/flarum/flarum/compare/v0.1.0-beta.8...v0.1.0-beta.8.1)

### Fixed
- Prevent caching of JSON:API responses ([e2544a2](https://github.com/flarum/flarum/commit/e2544a2a223b8ab2fb9efe00036b755b6e2cd7e7))

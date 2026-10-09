# ADR 001：沿用仓库 2.x，生产等待正式版

- 日期：2026-10-09；状态：采用。
- 仓库原始基线：`2.x` 分支、提交 `3d0a0c2`，核心 `^2.0.0-rc.8`、官方扩展 `*`、minimum-stability beta、prefer-stable true。
- 决策：按维护者确认，本机、团队环境和 PR 全部沿用此清单；不将仓库降级到 1.x，不移除原有 2.x 扩展。Composer 锁文件固定实际解析版本，正常同步执行 install。
- 依据：[2.x 安装要求](https://docs.flarum.org/install/)、[RC8 发布](https://github.com/flarum/framework/releases/tag/v2.0.0-rc.8)、[配置与升级](https://docs.flarum.org/update/)。MariaDB 必须使用 mariadb driver，PHP 8.3+，队列采用官方 sync，无外部队列服务。
- RC 只用于开发，阶段 1–12 沿用 Phase 0 的同一基线。Python 生产校验、PHP 生产桥接、生产镜像构建读取真实 composer.lock 并拒绝 RC/beta/dev 或非 2.x 正式核心。生产部署等待正式版；不得手工改锁文件版本来绕过检查。
- 正式 2.x 发布后：核实兼容性，调整 minimum-stability 为 stable，解析正式核心和扩展版本，完成暂存环境安装/恢复/HTTPS 验证后再发布。
- 仓库原本没有 PHP/Composer/数据库容器版本约束，因此选择符合官方要求的 PHP 8.3.35、Composer 2.10.3、MariaDB 11.4.13 LTS、Nginx 1.30.1。保持明确标签和 Windows 下载 SHA256，升级以仓库清单、官方要求和验证结果为依据。
- 移除前一轮临时 1.x 的所有依赖审计例外；不全局关闭 Composer 安全阻断，审计对任何漏洞或停止维护依赖返回失败。
- 不删除前一轮本机测试数据：旧数据库、配置和文件留在 Git 忽略目录；当前 2.x 使用新的数据库和重新初始化的应用文件，分别验收。
- Docker Compose 为团队首选；本机没有 Docker/WSL，使用工作区便携 PHP/MariaDB，Web 只监听回环地址。该替代方案不用于生产。
- 保留 Flarum 核心和上游骨架入口，不创建业务扩展或复杂基础设施。

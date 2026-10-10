# Changelog

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

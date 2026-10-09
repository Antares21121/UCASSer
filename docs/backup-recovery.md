# 备份、恢复与演练

## 覆盖范围和命名

`backup` 先验证已安装数据库，再停止 Web/应用写入，执行 `mariadb-dump --single-transaction --quick --hex-blob`，并包含：

- 全部论坛数据库表（账号、设置、帖子、权限等）。
- 全部 `public/assets/` 和 `storage/`；当前用户上传为头像等 assets 文件。
- `composer.json`、`composer.lock`、环境桥接、`extend.php` 和运行时版本锁。
- `manifest.json` 中的逐文件 SHA256、原数据库名、版本与非敏感运行参数。

外层 ZIP 对应 `.sha256` 文件，名称为 `forum-数据库名-UTC时间-随机后缀.zip`。数据库密码、邮件密码与 `.env` 不归档。备份会含用户数据和私有应用日志，必须按敏感数据保存；当前没有加密和异地上传实现。生产应单独配置加密、异地保存、保留期与访问审计。

```console
python scripts/forum.py backup
python scripts/forum.py backups
```

Windows 加 `--backend native`。备份期间论坛短暂不可写，数据库保持运行，完成或失败后尝试恢复 Web 服务。若恢复服务本身失败，命令非零并保留文件供检查。失败的备份不发布为 `.zip`，不得把 `.partial` 当成可用备份。当前脚本不自动删除旧备份。

运行前确认没有其他后台写入、计划任务或运维 SQL 客户端。未来加入队列、附件扩展或外部对象存储时，需要增加停写与备份适配。本阶段的默认内置扩展未增加这类存储。

## 从空环境恢复（同一后端）

必须使用新工作区、新数据库、新端口/Compose 项目名。恢复脚本不默认覆盖原数据库：要求 `--target-db` 等于新环境的 `DB_NAME`、目标数据库为空、目标无 `config.php`，并且数据库名不能与备份原名相同。数据库名确认是覆盖风险确认，自动化可显式使用 `--confirm 新数据库名`，不能省略目标。

1. 在单独目录克隆所需提交，安装 Python 和 Docker，或下载便携运行时。不要在正在服务用户的工作区演练。
2. 从备份 ZIP 提取 `composer.json` 和 `composer.lock` 到新工作区；核对来源与 SHA256。自定义扩展源码需使用备份对应的 Git 提交；本阶段只有 `extend.php`。脚本要求目标锁文件与归档完全一致。
3. 将 `.env.example` 复制为新目录的 `.env`，设置例如 `DB_NAME=campus_restore_20261009`、`COMPOSE_PROJECT_NAME=campus-restore-20261009`、`HTTP_PORT=8081`、`APP_URL=http://127.0.0.1:8081`。密码留空交给 setup 生成新值。Windows 再设置 `NATIVE_DB_PORT=3308`，以免连接原数据库。
4. 运行 `setup`、`start`；它们只在新数据卷/目录初始化空数据库，不运行论坛安装。**不要运行 install**，否则恢复会拒绝已配置目标。
5. 复制可信备份 ZIP 及对应 `.sha256` 到新机器私有目录，保留原文件名。执行恢复：

```console
python scripts/forum.py restore --archive /absolute/private/forum-backup.zip --target-db campus_restore_20261009
```

Windows 示例：

```powershell
python scripts/forum.py --backend native restore --archive "E:\private-backups\forum-backup.zip" --target-db campus_restore_20261009
```

6. 按提示输入准确目标数据库名。脚本验证外层 SHA256、逐文件校验、路径安全、锁文件和空库，再停止应用、导入 SQL、还原文件，创建基于新环境的 config.php，清缓存、启动并执行健康检查。恢复保留原管理员账号和密码哈希，不创建替代管理员。
7. 登录检查帖子、用户组、头像和邮件设置。新环境使用新访问地址；SMTP 配置仍在备份数据库中，演练应先隔离外发邮件。核对实际业务记录数量，并将结果写入运维记录。
8. 验证后停止演练服务，保留其数据库和日志用于审查。原环境仍运行，脚本不会替换其数据。

## 失败与迁移

- 校验失败、路径越界、目标已安装/非空或锁文件不一致：导入前拒绝。
- 导入或文件复制中途失败：不自动 DROP/清空数据库，保留部分状态并让操作员检查。再次演练选择新目标；不要删除原实例的数据来绕过检查。
- 工具路径、端口、目录权限不足：先执行 `doctor`、`status` 和查看脱敏错误日志。
- Windows 与 Docker 的备份文件布局不同，恢复脚本会明确拒绝跨后端；数据库 SQL 可迁移，文件需由运维解包并将 tar/目录转换到目标 assets/storage 卷，再验证。该跨后端自动转换尚未实现，不能声称已测试。
- 用于自动化的 `--confirm` 是调用者的显式确认。本次演练仅对自动生成的新数据库使用，不代表允许覆盖生产数据。

本次已完成实际数据库导出、文件备份和隔离恢复，证据见 [verification.md](verification.md)；Docker 演练由 CI 提供，尚未在本机运行。

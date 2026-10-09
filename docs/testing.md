# 测试与交接验收

测试写入真实开发数据库；请使用独立开发实例。业务测试只接受 `APP_ENV=development`，示例带「【示例】」，不会执行生产迁移或删除数据库。凭据、验证材料、邮件和原始导出留在内存或被忽略的 `.runtime/`，不上传测试 trace、数据库或备份。

```console
python -m pip install -r tests/requirements.txt
python scripts/check.py
python -m unittest discover -s tests -p "test_*.py" -v
npm ci
npm run build
python scripts/audit.py --backend docker
npm audit
```

已有开发数据库升级前备份，然后启用和迁移：

```console
python scripts/forum.py backup
python scripts/campus.py enable
python scripts/campus.py migrate
python scripts/campus.py seed
```

Windows 上述 Python 命令加 `--backend native`。种子重复执行只补齐明确标注的示例，不清空已有数据。业务测试依赖 smoke 管理员 `phase0_admin` 及私有 `.runtime/admin-initial-password.txt`；普通开发者不应把自己的管理员密码改成测试密码。需完整验收时在全新工作区运行下面的 smoke，自动安全生成测试管理员。

```console
python tests/smoke.py --backend docker
python tests/campus_smoke.py --backend docker --phase 11
npx playwright install chromium
npm run test:browser
```

本机无 Docker 时用 `--backend native`；Windows 已装 Chrome 可设 `$env:FORUM_BROWSER_CHANNEL='chrome'`，避免重复下载 Chromium。浏览器地址默认 8080，可设 `FORUM_BROWSER_URL`。`tests/smoke.py --resume` 仅用于该 smoke 创建的实例；普通已有实例会拒绝写入。

| 验收 | 覆盖 |
|---|---|
| 14 项管理脚本测试 | 配置解析、秘密排除、恶意归档、备份失败、恢复拒绝、RC 生产门禁 |
| 阶段 1–2 | 首页、分区原生/API/搜索权限、角色隔离、撤权、邮件确认、密码重置、GDPR 导出/清除 |
| 阶段 3–6 | 信息、资料、问答、课程评分与真实 SQL 样本、关联、版本冲突、反馈、评价撤回恢复 |
| 阶段 7–8 | 真实回复邮件、采纳和反馈通知、已读、个人隔离、受限通知过滤、23 条检索分页与特殊输入 |
| 阶段 9–11 | 私有举报、补充、分区处理、独立申诉、治理版本、CSRF、封禁/解封、重复发布、登录限流、危险 URL |
| 浏览器 | 真实发布/预览/编辑/评论/收藏；课程维护与评价、资料、回答采纳、搜索、个人中心、治理和后台；移动布局；错误/空状态 |
| 隔离恢复 | 真 SQL/文件备份、新工作区/新数据库恢复、扩展源码校验、业务分区可用、原实例保留、重复恢复拒绝 |

GitHub Actions 运行相同检查，使用 Linux Docker、锁定 npm/Composer、真实安装、恢复与业务接口及 Chromium。远程结果以 PR 的实际 checks 为准。失败诊断脱敏；只上传不含个人数据的验收结果文件。

实际执行日期、结果和 PR CI 链接记录在 [verification.md](verification.md)。正式 SMTP、HTTPS、只读生产卷、真实运营删除申请和稳定 2.x 升级仍需生产前演练，不由开发通过结果代替。

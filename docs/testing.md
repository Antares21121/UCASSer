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
| 3 项接口验收客户端测试 | 仅对核心发帖 `too_many_requests` 等待一次；账号/登录限流不重试，预期 429 保持可观测 |
| 阶段 1–2 | 首页、分区原生/API/搜索权限、角色隔离、撤权、邮件确认、密码重置、GDPR 导出/清除 |
| 阶段 3–6 | 信息、资料、问答、课程评分与真实 SQL 样本、关联、版本冲突、反馈、评价撤回恢复 |
| 阶段 7–8 | 真实回复邮件、采纳和反馈通知、已读、个人隔离、受限通知过滤、23 条检索分页与特殊输入 |
| 阶段 9–11 | 私有举报、补充、分区处理、独立申诉、治理版本、CSRF、封禁/解封、重复发布、登录限流、危险 URL |
| 14 项原生浏览器场景 | 十板块与中文/深色主题、硬刷新摘要、板块筛选及发布默认值、503 后草稿重试、北京时间存储；真实发布/预览/编辑/评论/收藏；课程维护与评价、资料、回答采纳、搜索、个人中心、治理和后台；首页返回与背景、分页刷新、连续切换请求竞态；目录修订与反馈、DPR 2、文字对比度及强制颜色模式 |
| 隔离恢复 | 真 SQL/文件备份、新工作区/新数据库恢复、扩展源码校验、业务分区可用、原实例保留、重复恢复拒绝 |

GitHub Actions 运行相同检查，使用 Linux Docker、锁定 npm/Composer、真实安装、恢复与业务接口及 Chromium。远程结果以 PR 的实际 checks 为准。失败诊断脱敏；只上传不含个人数据的验收结果文件。

PR 更新触发一次完整验收，`2.x` 推送另行验收目标分支；普通功能分支不同时触发重复的 push 与 PR 任务。同一 PR 有新提交时自动取消旧验收，手动运行仍可使用 workflow_dispatch。

实际执行日期、结果和 PR CI 链接记录在 [verification.md](verification.md)。正式 SMTP、HTTPS、只读生产卷、真实运营删除申请和稳定 2.x 升级仍需生产前演练，不由开发通过结果代替。

浏览器与阶段接口测试按顺序运行；接口测试临时调整权限，不能与浏览器同时执行。`campusRecord` 的公开字段、原生检索 20+3 条分页、非法筛选拒绝、身份/举报队列和操作权限均有真实 API 验收。

接口验收遇到 Flarum 核心十秒发帖防刷时等待 10.1 秒后仅重试一次，不关闭或放宽真实防刷规则。此处理仅识别错误码 `too_many_requests`，不会绕过校园 `rate_limit` 或登录限流；Linux 运行较快，不能依赖 Windows 的单次请求耗时自然跨过窗口。

先启用资料目录，再运行 `python tests/resource_smoke.py --url http://127.0.0.1:8080 --admin-user phase0_admin --password-file .runtime/admin-initial-password.txt`，最后运行完整浏览器验收。目录测试写入带验收标记的分类和资料，只应在独立开发实例执行。完成后可用 `scripts/demo.py` 建立更便于视觉检查的演示帖子。

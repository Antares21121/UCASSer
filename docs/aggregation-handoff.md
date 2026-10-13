# 信息汇集交接与自动抓取

## 当前交付

- 信息流：`/campus/aggregation`；登录成员投稿：`/campus/aggregation/submit`；管理员审核：`/campus/aggregation/review`。
- 已接入一个公开网页来源：中国社会科学院大学教务处通知公告。抓取结果只进入待审队列，管理员确认后才公开；相同网页重复运行不会反复入队，已发布网页的变化重新进入审核。
- 未接入公众号、微信群或任意网站的通用抓取。新增网站需要先核对其公开访问方式、页面结构，再登记来源并编写对应适配器。完整边界见 [设计说明](aggregation-design.md)。

## 本地交接步骤（Docker）

在项目根目录运行，使用自己的 `.env` 和数据库，不复制别人的账号或数据：

```powershell
python scripts/forum.py start
python scripts/forum.py healthcheck
python scripts/forum.py backup
python scripts/campus.py migrate
python scripts/aggregation.py --limit 5
```

已有数据升级前先备份；迁移只需在更新代码后执行。日常抓取只需最后一条命令。`--limit` 是每个来源读取的最多文章数，允许 1–10；`--source 1` 可只抓来源编号 1。看到“新增 N 条待审候选”后，用管理员账号打开审核页；若抓取失败，检查该页的“已登记网页来源”错误信息及 `.runtime/last-error.log`。抓取失败不会自动发布错误内容。

## 每天自动抓取一次（Windows 开发机）

先确认手动抓取成功，并保持 Docker Desktop 可用。在 **PowerShell** 中运行以下命令注册当前用户的计划任务；将路径换成实际克隆目录：

```powershell
$repo = "$env:USERPROFILE\Desktop\UCASSer"
$python = (Get-Command python -ErrorAction Stop).Source
$action = New-ScheduledTaskAction -Execute $python -Argument 'scripts/aggregation.py --limit 5' -WorkingDirectory $repo
$trigger = New-ScheduledTaskTrigger -Daily -At '09:00'
Register-ScheduledTask -TaskName 'UCASSer-Aggregation-Poll' -Action $action -Trigger $trigger -Description '每日抓取已登记的公开网页，送管理员审核'
```

这只负责按时运行抓取命令，不会自动审核或发布。电脑关机、未登录或 Docker Desktop 未运行时，任务可能无法完成；到“任务计划程序”查看上次运行结果，再到审核页检查待审候选和来源错误。需要暂停时，在任务计划程序禁用 `UCASSer-Aggregation-Poll`；需要手动触发时运行 `Start-ScheduledTask -TaskName 'UCASSer-Aggregation-Poll'`。不要在多个设备上同时安排同一数据库的抓取。

部署到服务器后，改用服务器的定时器调用同一个 `python scripts/aggregation.py --limit 5` 命令，不依赖个人电脑开机。首次上线先确认来源抓取许可、频率和运营审核人。

## 微信文章的人工验证窗口（试验）

在 Windows 项目根目录运行 `powershell -File scripts/wechat-login.ps1`；也可用 `-ArticleUrl 'https://mp.weixin.qq.com/s/...'` 指定文章。脚本会打开独立的 Chrome 窗口，让维护者亲自完成微信要求的验证或登录。再次遇到验证时，重新运行同一命令；浏览器状态只留在未纳入 Git 的 `.runtime/wechat-chrome-profile`，不要复制或提交它。若 Chrome 安装在非标准位置且脚本无法找到，可用 `-ChromePath 'D:\Chrome\Application\chrome.exe'` 指定位置。

这个窗口仅用于验证微信文章是否能人工打开。当前定时抓取器仍只处理已登记的教务处公开网页，不会读取 Chrome 的登录状态，也不会自动采集公众号。若后续接入公众号，应在抓取失败时标记“需人工验证”，由维护者打开此窗口处理；不能保证微信验证后能长期自动读取文章列表。

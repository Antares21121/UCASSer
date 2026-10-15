# 信息汇集交接与自动抓取

## 当前交付

- 信息流：`/campus/aggregation`；登录成员投稿：`/campus/aggregation/submit`；管理员审核：`/campus/aggregation/review`；管理员登记来源：`/campus/aggregation/sources`。
- 已接入一个公开网页来源：中国社会科学院大学教务处通知公告。抓取结果只进入待审队列，管理员确认后才公开；相同网页重复运行不会反复入队，已发布网页的变化重新进入审核。
- 试验性导入了 8 篇经文章页核对发布账号为“社科大社会与民族学院”、且由[学院官网](https://shymzxy.ucass.edu.cn/)收录链接的微信文章；它们只进入管理员待审队列，尚未公开。这是官网可见链接的抽样，不等于公众号最近 8 篇，更不能证明已取得最近 20 篇。
- 未接入公众号文章列表的自动抓取，也未接入微信群或任意网站的通用抓取。新增网站需要先核对其公开访问方式、页面结构，再登记来源并编写对应适配器。完整边界见 [设计说明](aggregation-design.md)。

### 统一来源登记页

管理员可从信息汇集的“来源管理”页登记公开网页通知列表，或用某公众号自行发布的一篇微信原文链接登记该账号。填写名称和 HTTPS 链接后，记录会显示“待接入抓取”；登记操作不会访问该网址，也不会自动建立公众号订阅。现有教务处适配器继续按原计划运行，群聊消息仍通过成员“提交线索”入口人工录入。

接入新网站前，维护者需核对页面结构并配置适配器。接入公众号前，维护者需在本机订阅服务中单独添加账号并按下文扫码授权。使用与登记页**完全相同的公众号名称**导入 RSS 时，待接入记录会转为“由订阅服务导入”；名称不同则会出现两条来源记录，需要维护者核对。订阅服务会将文章送入待审队列，不会自动发布。登记页显示来源最近一次检查时间和错误，便于区分已运行与待接入来源。

## 可选：从独立公众号订阅服务导入待审队列

已提供 `scripts/aggregation_feed.py` 连接器，读取**本机运行**的 RSS 服务（例如 [we-mp-rss](https://github.com/rachelos/we-mp-rss)）并导入公众号文章的标题、时间、原文链接。它不访问或保存微信登录凭据，不抓取正文，也不自动审核发布。只接收指向 `https://mp.weixin.qq.com/s/...` 的原文链接；来源初始标为“非官方”，管理员需要打开原文确认账号及内容。相同链接重复导入不重复入队。

1. 独立启动订阅服务并由维护者在该服务界面亲自扫码授权、订阅公众号。UCASSer 不共享数据库或登录状态。该服务的配置和数据应保存在其自己的目录，不提交到 UCASSer。
2. 在订阅服务界面复制目标公众号的 RSS 地址。地址必须是本机 `http://127.0.0.1:端口/...` 或 `http://localhost:端口/...`，且订阅服务应输出**原始文章链接**，不能输出自己的文章页面链接。
3. 在 UCASSer 项目根目录手动运行一次：

```powershell
python scripts/aggregation_feed.py --feed-url 'http://127.0.0.1:8001/rss/实际订阅ID' --name '社科大社会与民族学院' --limit 20
```

实际 RSS 路径以服务界面显示的为准。Docker 后端与 Windows 原生后端都支持；原生后端在命令后加 `--backend native`。导入结果在 `/campus/aggregation/review`，来源默认不参与现有网页抓取任务。授权失效时在订阅服务里重新扫码，UCASSer 不读取 Chrome Cookie。确认手动导入有效后，可用系统计划任务定时运行同一命令；机器、Docker 和订阅服务都必须运行。订阅服务能否补齐最近 20 篇取决于其采集方式，导入命令不会虚构缺失文章。微信读书采集模式的[上游说明](https://github.com/rachelos/we-mp-rss/blob/main/docs/weread-mp.md)指出该模式每次只能取最新一篇。

本机试点已在独立容器 `ucasser-we-mp-rss` 中登记“社科大社会与民族学院”，RSS 地址为 `http://127.0.0.1:8001/rss/MP_WXS_3876517368`。账号密码保存在忽略 Git 的 `.runtime/we-mp-rss.env`；订阅服务数据库使用 Docker 命名卷 `ucasser-we-mp-rss-data`，避免 Windows 目录挂载下 SQLite 打不开的问题。执行导入时使用：

```powershell
python scripts/aggregation_feed.py --feed-url 'http://127.0.0.1:8001/rss/MP_WXS_3876517368' --name '社科大社会与民族学院' --limit 20
```

本次试点通过**微信读书**扫码授权，测试接口确认该账号可读，手动采集到 1 篇文章，运行上述命令后新增 1 条待审候选。订阅服务界面的“公众号平台授权”与“微信读书授权”是不同状态；前者显示未授权不影响这条已验证的微信读书采集路径。该模式目前只能确认取得最新一篇，不能把 `--limit 20` 理解为已取得最近 20 篇。后续采集需要在订阅服务的微信读书采集功能中触发，再运行导入命令；授权失效则由维护者重新扫码。试点容器只绑定本机 `127.0.0.1:8001`；不要将管理端口直接暴露到公网。

### 添加更多公众号并每天采集

1. 打开本机订阅服务 `http://127.0.0.1:8001/`，进入“添加订阅”。点“通过公众号码文章获取”，粘贴**目标公众号自己发布的**一篇 `https://mp.weixin.qq.com/s/...` 原文链接，点“获取”。核对自动带出的公众号名称后点“添加订阅”。每个公众号添加一次即可。请勿仅凭转载文章的标题判断发布账号。
2. 在 UCASSer 项目根目录执行 `python scripts/aggregation_weread.py`。它逐个处理订阅服务里已启用的 `MP_WXS_*` 公众号：先用当前微信读书授权采集，再将本机 RSS 中最多 20 条原文链接导入待审队列。旧链接去重，不自动发布。运行记录在 `.runtime/aggregation-weread.log`。如果提示微信读书未授权，回订阅服务重新扫码。
3. Windows 开发机可用以下 PowerShell 命令将同一脚本设为每天上午 9 点运行（当前用户登录且 Docker Desktop 和订阅服务可用时）：

```powershell
$repo = "$env:USERPROFILE\Desktop\UCASSer"
$python = (Get-Command python -ErrorAction Stop).Source
$action = New-ScheduledTaskAction -Execute $python -Argument 'scripts/aggregation_weread.py' -WorkingDirectory $repo
$trigger = New-ScheduledTaskTrigger -Daily -At '09:00'
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName 'UCASSer-WeRead-Collect' -Action $action -Trigger $trigger -Principal $principal -Description '每日采集公众号并送信息汇集待审队列' -Force
```

在“任务计划程序”可查看或禁用 `UCASSer-WeRead-Collect`。每天执行一次只能取得当天运行时的**最新一篇**及已入 RSS 的历史文章；同一账号两次运行之间连续发布多篇时，当前微信读书接口可能漏掉较早的文章，不能保证完整归档。电脑关机、Docker 未启动或授权失效时也不会成功采集，应检查日志并补做人工核对。

## 本地交接步骤（Docker）

在项目根目录运行，使用自己的 `.env` 和数据库，不复制别人的账号或数据：

```powershell
python scripts/forum.py start
python scripts/forum.py healthcheck
python scripts/forum.py backup
python scripts/campus.py migrate
python scripts/aggregation.py --limit 5
```

已有数据升级前先备份；迁移只需在更新代码后执行。日常抓取只需最后一条命令。`--limit` 是每个来源读取的最多文章数，允许 1–10；`--source 1` 可只抓来源编号 1。看到“新增 N 条待审候选”后，用管理员账号打开审核页；若抓取失败，检查来源管理页的错误信息及 `.runtime/last-error.log`。抓取失败不会自动发布错误内容。

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

本地试验通过 `php flarum campus:aggregation:import-college-trial .runtime/college-wechat-trial.json` 将已核对发布账号、标题、发布时间和学院官网出处的 JSON 证据导入待审队列。该证据文件留在本机 `.runtime`，不随 Git 提交；重复导入不会重复入队。试验来源默认禁用定时抓取。公众号账号主页在普通浏览器中提示“请在微信客户端打开”，故当前无法证明这 8 篇是该账号最新发布的文章，也没有凑足 20 篇。

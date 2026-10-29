# 运营后台

运营后台地址为 `/campus/control`。它使用独立授权的普通论坛账号，不要求该账号成为 Flarum 管理员。登录后可在一个工作区完成公开来源采集、快照导入、信息审核、来源登记与暂停、校园身份审核，以及举报和申诉的日常处理。所有写入操作在服务端核对运营权限；信息审核、来源状态和身份处理保留原有审计记录。

论坛最初安装和底层扩展配置仍属于技术部署，不通过运营后台执行。运营账号不获得 Flarum 的扩展安装或系统设置权限。后台的举报处置提供状态处理、隐藏和恢复；永久删除与用户封禁仍由平台底层权限单独控制。

## 创建独立运营账号

先在网站注册并验证一个专用的普通账号，切勿把已有管理员账号当作日常运营账号。部署者在工作区运行一次授权命令，用户名换成实际账号：

```bash
docker compose exec -T -u www-data app php flarum campus:operator 运营用户名
```

当前阿里云测试站使用 `.env.test`，在 `/opt/ucasser-test` 中执行：

```bash
sudo env FORUM_ENV_FILE=.env.test docker compose --env-file .env.test -f compose.yaml exec -T -u www-data app php flarum campus:operator 运营用户名
```

撤销时在命令末尾加 `--revoke`。授权记录只保留用户编号和授予时间；不另存账号密码。创建和撤销由拥有服务器命令权限的人执行，防止后台账号自行扩张授权。账号退出、密码修改与找回沿用论坛登录系统。部署前先备份并运行 `python scripts/campus.py migrate`；服务器使用 `sudo python3 scripts/campus.py --env-file .env.test migrate`。

## 一键采集与本地快照

“一键采集”只把任务加入队列。后台任务执行器每分钟取一项任务，逐个访问已启用的网页来源；结果显示在总览页。任务失败会显示来源报错，不会自动发布内容。服务器无法连接学校官网时，可在能访问学校官网的本地电脑按[本地官网采集](local-aggregation.md)生成 JSON 快照，再点“选择快照并导入”。浏览器直接把最多 1 MB 的快照送入待审队列，不需要将文件放到服务器目录。新候选仍须在“信息审核”栏目核对来源并决定发布、合并、更新或拒绝。

本地开发机可手动处理一项已排队任务：

```console
python scripts/aggregation_jobs.py
```

服务器在更新代码和迁移后安装工作执行器：

```bash
cd /opt/ucasser-test
sudo install -m 0644 deploy/systemd/ucasser-collection-worker.service /etc/systemd/system/
sudo install -m 0644 deploy/systemd/ucasser-collection-worker.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ucasser-collection-worker.timer
systemctl list-timers ucasser-collection-worker.timer
```

首次排查用 `sudo journalctl -u ucasser-collection-worker.service -n 80 --no-pager`。这个每分钟轮询的执行器只处理后台按钮创建的任务；北京时间 09:00 和 21:00 的自动采集仍由 `ucasser-aggregation.timer` 独立执行。网页和公众号的订阅状态并不因为按钮或来源登记而自动获得授权。

## 来源、审核和举报

来源管理可登记官网通知列表或公众号原文链接。新登记来源保持“待接入”；只有已有适配器的官网栏目能启停和单独采集。公众号仍需独立订阅服务及人工扫码授权，后台不会读取浏览器或微信登录态。学校官网曾从阿里云服务器连接超时；来源状态中的错误信息用于区分网络失败、页面结构变化和未接入。

身份审核显示待处理申请，批准或拒绝时必须记录理由，处理后清除原始申请材料。举报处理显示未结案件、关联讨论、处理理由和状态；申诉必须由原处理人以外的运营人员复核。运营后台无权跳过这些服务端检查。

私信启用 `flarum-messages` 扩展后，编辑区保留文本输入、发送和图片选择。图片支持 PNG、JPEG、WebP，单张不超过 2 MB；图片文件保存在非公开 `storage/` 中，只有上传者及所属私信会话成员可读取，其他人即使知道链接也无法访问。

## 学院、课程与共享资料

控制台新增三个目录栏目（详见 [改版交接](redesign.md)）：

- 学院目录：稳定代码、名称、别名、排序与状态；与资料目录的学院节点保持同步。
- 课程目录：代码、名称、学期、开课学院、类型/性质、简介；唯一身份为学院 + 代码 + 学期；评价通过稳定课程 ID 关联，改名与重新导入不丢评价。
- 共享资料：录入网盘链接元数据（不托管文件），仅 https，外链新窗口打开；修订与失效反馈沿用资料目录的记录。

三个栏目均支持「下载模板 → 上传 CSV/JSON → 预览 → 确认写入」，同一文件重复导入幂等；每批保留逐行前后值，可整体回滚。表单与批量导入使用同一套服务端校验，任何写入都记录操作者、时间与理由。普通账号调用写接口返回 403。

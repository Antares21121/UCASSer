# 官网在服务器无法访问时：从本地采集

当云服务器访问社科大官网超时，而维护者的 Windows 电脑可以访问时，可在本地抓取公开通知，再将快照导入服务器的**待审核队列**。此流程不需要校园门户账号，也不采集非公开页面。

## 本地抓取

在更新过代码的项目目录中运行（需要本机 PHP 和 Windows 自带 `curl.exe`）：

```powershell
python scripts/aggregation_local_snapshot.py --source 学校通知公告 --source 教务处通知公告 --limit 2
```

输出为项目中的 `.runtime/aggregation-web-local.json`。脚本复用网站现有的公告解析器，只保留官方同域名的公告原链接、标题、发布日期、短摘要和正文变化指纹。失败的来源和文章写在快照的 `failures` 中；成功来源仍可导入。重复抓取会替换本机快照，不会触碰线上数据库。

试点核对无误后可运行全部已登记的公开官网来源：

```powershell
python scripts/aggregation_local_snapshot.py --all --limit 5
```

每个来源最多读取 5 篇；较多来源会花几分钟。遇到网站改版、404、403 或 429 时，检查错误，不应绕过访问控制或虚构缺失文章。

## 导入服务器审核队列

先确认服务器代码已包含 `campus:aggregation:import-web-local` 命令，且已完成数据备份。使用阿里云 Workbench 的**文件管理**将本机 `.runtime/aggregation-web-local.json` 上传至服务器 `/home/admin/aggregation-web-local.json`，再在 Workbench 终端运行：

```bash
cd /opt/ucasser-test
sudo install -m 0640 -o root -g www-data /home/admin/aggregation-web-local.json .runtime/aggregation-web-local.json
sudo python3 scripts/aggregation_import_local.py --env-file .env.test
```

导入只接受代码目录中的已登记官网、同域公告原链接以及完整字段；暂停的来源不会导入。重复导入按链接和内容指纹去重，正文变化则成为新的待审核候选。管理员在 `/campus/aggregation/review` 核对后决定发布。不要上传 `.env`、Cookie 或微信登录凭据。

Workbench 一键登录的 `admin` 用户通常可以向 `/home/admin` 上传，但不能直接写入 `/opt`；上述 `sudo install` 只复制这份已生成的快照。[阿里云 Workbench 文件传输说明](https://help.aliyun.com/zh/simple-application-server/user-guide/use-workbench-to-transfer-files-to-a-linux-server)。

本地采集和人工上传是当前网络故障下的验证流程。自动 09:00／21:00 采集仍由服务器定时器负责；它目前无法连通部分社科大官网。若要把本地采集结果自动送到服务器，需先恢复一条可验证的加密传输通道；不要通过明文 HTTP 发送站点凭据或采集器口令。

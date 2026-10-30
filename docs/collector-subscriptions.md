# 恢复已核对的公众号订阅

范围为 `data/reconciliation/public-content.json` 中的 19 个公开发布者编号，排除采集器的虚拟“精选文章”源。这不是全部学院和下属组织公众号已覆盖的证明。

前提是内部连接完成、所有者已亲自扫码授权。工具先测试保存的授权，再使用采集器的 `/weread/mp/test` 对每个编号核对当前发布者名称。任何编号、名称或访问错误都会停止，在全部通过前不写订阅。

```bash
sudo python3 scripts/restore_collector_subscriptions.py --root /opt/ucasser-test --env-file .env.test
sudo python3 scripts/restore_collector_subscriptions.py --root /opt/ucasser-test --env-file .env.test --apply
```

默认预览只核对公开身份，并在 SQLite 显式事务中验证插入后回滚。应用前制作一份仅 root 可读的采集器数据库备份，再在单个事务中补充缺失订阅。已有订阅（含停用状态、说明和时间）原样保留；同一编号或 `faker_id` 对应不同发布者时，全部插入回滚。账号、密码、微信 Cookie、已有文章和论坛数据不由此工具修改。它不调用自动排队的新增公众号接口，不启动文章抓取。

订阅恢复后，可运行已有的 `scripts/aggregation_weread.py --env-file .env.test --limit 5` 实际采集和导入待审队列。公开网站采集单独验收；服务器仍无法访问学校官网时，不能据此声称官网定时采集成功。

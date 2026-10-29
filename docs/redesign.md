# 板块改版与内容迁移

本 PR 在 `2.x`（PR #15 合并后）上完成页面、板块与内容管理改版。规格来源为用户提供的 `UCASSer-handoff` 资料包（SPEC.md / START_HERE.md / DATA_TEMPLATES.md / IMAGE_BRIEF.md）。

## 板块变化

| 旧板块 | 处理 | 内容去向 |
|---|---|---|
| 课程经验 `campus-courses` 讨论板块 | 撤销 | 结构化课程评价迁入内部归档 `campus-archive`；课程目录 `/campus/courses` 与评价继续可用 |
| 综合讨论 `general` | 撤销（隐藏标签） | 按标题关键词与结构化记录分类迁移 |
| 二手交易与出行信息 `campus-market` | 拆分 | 二手归 `campus-secondhand`，出行/同行归 `campus-travel` |
| 讲座、学术与志愿服务 `campus-academic` | 撤销 | 机会与招募类进入 `campus-careers`，其余按分类迁移 |
| 校园资讯与活动 `campus-news` | 撤销 | 讨论逐条迁移；信息汇集 `/campus/aggregation` 与采集来源保留 |
| 闲聊与公共讨论 `campus-chat` | 撤销 | 按主题迁移到生活互助或问答等 |
| 社区公告与治理 `campus-governance` | 撤销 | 治理公告讨论迁入内部归档；公开治理页 `/campus/governance`、举报申诉与审计保留 |

保留板块：共享资料库、校园问答、校园生活与互助、实习就业与竞赛。`/all` 与旧 `/t/campus-*` 入口保留跳转，不静默 404；讨论 ID 不变，`/d/{id}` 旧链接直接生效。

## 迁移执行（幂等、可复跑）

1. 备份数据库后执行 `php flarum migrate`。schema 迁移自动：创建二手/出行/归档板块、关闭并隐藏旧板块、按规则迁移全部旧讨论（无法自动判定的进入 `campus_board_migrations` 人工复核清单，绝不删除或隐藏内容）。
2. 迁移前预览：

```console
php flarum campus:boards:migrate            # dry-run：输出逐条去向与统计
php flarum campus:boards:migrate --apply    # 写入（重跑跳过已完成项）
```

迁移前后用 `campus_board_migrations` 对照计数；`campus_records.section_id`、讨论标签、分类与搜索索引一并更新。

## 回复编辑器

公开讨论与回复编辑器只保留：本地上传图片、@ 提及、正文与发布按钮；移除预览、粗斜体、删除线、引用、警告、代码、链接、列表、表情等快捷按钮。手写 Markdown 正常渲染。图片支持 PNG/JPEG/WebP，单张 ≤ 5 MB，前后端都校验；服务端按文件内容识别 MIME、GD 重新编码去除 EXIF、随机文件名、像素与每日速率限制；草稿孤儿图片 7 天后清理；仅可发帖的已确认账号可上传；私信图片存储与权限保持独立不变。

## 昵称与 @ 提及

每个账号分配永久、唯一、公开的数字后缀（≥4 位，数据库唯一索引 + 并发重试，注销不回收）。展示为 `昵称 #6665`；@ 自动补全展示头像、昵称与 `#编号`，选中后保存原生稳定用户 ID；手写 `@昵称#编号` 由服务端解析为对应账号并正确发通知；改昵称不换号，历史提及继续可用。原 Flarum 用户名、登录与个人主页 URL 不变。

## 运营控制台新增

`/campus/control` 新增「学院目录」「课程目录」「共享资料」三个栏目，均要求 `campus_operators` 独立授权（普通账号写接口返回 403），并写入操作审计（操作者、时间、前后值、理由/批次）：

- 学院：稳定代码、名称（唯一）、别名、排序、状态；与 `ucasser_resource_categories` 的 college 节点同步，不生成重复节点。
- 课程：课程代码、名称、学期、开课学院、类型/性质、简介；唯一身份为学院 + 代码 + 学期；复用 `campus_courses` / `campus_course_offerings`；评价始终通过稳定 course ID 关联。
- 共享资料：录入链接元数据（标题、https 链接、课程归属、资料类型、简介、来源/分享授权、提取码、状态、录入人与时间）；复用 `ucasser_resources` 与修订记录；外链 `noopener noreferrer`。
- 批量导入：下载模板 → 上传 CSV/JSON（≤1 MB、≤500 行）→ dry-run 预览（新增/更新/跳过/冲突/失败逐行原因）→ 人工确认写入 → 批次记录可回滚；同一文件重复导入幂等。表单与导入共用同一套校验服务，AI 不得直写数据库。

## 经验等级基础

每个账号 `total_xp=0`、`level=1`，资料页低调展示 `Lv.1`；等级门槛存于可配置、可版本化的设置（`ucasser-campus.level_thresholds`）。本次不创建任务、不接入任何涨经验事件、不提供手动加经验接口。账号删除保留后缀（不回收）并清除统计；GDPR 导出包含后缀、经验与上传图片记录。

## 视觉与图片

配色：深校园红 `#A71937`、强调红 `#C22A49`、暖纸白 `#F7F4EE`、深墨蓝 `#1C3042`、浅雾蓝 `#DFEAF2`。首页为纸白背景上的深红标题、细红线与单张主视觉图；右上角皮肤与通知按钮移除（通知列表移入个人菜单，数据与发送不受影响）；品牌主题跟随系统明暗模式。图片为项目自绘 SVG 插画（见 `docs/visual-language.md` 的来源与许可记录），板块卡固定 4:3 防布局跳动、懒加载、装饰图空 alt；低网速与关图时布局与文字完整。

## 验收与测试

- `python scripts/check.py`、`python -m unittest discover -s tests -p "test_*.py"`
- `npm run build`（提交 `extensions/campus/js/dist`）
- 新增 `tests/redesign_smoke.py`（API 验收：板块目录、越权 403、后缀与等级、控制台导入预览/幂等/回滚、图片上传限制）
- 新增 `tests/browser/redesign.spec.cjs`（顶栏按钮、板块入口、旧链接跳转、回复工具栏、移动端溢出）
- CI：新增步骤运行以上验收（见 `.github/workflows/ci.yaml`）

未完成/待用户提供：真实照片素材（当前使用自绘插画，若后续有明确授权的摄影素材可替换）；真实课程与学院数据（导入框架已就绪，需用户提供经核实、有权公开的数据文件）。

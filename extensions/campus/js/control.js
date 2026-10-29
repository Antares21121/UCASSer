import app from 'flarum/forum/app';
import PageStructure from 'flarum/forum/components/PageStructure';
import Button from './button';
import { DataPage, api, field, formatTime, link, symbol, errorMessage, loginPrompt, emptyState } from './ui';

const jobNames = { queued: '等待执行', running: '正在采集', succeeded: '已完成', failed: '部分或全部失败' };
const categories = { notice: '通知', activity: '活动', lecture: '讲座', opportunity: '机会', other: '其他' };
const tabs = [
  ['overview', '总览与采集', 'tachometer-alt'],
  ['review', '信息审核', 'clipboard-check'],
  ['sources', '来源管理', 'rss'],
  ['identity', '身份审核', 'user-check'],
  ['cases', '举报处理', 'flag'],
  ['colleges', '学院目录', 'university'],
  ['courses', '课程目录', 'book'],
  ['resources', '共享资料', 'folder-open']
];
const catalogKinds = {
  colleges: {
    title: '学院目录',
    api: 'control/catalog/colleges',
    hint: '学院是受控目录：名称唯一，可改名而不破坏课程关联；停用后不再出现在公开选择中。',
    template: ['external_key,name,aliases,status', 'demo-college-01,示例学院,演示学院,active'],
    form: [
      ['code', '稳定代码', { required: true, minlength: 1, maxlength: 40 }],
      ['name', '学院名称', { required: true, minlength: 2, maxlength: 120 }],
      ['aliases', '别名（逗号分隔）', { maxlength: 300 }],
      ['external_key', '外部稳定编号', { maxlength: 80 }]
    ],
    columns: [
      ['name', '名称'], ['code', '代码'], ['aliases', '别名'], ['status', '状态'], ['position', '排序']
    ]
  },
  courses: {
    title: '课程目录',
    api: 'control/catalog/courses',
    hint: '课程按「学院 + 代码 + 学期」确定唯一身份；同名课程不会自动合并，名称冲突需人工处理。',
    template: ['external_key,college_key,code,name,semester,type,nature,description', 'demo-course-01,demo-college-01,DEMO101,示例课程,2026秋,本科,选修,仅用于测试'],
    form: [
      ['code', '课程代码', { maxlength: 32 }],
      ['name', '课程名称', { required: true, minlength: 2, maxlength: 80 }],
      ['college_id', '开课学院', { type: 'select' }],
      ['semester', '学期', { maxlength: 80 }],
      ['type', '类型', { maxlength: 80 }],
      ['nature', '性质', { maxlength: 80 }],
      ['description', '简介', { textarea: true, maxlength: 2000 }],
      ['external_key', '外部稳定编号', { maxlength: 80 }]
    ],
    columns: [
      ['name', '课程'], ['code', '代码'], ['college_name', '学院'], ['semester', '学期'], ['type', '类型'], ['nature', '性质']
    ]
  },
  resources: {
    title: '共享资料网盘链接',
    api: 'control/catalog/resources',
    hint: '这里录入的是链接元数据，不托管网盘文件；只允许 https 链接，页面输出转义、外链新窗口打开。',
    template: ['external_key,course_key,title,url,resource_type,description,source_url,share_permission,access_code,status', 'demo-resource-01,demo-course-01,示例课程公开资料,https://example.org/course,参考链接,仅用于测试,https://example.org/course,已获分享许可,,pending'],
    form: [
      ['title', '资料标题', { required: true, minlength: 2, maxlength: 180 }],
      ['url', '访问链接（https）', { required: true, type: 'url', maxlength: 2048 }],
      ['category_id', '课程归属', { type: 'select' }],
      ['resource_type', '资料类型', { maxlength: 40 }],
      ['description', '简介', { textarea: true, maxlength: 2000 }],
      ['source_url', '来源链接', { type: 'url', maxlength: 2048 }],
      ['share_permission', '来源 / 分享授权说明', { textarea: true, maxlength: 2000 }],
      ['access_code', '提取码（可选）', { maxlength: 80 }],
      ['status', '状态', { type: 'select' }],
      ['external_key', '外部稳定编号', { maxlength: 80 }]
    ],
    columns: [
      ['title', '标题'], ['url', '链接'], ['category_name', '归属'], ['resource_type', '类型'], ['status', '状态'], ['updated_at', '更新时间']
    ]
  }
};

export default class ControlPage extends DataPage {
  requiresLogin = true;
  bodyClass = 'CampusApp CampusOperations';

  oninit(vnode) {
    this.reviewNotes = {};
    this.reviewTargets = {};
    this.reviewVerified = {};
    this.identityNotes = {};
    this.caseNotes = {};
    this.caseActions = {};
    this.sourceForm = { kind: 'web', name: '', url: '' };
    this.catalogForm = null;
    this.catalogImport = null;
    this.catalogOptions = null;
    super.oninit(vnode);
  }

  async fetch() {
    this.tab = tabs.some(([name]) => name === this.params.tab) ? this.params.tab : 'overview';
    this.page = Math.max(1, Math.min(10000, Number.parseInt(this.params.page, 10) || 1));
    const path = {
      review: `aggregation/queue?page=${this.page}`, sources: 'aggregation/sources',
      identity: `identity/queue?page=${this.page}`, cases: `cases?status=open&page=${this.page}`
    }[this.tab];
    const catalogKind = Object.keys(catalogKinds).includes(this.tab) ? this.tab : null;
    if (catalogKind) {
      const detail = await api(`${catalogKinds[catalogKind].api}?page=${this.page}` + (this.catalogSearch ? `&search=${encodeURIComponent(this.catalogSearch)}` : ''));
      this.detail = detail.data.items || [];
      this.detailMeta = detail.data.meta || { total: 0 };
      if (catalogKind !== 'colleges') this.catalogOptions = (await api('control/catalog/categories')).data;
      this.data = { work: {}, content: {}, community: {}, sources: {} };
      return;
    }
    const [overview, detail] = await Promise.all([api('control'), path ? api(path) : Promise.resolve(null)]);
    this.data = overview.data;
    this.detail = detail?.data || [];
    this.detailMeta = detail?.meta || null;
  }

  pager(size = 20) {
    const total = Number(this.detailMeta?.total || 0);
    if (total <= size) return null;
    const href = page => `/campus/control?tab=${this.tab}&page=${page}`;
    return m('nav.Campus-controlPager', { 'aria-label': `${tabs.find(([name]) => name === this.tab)?.[1]}翻页` }, [
      this.page > 1 ? link(href(this.page - 1), '上一页') : m('span', '上一页'),
      m('span', `第 ${this.page} 页 · 共 ${total} 条`),
      this.page * size < total ? link(href(this.page + 1), '下一页') : m('span', '下一页')
    ]);
  }

  view() {
    const content = !app.session.user ? loginPrompt('运营后台', '请使用已获授权的运营账号登录。') : this.loading ?
      m('section.Campus-panel', { role: 'status' }, '正在加载运营后台…') : this.error ?
      m('section.Campus-panel.Campus-gate', { role: 'alert' }, [
        m('h1', this.errorStatus === 403 ? '此账号尚未获得运营授权' : '后台暂时无法打开'),
        m('p', this.error),
        m(Button, { onclick: () => this.load() }, '重新加载')
      ]) : this.content();
    return m(PageStructure, { className: 'CampusPage CampusOperations' }, m('div.Campus.Campus-operationsWrap', [
      this.notice ? m('div.Campus-notice', { role: this.noticeError ? 'alert' : 'status',
        className: this.noticeError ? 'is-error' : 'is-success' }, this.notice) : null,
      m('fieldset.Campus-interaction', { disabled: Boolean(this.busy), 'aria-busy': Boolean(this.busy) }, content)
    ]));
  }

  decide(candidate, action) {
    const item_id = Number(this.reviewTargets[candidate.id] || candidate.item_id || 0);
    return this.perform(() => api(`aggregation/candidates/${candidate.id}/review`, 'POST', {
      action, reason: this.reviewNotes[candidate.id] || '', verified: Boolean(this.reviewVerified[candidate.id]),
      ...(action === 'merge' || action === 'update' ? { item_id } : {})
    }), { message: action === 'reject' ? '候选信息已拒绝' : '审核结果已保存' });
  }

  reviewIdentity(row, status) {
    return this.perform(() => api(`identity/${row.id}`, 'PATCH', {
      status, reason: this.identityNotes[row.id] || ''
    }), { message: '身份申请已处理' });
  }

  processCase(row, status) {
    return this.perform(() => api(`cases/${row.id}`, 'PATCH', {
      version: row.version, status, action: this.caseActions[row.id] || 'none', reason: this.caseNotes[row.id] || ''
    }), { message: '举报处理结果已记录' });
  }

  addSource(event) {
    event.preventDefault();
    return this.perform(() => api('aggregation/sources', 'POST', this.sourceForm), {
      message: '来源已登记；接入适配器后才能自动采集。',
      after: () => { this.sourceForm = { ...this.sourceForm, name: '', url: '' }; }
    });
  }

  collect(sourceId = null) {
    return this.perform(() => api('control/collect', 'POST', sourceId ? { source_id: sourceId } : {}), {
      message: '采集任务已加入队列。学校官网若拒绝服务器连接，可改用本地快照。'
    });
  }

  toggleSource(source) {
    const enabled = !source.enabled;
    return this.perform(() => api(`control/sources/${source.id}`, 'PATCH', { enabled }), {
      message: enabled ? '来源已启用' : '来源已暂停'
    });
  }

  async importFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > 1024 * 1024 || !file.name.toLowerCase().endsWith('.json')) {
      this.notice = '请选择不超过 1 MB 的官网 JSON 快照。';
      this.noticeError = true;
      return;
    }
    let snapshot;
    try { snapshot = await file.text(); } catch (error) {
      this.notice = errorMessage(error, '读取文件失败，请重试。');
      this.noticeError = true;
      return;
    }
    return this.perform(async () => {
      const response = await api('control/import', 'POST', { snapshot });
      this.importResult = response.data;
    }, { message: '快照已导入待审核队列，未自动公开。' });
  }

  reviewPanel() {
    return m('section.Campus-controlSection', [
      m('div.Campus-controlSectionHead', [m('h2', '信息审核'), m('span', `${this.data.work.aggregation_pending} 条待处理`)]),
      m('p.Campus-hint', '核对来源和时间后发布。未核实的内容会明确标注，缴费与报名链接不会成为操作按钮。'),
      this.detail.length ? m('div.Campus-controlQueue', this.detail.map(candidate => m('article.Campus-controlQueueItem', { key: candidate.id }, [
        m('div.Campus-controlQueueMeta', [m('span.Campus-badge', categories[candidate.category] || '其他'),
          m('span', candidate.origin === 'web' ? '网页采集' : '成员投稿'),
          m('span', candidate.visibility === 'members' ? '仅成员可见' : '公开')]),
        m('h3', candidate.title), m('p', candidate.summary),
        m('p.Campus-controlQueueMeta', [candidate.source_name, candidate.source_url ? m('a', {
          href: candidate.source_url, target: '_blank', rel: 'noopener noreferrer nofollow'
        }, '核对原链接 ↗') : null]),
        candidate.item_id ? m('p.Campus-hint', `已关联公开条目 #${candidate.item_id}，可批准更新或合并来源。`) : null,
        m('label', [m('input[type=checkbox]', { checked: Boolean(this.reviewVerified[candidate.id]),
          onchange: e => this.reviewVerified[candidate.id] = e.target.checked }), ' 已核实原始信息']),
        field('reason', '审核说明（拒绝时至少 5 字）', this.reviewNotes[candidate.id] || '',
          value => this.reviewNotes[candidate.id] = value, { maxlength: 500 }),
        field('item_id', '合并目标条目编号', this.reviewTargets[candidate.id] || candidate.item_id || '',
          value => this.reviewTargets[candidate.id] = value, { type: 'number', min: 1 }),
        m('div.Campus-actions', [
          m(Button, { className: 'Button Button--primary', onclick: () => this.decide(candidate, 'publish') }, '发布'),
          m(Button, { onclick: () => this.decide(candidate, 'merge') }, '合并来源'),
          candidate.item_id ? m(Button, { onclick: () => this.decide(candidate, 'update') }, '批准更新') : null,
          m(Button, { onclick: () => this.decide(candidate, 'reject') }, '拒绝')
        ])
      ]))) : emptyState('本页没有待审核信息', '新采集和成员投稿会在这里等待处理。'),
      this.pager()
    ]);
  }

  sourcesPanel() {
    return m('section.Campus-controlSection', [
      m('div.Campus-controlSectionHead', [m('h2', '来源管理'), m('span', `${this.data.sources.total} 个已登记来源`)]),
      m('form.Campus-controlSourceForm', { onsubmit: e => this.addSource(e) }, [
        m('label.Campus-field', [m('span.Campus-fieldLabel', '来源类型'), m('select.FormControl', {
          value: this.sourceForm.kind, onchange: e => this.sourceForm.kind = e.target.value
        }, [m('option', { value: 'web' }, '公开网页'), m('option', { value: 'wechat' }, '公众号')])]),
        field('name', '来源名称', this.sourceForm.name, value => this.sourceForm.name = value,
          { required: true, minlength: 2, maxlength: 120 }),
        field('url', this.sourceForm.kind === 'web' ? '通知列表网址' : '公众号原文链接', this.sourceForm.url,
          value => this.sourceForm.url = value, { required: true, type: 'url', maxlength: 1024 }),
        m(Button, { type: 'submit', className: 'Button Button--primary' }, '登记来源')
      ]),
      m('p.Campus-hint', '新来源先进入待接入状态；官网需配置适配器，公众号需在订阅服务授权。'),
      this.detail.length ? m('div.Campus-controlQueue', this.detail.map(source => m('article.Campus-controlQueueItem', { key: source.id }, [
        m('h3', source.name), m('p.Campus-controlQueueMeta', [source.adapter, ' · ', source.enabled ? '已启用' : '未启用']),
        source.listing_url ? m('a', { href: source.listing_url, target: '_blank', rel: 'noopener noreferrer nofollow' }, '查看来源 ↗') : null,
        source.last_error ? m('p.Campus-controlSourceError', source.last_error) : null,
        ['ucass-notices', 'ucass-vsb', 'ucass-employment'].includes(source.adapter) ? m('div.Campus-actions', [
          m(Button, { onclick: () => this.toggleSource(source) }, source.enabled ? '暂停' : '启用'),
          m(Button, { disabled: !source.enabled, onclick: () => this.collect(source.id) }, '采集此来源')
        ]) : null
      ]))) : emptyState('尚未登记来源', '可以在上方添加官网通知列表或公众号原文链接。')
    ]);
  }

  identityPanel() {
    return m('section.Campus-controlSection', [
      m('div.Campus-controlSectionHead', [m('h2', '校园身份审核'), m('span', `${this.data.work.identity_pending} 条待处理`)]),
      m('p.Campus-hint', '审核后将清除申请材料；处理理由不要引用申请人的私人信息。'),
      this.detail.length ? m('div.Campus-controlQueue', this.detail.map(row => m('article.Campus-controlQueueItem', { key: row.id }, [
        m('h3', `${row.display_name} · 申请 #${row.id}`),
        m('small', formatTime(row.created_at)), m('p', row.evidence),
        field('reason', '处理理由（至少 5 字）', this.identityNotes[row.id] || '',
          value => this.identityNotes[row.id] = value, { required: true, minlength: 5, maxlength: 500 }),
        m('div.Campus-actions', [
          m(Button, { className: 'Button Button--primary', onclick: () => this.reviewIdentity(row, 'approved') }, '批准'),
          m(Button, { onclick: () => this.reviewIdentity(row, 'rejected') }, '拒绝')
        ])
      ]))) : emptyState('本页没有待审核申请', '成员提交校园身份申请后会显示在这里。'),
      this.pager(50)
    ]);
  }

  casesPanel() {
    return m('section.Campus-controlSection', [
      m('div.Campus-controlSectionHead', [m('h2', '举报与申诉'), m('span', `${this.data.work.cases_pending} 条待处理`)]),
      m('p.Campus-hint', '处理前核对讨论和上下文。申诉需由原处理人之外的运营人员复核。'),
      this.detail.length ? m('div.Campus-controlQueue', this.detail.map(row => m('article.Campus-controlQueueItem', { key: row.id }, [
        m('div.Campus-controlQueueMeta', [m('span.Campus-badge', row.status), m('span', `举报 #${row.id}`)]),
        m('h3', `关联讨论 #${row.discussion_id}`), m('p', row.reason || '没有可显示的举报说明'),
        link(`/d/${row.discussion_id}`, '查看讨论上下文'),
        row.can_manage && (row.status !== 'appealed' || row.can_review) ? [
          m('label.Campus-field', [m('span.Campus-fieldLabel', '内容处置'), m('select.FormControl', {
            value: this.caseActions[row.id] || 'none', onchange: e => this.caseActions[row.id] = e.target.value
          }, [m('option', { value: 'none' }, '只记录处理结果'),
            m('option', { value: 'hide' }, '隐藏相关内容'),
            m('option', { value: 'restore' }, '恢复相关内容')])]),
          field('reason', '处理理由（至少 5 字）', this.caseNotes[row.id] || '',
            value => this.caseNotes[row.id] = value, { required: true, minlength: 5, maxlength: 500 }),
          m('div.Campus-actions', row.status === 'appealed' ? [
            m(Button, { className: 'Button Button--primary', onclick: () => this.processCase(row, 'reviewed') }, '完成独立复核')
          ] : [
            m(Button, { onclick: () => this.processCase(row, 'in_progress') }, '标记处理中'),
            m(Button, { onclick: () => this.processCase(row, 'needs_info') }, '请求补充'),
            m(Button, { className: 'Button Button--primary', onclick: () => this.processCase(row, 'resolved') }, '处理完成'),
            m(Button, { onclick: () => this.processCase(row, 'rejected') }, '不予受理')
          ])
        ] : m('p.Campus-hint', '此申诉需要另一位运营人员复核。')
      ]))) : emptyState('本页没有未结案件', '新的举报和申诉会显示在这里。'),
      this.pager()
    ]);
  }

  catalogImportSummary(result) {
    if (!result) return null;
    return m('div.Campus-catalogImportSummary', { role: 'status' }, [
      m('p', `共 ${result.total} 行：有效 ${result.valid}，新增 ${result.new}，更新 ${result.updated}，跳过 ${result.skipped}，冲突 ${result.conflicts}，失败 ${result.failed}。`),
      result.batch_id ? m('p', `已应用为批次 #${result.batch_id}；如需撤销，可回滚该批次。`) : m('p.Campus-hint', '预览模式：尚未写入。核对逐行结果后再确认导入。'),
      m('table.Campus-catalogImportTable', [
        m('thead', m('tr', [m('th', '行'), m('th', '结果'), m('th', '外部编号'), m('th', '说明')])),
        m('tbody', (result.results || []).map(row => m('tr', [m('td', row.line), m('td', row.action), m('td', row.external_key || ''), m('td', row.error || '')])))
      ])
    ]);
  }

  async importCatalogFile(event) {
    const kind = this.tab;
    const config = catalogKinds[kind];
    if (!config) return;
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > 1024 * 1024) {
      this.notice = '导入文件不能超过 1 MB。';
      this.noticeError = true;
      return;
    }
    let rows;
    try {
      const text = await file.text();
      if (file.name.toLowerCase().endsWith('.json')) {
        rows = JSON.parse(text);
        if (!Array.isArray(rows)) throw new Error('数组');
      } else {
        const lines = text.split(/\r?\n/).filter(line => line.trim());
        const headers = lines.shift().replace(/^\uFEFF/, '').split(',');
        rows = lines.map(line => {
          const values = line.split(',');
          const row = {};
          headers.forEach((header, index) => row[header.trim()] = (values[index] ?? '').trim());
          return row;
        });
      }
    } catch (error) {
      this.notice = '文件读取或解析失败：请使用控制台下载的模板格式。';
      this.noticeError = true;
      return;
    }
    this.catalogImport = {
      kind,
      rows,
      result: null,
      applying: false
    };
    return this.perform(async () => {
      const response = await api(config.api + '/import', 'POST', {
        rows: this.catalogImport.rows,
        apply: false
      });
      this.catalogImport.result = response.data;
    }, { message: '预览已生成：请核对逐行结果后再确认导入。', refresh: false });
  }

  applyCatalogImport() {
    const kind = this.catalogImport?.kind;
    const config = catalogKinds[kind];
    if (!config) return;
    return this.perform(async () => {
      const response = await api(config.api + '/import', 'POST', {
        rows: this.catalogImport.rows,
        apply: true
      });
      this.catalogImport.result = response.data;
    }, { message: '导入已应用并记录批次，可随时回滚。' });
  }

  downloadTemplate() {
    const config = catalogKinds[this.tab];
    if (!config) return;
    const content = config.template.map(line => line.split(',').join(',')).join('\n');
    const url = URL.createObjectURL(new Blob(['\uFEFF' + content], { type: 'text/csv;charset=utf-8' }));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'campus-' + this.tab + '-template.csv';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  }

  catalogPanel() {
    const config = catalogKinds[this.tab];
    if (!config) return null;
    const form = this.catalogForm;
    const statuses = ['active', 'inactive'];
    const resourceStatuses = [['active', '正常'], ['needs_review', '待审核'], ['broken', '已失效'], ['archived', '已归档']];
    return m('section.Campus-controlSection', [
      m('div.Campus-controlSectionHead', [m('h2', config.title), m('span', `${this.detailMeta?.total || 0} 条记录`)]),
      m('p.Campus-hint', config.hint),
      m('div.Campus-actions', [
        m(Button, { className: 'Button Button--primary', onclick: () => { this.catalogForm = this.emptyCatalogForm(); } }, '新建记录'),
        m(Button, { onclick: () => this.downloadTemplate() }, '下载导入模板'),
        m('label.Campus-controlUpload', [symbol('file-upload'), ' 选择 CSV / JSON 预览导入', m('input', { type: 'file', accept: '.csv,.json', disabled: this.busy, onchange: e => this.importCatalogFile(e) })])
      ]),
      this.catalogImport ? [this.catalogImportSummary(this.catalogImport.result), this.catalogImport.result && !this.catalogImport.result.batch_id ? m('div.Campus-actions', [m(Button, { className: 'Button Button--primary', loading: this.busy, onclick: () => this.applyCatalogImport() }, '确认导入（写入）'), m(Button, { onclick: () => this.catalogImport = null }, '放弃导入')]) : null] : null,
      form ? m('form.Campus-panel.Campus-catalogForm', {
        onsubmit: e => {
          e.preventDefault();
          const body = {};
          for (const [key] of config.form) body[key] = form[key] === '' ? null : form[key];
          body.status = form.status || (this.tab === 'colleges' ? 'active' : this.tab === 'resources' ? 'needs_review' : undefined);
          if (this.tab === 'resources' && !body.status) delete body.status;
          this.perform(() => api(config.api + (form.id ? '/' + form.id : ''), form.id ? 'PATCH' : 'POST', body), {
            message: '记录已保存并写入审计。',
            after: () => { this.catalogForm = null; }
          });
        }
      }, [m('h3', form.id ? '编辑记录 #' + form.id : '新建记录'), this.tab === 'courses' ? field('college_id', '开课学院', form.college_id, v => form.college_id = v, {
        type: 'select',
        options: [['', '不关联学院'], ...(this.catalogOptions?.colleges || []).map(c => [c.id, c.name + '（' + c.code + '）'])]
      }) : null, ...config.form.filter(([key]) => !(this.tab === 'courses' && key === 'college_id')).map(([key, label, options]) => {
        if (options.type === 'select') {
          return field(key, label, form[key], v => form[key] = v, {
            options: this.tab === 'resources' && key === 'category_id' ? [['', '选择课程归属'], ...(this.catalogOptions?.categories || []).map(c => [c.id, c.name])] : key === 'status' ? (this.tab === 'resources' ? resourceStatuses : statuses.map(s => [s, s === 'active' ? '正常' : '停用'])) : []
          });
        }
        return field(key, label, form[key], v => form[key] = v, options);
      }), m('div.Campus-actions', [m(Button, { type: 'submit', className: 'Button Button--primary' }, '保存记录'), m(Button, { type: 'button', onclick: () => this.catalogForm = null }, '取消')])]) : null,
      this.detail.length ? m('div.Campus-controlQueue', this.detail.map(row => m('article.Campus-controlQueueItem', { key: row.id }, [
        m('h3', row.title || row.name),
        m('p.Campus-controlQueueMeta', config.columns.map(([key, label]) => [label, row[key]]).filter(([, value]) => value !== null && value !== undefined && value !== '').map(([label, value]) => `${label}：${value}`).join(' · ')),
        this.tab === 'resources' && row.url ? m('a', { href: row.url, target: '_blank', rel: 'noopener noreferrer nofollow' }, '打开链接 ↗') : null,
        m('div.Campus-actions', [
          m(Button, { onclick: () => { this.catalogForm = this.emptyCatalogForm(row); } }, '编辑'),
          this.tab === 'colleges' ? m(Button, { onclick: () => this.perform(() => api(config.api + '/' + row.id, 'PATCH', { status: row.status === 'active' ? 'inactive' : 'active' }), { message: '状态已更新并写入审计。' }) }, row.status === 'active' ? '停用' : '启用') : null,
          this.tab === 'resources' ? m(Button, { onclick: () => this.perform(() => api(config.api + '/' + row.id, 'PATCH', { status: 'broken' }), { message: '已标记为失效链接。' }) }, '报告失效') : null
        ])
      ]))) : emptyState('还没有记录', '使用上方表单或批量导入建立目录；导入会先预览再写入。'),
      this.pager(25)
    ]);
  }

  emptyCatalogForm(row = null) {
    const config = catalogKinds[this.tab];
    const form = { id: row?.id || '' };
    for (const [key] of config.form) form[key] = row?.[key] ?? '';
    if (this.tab === 'resources') form.status = row?.status || 'needs_review';
    if (this.tab === 'colleges') form.status = row?.status || 'active';
    if (this.tab === 'courses' && row?.offer) form.college_id = row?.offer?.college_id ?? row?.college_id ?? '';
    if (this.tab === 'courses') form.college_id = row?.college_id ?? '';
    return form;
  }

  content() {
    const d = this.data;
    const job = d.collection_job;
    const running = job && ['queued', 'running'].includes(job.status);
    return m('div.Campus-control', [
      m('header.Campus-controlHeader', [
        m('div', [m('h1', '运营后台'), m('p', '在这里采集、审核并处理校园社区的日常工作。')]),
        m(Button, { onclick: () => this.load(), disabled: this.loading || this.busy }, [symbol('sync-alt'), ' 刷新状态'])
      ]),
      m('nav.Campus-controlTabs', { 'aria-label': '运营后台栏目' }, tabs.map(([name, title, icon]) =>
        link('/campus/control' + (name === 'overview' ? '' : '?tab=' + name), [symbol(icon), title], {
          'aria-current': this.tab === name ? 'page' : undefined
        }))),
      m('p.Campus-controlTimestamp', d.generated_at ? '数据更新于 ' + formatTime(d.generated_at) : '当前为目录管理栏目'),
      d.sources?.collector?.state === 'authorization_required' ? m('div.Campus-notice.is-error', { role: 'alert' },
        '公众号订阅授权已失效，请在采集器管理页面重新扫码；服务器上的本机地址需要安全转发才能访问。') :
      d.sources?.collector?.state === 'unavailable' ? m('div.Campus-notice.is-error', { role: 'alert' },
        '公众号订阅服务不可用，请检查采集工具和运行日志。') : null,
      this.tab === 'review' ? this.reviewPanel() : this.tab === 'sources' ? this.sourcesPanel() :
        this.tab === 'identity' ? this.identityPanel() : this.tab === 'cases' ? this.casesPanel() :
        Object.keys(catalogKinds).includes(this.tab) ? this.catalogPanel() :
      m('div.Campus-controlLayout', [
        m('main.Campus-controlMain', [
          m('section.Campus-controlCollect', [
            m('div.Campus-controlSectionHead', [m('h2', '采集与导入'), m('span', '新内容先进入审核队列')]),
            m('div.Campus-controlCollectActions', [
              m('div', [m('h3', '服务器直接采集'), m('p', '抓取已启用的公开来源；执行结果会显示在下方。'),
                m(Button, { className: 'Button Button--primary', disabled: running || this.busy, onclick: () => this.collect() },
                  [symbol('sync-alt'), ' 一键采集'])]),
              m('div', [m('h3', '从本地电脑导入'), m('p', '若学校官网无法从服务器访问，请先在电脑生成官网 JSON 快照。'),
                m('label.Campus-controlUpload', [symbol('file-upload'), ' 选择快照并导入',
                  m('input', { type: 'file', accept: '.json,application/json', disabled: this.busy, onchange: e => this.importFile(e) })]),
                link('/campus/control?tab=review', '查看待审核内容')])
            ]),
            this.importResult ? m('p.Campus-controlImportResult', { role: 'status' },
              `本次核对 ${this.importResult.sources} 个来源，新增 ${this.importResult.added} 条待审信息。`) : null,
            job ? m('div.Campus-controlJob', { role: 'status', 'aria-live': 'polite' }, [
              m('strong', `最近任务 #${job.id} · ${jobNames[job.status] || job.status}`),
              m('small', '发起于 ' + formatTime(job.created_at)),
              job.result ? m('pre', job.result) : running ? m('p', '正在等待后台执行；可稍后刷新状态。') : null
            ]) : m('p.Campus-controlEmpty', '还没有由控制台发起的采集任务。')
          ]),
          m('section.Campus-controlSection', [
            m('div.Campus-controlSectionHead', [m('h2', '工作入口'), m('span', '所有日常处理集中在此')]),
            m('div.Campus-controlWork', [
              link('/campus/control?tab=sources', [symbol('rss'), m('span', [m('strong', '来源清单'), m('small', `${d.sources.total} 个已登记来源`)]), symbol('arrow-right')], { className: 'Campus-controlWorkLink' }),
              link('/campus/control?tab=review', [symbol('clipboard-check'), m('span', [m('strong', '信息审核'), m('small', `${d.work.aggregation_pending} 条待审`)]), symbol('arrow-right')], { className: 'Campus-controlWorkLink' }),
              link('/campus/control?tab=identity', [symbol('user-check'), m('span', [m('strong', '身份审核'), m('small', `${d.work.identity_pending} 条待审`)]), symbol('arrow-right')], { className: 'Campus-controlWorkLink' }),
              link('/campus/control?tab=cases', [symbol('flag'), m('span', [m('strong', '举报处理'), m('small', `${d.work.cases_pending} 条未结`)]), symbol('arrow-right')], { className: 'Campus-controlWorkLink' })
            ])
          ])
        ]),
        m('aside.Campus-controlAside', [
          m('section.Campus-controlSummary', [m('h2', '站点概况'), m('dl', [
            ['用户', d.community.users], ['讨论', d.community.discussions], ['课程', d.content.courses], ['校园内容', d.content.records]
          ].map(([label, count]) => m('div', [m('dt', label), m('dd', count)])))]),
          m('section.Campus-controlSources', [m('h2', '最近来源状态'),
            m('p.Campus-controlSourceSummary', `${d.sources.active} 个站内采集已开启 · ${d.sources.errors} 个报错`),
            d.sources.recent.length ? m('ul', d.sources.recent.map(source => m('li', { key: source.id }, [
              m('div.Campus-controlSourceName', [m('strong', source.name), m('span', source.enabled ? '已启用' : '已暂停')]),
              source.last_error ? m('small.Campus-controlSourceError', source.last_error) : m('small', source.last_checked_at ? '上次检查 ' + formatTime(source.last_checked_at) : '尚未检查'),
              ['ucass-notices', 'ucass-vsb', 'ucass-employment'].includes(source.adapter) ? m('div.Campus-controlSourceActions', [
                m(Button, { disabled: this.busy, onclick: () => this.toggleSource(source) }, source.enabled ? '暂停' : '启用'),
                m(Button, { disabled: this.busy || running || !source.enabled, onclick: () => this.collect(source.id) }, '单独采集')
              ]) : null
            ]))) : m('p', '还没有登记来源。')])
        ])
      ])
    ]);
  }
}

import app from 'flarum/forum/app';
import Button from './button';
import { DataPage, api, emptyState, field, formatTime, link, pageHeading, symbol } from './ui';
import { pagination } from './navigation';

const categories = { notice: '通知', activity: '活动', lecture: '讲座', opportunity: '机会', other: '其他' };
const stateText = { pending: '待审核', published: '已发布', rejected: '未通过' };
const tabs = active => m('nav.Aggregation-tabs', { 'aria-label': '信息汇集页面' }, [
  link('/campus/aggregation', '信息流', { 'aria-current': active === 'feed' ? 'page' : undefined }),
  app.session.user ? link('/campus/aggregation/submit', '提交线索', { 'aria-current': active === 'submit' ? 'page' : undefined }) : null,
  app.session.user?.isAdmin() ? link('/campus/aggregation/review', '审核候选', { 'aria-current': active === 'review' ? 'page' : undefined }) : null,
  app.session.user?.isAdmin() ? link('/campus/aggregation/sources', '来源管理', { 'aria-current': active === 'sources' ? 'page' : undefined }) : null
]);

const sourceLabel = source => source.trust === 'official' ? '官方来源' : '非官方来源';
const sourceList = entry => m('ul.Aggregation-sources', entry.sources.map((source, index) => m('li', { key: index }, [
  m('span.Aggregation-sourceKind', sourceLabel(source)),
  source.url ? m('a', { href: source.url, target: '_blank', rel: 'noopener noreferrer nofollow' }, source.name + ' ↗') : m('span', source.name),
  source.submitter ? m('span', '投稿：' + source.submitter) : null
])));

export class AggregationPage extends DataPage {
  async fetch() {
    const response = await api('aggregation/items?page=' + (this.params.page || 1));
    this.data = response.data;
    this.meta = response.meta;
  }
  content() {
    return m('div.Aggregation', [
      pageHeading('信息汇集', '来自校园公开网页和成员线索。每条信息标明来源与核实状态，内容以原发布方为准。', app.session.user ? link('/campus/aggregation/submit', '提交线索', { className: 'Button Button--primary' }) : null),
      tabs('feed'),
      m('p.Aggregation-explainer', [symbol('info-circle'), ' “未核实”表示管理员已审核展示，但尚未确认事实；请勿据此直接缴费或报名。']),
      this.data.length ? m('div.Aggregation-list', this.data.map(entry => m('article.Aggregation-entry', { key: entry.id }, [
        m('div.Aggregation-entryTop', [
          m('span.Aggregation-type', categories[entry.category] || '其他'),
          m('span.Aggregation-status', { className: entry.verification === 'verified' ? 'is-verified' : 'is-unverified' }, entry.verification === 'verified' ? '已核实' : '未核实'),
          entry.visibility === 'members' ? m('span.Aggregation-members', '仅登录成员可见') : null
        ]),
        m('h2', entry.title),
        m('p.Aggregation-summary', entry.summary),
        m('div.Aggregation-meta', [
          m('span', '来源日期：' + formatTime(entry.source_published_at || entry.created_at, true)),
          m('span', '来源 ' + entry.sources.length + ' 处')
        ]),
        sourceList(entry),
        entry.verification !== 'verified' ? m('p.Aggregation-caution', '这条信息尚未核实。报名、缴费等事项请到发布机构核对。') : null
      ]))) : emptyState('暂无已发布信息', '抓取结果和成员线索经管理员审核后会出现在这里。', app.session.user ? link('/campus/aggregation/submit', '提交第一条线索') : null),
      this.meta.total > 20 ? pagination({ page: this.meta.page, total: this.meta.total, size: 20, href: page => '/campus/aggregation' + (page > 1 ? '?page=' + page : ''), unit: '条信息' }) : null
    ]);
  }
}

export class AggregationSubmitPage extends DataPage {
  requiresLogin = true;
  oninit(vnode) {
    this.form = { title: '', summary: '', category: 'notice', origin: 'public_web', source_name: '', source_url: '', show_submitter: false };
    super.oninit(vnode);
  }
  async fetch() {
    this.data = (await api('aggregation/mine')).data;
  }
  content() {
    return m('div.Aggregation', [
      pageHeading('提交信息线索', '公开网页和无链接线索都可以提交。管理员审核后才会展示；请勿复制群聊记录或他人个人信息。'),
      tabs('submit'),
      m('div.Aggregation-submitLayout', [
        m('form.Aggregation-form.Campus-panel', { onsubmit: event => {
          event.preventDefault();
          this.perform(() => api('aggregation/submissions', 'POST', this.form), { message: '线索已提交，等待管理员审核。', after: () => {
            this.form = { title: '', summary: '', category: 'notice', origin: 'public_web', source_name: '', source_url: '', show_submitter: false };
          }});
        }}, [
          m('h2', '线索内容'),
          field('title', '标题', this.form.title, value => this.form.title = value, { required: true, minlength: 3, maxlength: 180 }),
          field('summary', '简短说明', this.form.summary, value => this.form.summary = value, { textarea: true, required: true, minlength: 8, maxlength: 1000, hint: '说明发生了什么、时间地点和需要核对的部分；不要粘贴完整文章或群聊内容。' }),
          m('label.Campus-field', [m('span.Campus-fieldLabel', '类别'), m('select.FormControl', { value: this.form.category, onchange: e => this.form.category = e.target.value }, Object.entries(categories).map(([key, label]) => m('option', { value: key }, label)))]),
          m('label.Campus-field', [m('span.Campus-fieldLabel', '来源类型'), m('select.FormControl', { value: this.form.origin, onchange: e => {
            this.form.origin = e.target.value;
            if (this.form.origin === 'private_tip') this.form.source_url = '';
          } }, [m('option', { value: 'public_web' }, '公开网页'), m('option', { value: 'private_tip' }, '无公开链接的线索')])]),
          field('source_name', '来源说明', this.form.source_name, value => this.form.source_name = value, { required: true, minlength: 2, maxlength: 120, placeholder: '例如：教务处通知公告／学院群内线索' }),
          this.form.origin === 'public_web' ? field('source_url', '原始网页链接', this.form.source_url, value => this.form.source_url = value, { type: 'url', required: true, maxlength: 1024 }) : null,
          m('label.Aggregation-checkbox', [m('input[type=checkbox]', { checked: this.form.show_submitter, onchange: e => this.form.show_submitter = e.target.checked }), '公开展示我的昵称']),
          m(Button, { type: 'submit', className: 'Button Button--primary', loading: this.busy }, '提交审核')
        ]),
        m('aside.Aggregation-side', [m('h2', '我的提交'), this.data.length ? m('ul.Aggregation-mine', this.data.map(item => m('li', { key: item.id }, [m('strong', item.title), m('span', stateText[item.status] || item.status), item.review_reason ? m('small', item.review_reason) : null]))) : m('p', '还没有提交记录。')])
      ])
    ]);
  }
}

export class AggregationReviewPage extends DataPage {
  requiresLogin = true;
  oninit(vnode) {
    this.notes = {};
    this.targets = {};
    this.verified = {};
    super.oninit(vnode);
  }
  async fetch() {
    const response = await api('aggregation/queue?page=' + (this.params.page || 1));
    this.data = response.data;
    this.meta = response.meta;
  }
  decide(candidate, action) {
    const item_id = Number(this.targets[candidate.id] || candidate.item_id || 0);
    this.perform(() => api('aggregation/candidates/' + candidate.id + '/review', 'POST', {
      action, reason: this.notes[candidate.id] || '', verified: Boolean(this.verified[candidate.id]),
      ...(action === 'merge' || action === 'update' ? { item_id } : {})
    }), { message: action === 'reject' ? '候选信息已拒绝' : '审核结果已保存' });
  }
  content() {
    return m('div.Aggregation', [
      pageHeading('信息汇集审核', '核对原始来源、合并重复线索，再决定是否公开。网页发生变化时只更新待审候选。'),
      tabs('review'),
      this.data.length ? m('div.Aggregation-reviewList', this.data.map(candidate => m('article.Aggregation-review.Campus-panel', { key: candidate.id }, [
        m('div.Aggregation-entryTop', [m('span.Aggregation-type', categories[candidate.category] || '其他'), m('span.Aggregation-status.is-pending', candidate.origin === 'web' ? '网页抓取' : '用户投稿'), m('span', candidate.visibility === 'members' ? '成员可见线索' : '公开网页')]),
        m('h2', candidate.title), m('p', candidate.summary),
        m('p.Aggregation-meta', [candidate.source_name, candidate.source_url ? ' · ' : '', candidate.source_url ? m('a', { href: candidate.source_url, target: '_blank', rel: 'noopener noreferrer nofollow' }, '核对原网页 ↗') : null]),
        candidate.item_id ? m('p.Aggregation-hint', '相同网址曾关联已发布条目 #' + candidate.item_id + '，请核对是修订还是另一件事。') : null,
        m('label.Aggregation-checkbox', [m('input[type=checkbox]', { checked: Boolean(this.verified[candidate.id]), onchange: e => this.verified[candidate.id] = e.target.checked }), '已核实原始信息']),
        field('reason', '审核说明（拒绝时必填）', this.notes[candidate.id] || '', value => this.notes[candidate.id] = value, { maxlength: 500 }),
        field('item_id', '合并或更新的目标条目编号', this.targets[candidate.id] || candidate.item_id || '', value => this.targets[candidate.id] = value, { type: 'number', min: 1 }),
        m('div.Aggregation-reviewActions', [
          m(Button, { className: 'Button Button--primary', onclick: () => this.decide(candidate, 'publish'), disabled: this.busy }, '发布新条目'),
          m(Button, { onclick: () => this.decide(candidate, 'merge'), disabled: this.busy }, '合并来源'),
          candidate.item_id ? m(Button, { onclick: () => this.decide(candidate, 'update'), disabled: this.busy }, '批准更新') : null,
          m(Button, { onclick: () => this.decide(candidate, 'reject'), disabled: this.busy }, '拒绝')
        ])
      ]))) : emptyState('待审队列已清空', '网页抓取或成员投稿产生的新线索会出现在这里。'),
      this.meta.total > 20 ? pagination({ page: this.meta.page, total: this.meta.total, size: 20, href: page => '/campus/aggregation/review' + (page > 1 ? '?page=' + page : ''), unit: '条候选' }) : null
    ]);
  }
}

const sourceKind = source => source.adapter.startsWith('we-mp-rss') || source.adapter === 'college-wechat-trial' ? '公众号' : '公开网页';
const sourceStatus = source => {
  if (source.adapter.endsWith('-pending') || source.adapter === 'pending-web') return '待接入抓取';
  if (source.adapter === 'we-mp-rss') return '由订阅服务导入';
  if (source.adapter === 'college-wechat-trial') return '人工试验导入';
  return source.enabled ? '已启用' : '已停用';
};

export class AggregationSourcesPage extends DataPage {
  requiresLogin = true;
  oninit(vnode) {
    this.form = { kind: 'web', name: '', url: '' };
    super.oninit(vnode);
  }
  async fetch() {
    this.data = (await api('aggregation/sources')).data;
  }
  content() {
    return m('div.Aggregation', [
      pageHeading('管理信息来源', '先登记要关注的网站和公众号，接入抓取后，新内容会进入管理员审核队列。'),
      tabs('sources'),
      m('p.Aggregation-explainer', [symbol('info-circle'), ' 新登记的来源会标记“待接入抓取”，不会立即自动采集。管理员核对来源并配置适配器或公众号订阅后，才会开始抓取。']),
      m('div.Aggregation-submitLayout', [
        m('form.Aggregation-form.Campus-panel', { onsubmit: event => {
          event.preventDefault();
          this.perform(() => api('aggregation/sources', 'POST', this.form), { message: '来源已登记，等待接入抓取。', after: () => {
            this.form = { ...this.form, name: '', url: '' };
          }});
        }}, [
          m('h2', '登记来源'),
          m('label.Campus-field', [m('span.Campus-fieldLabel', '来源类型'), m('select.FormControl', { value: this.form.kind, onchange: e => { this.form.kind = e.target.value; this.form.url = ''; } }, [
            m('option', { value: 'web' }, '公开网页'), m('option', { value: 'wechat' }, '微信公众号')
          ])]),
          field('name', '名称', this.form.name, value => this.form.name = value, { required: true, minlength: 2, maxlength: 120, placeholder: this.form.kind === 'web' ? '例如：某学院通知公告' : '例如：某学院公众号' }),
          field('url', this.form.kind === 'web' ? '通知列表网址' : '该公众号的一篇原文链接', this.form.url, value => this.form.url = value, { type: 'url', required: true, maxlength: 1024, placeholder: this.form.kind === 'web' ? 'https://example.edu.cn/notice/' : 'https://mp.weixin.qq.com/s/…', hint: this.form.kind === 'web' ? '请填能看到多条通知的公开列表页。' : '公众号名称不是稳定网址，请先提供该账号发布的一篇文章链接，用于识别账号。' }),
          m(Button, { type: 'submit', className: 'Button Button--primary', loading: this.busy }, '登记来源')
        ]),
        m('aside.Aggregation-side', [
          m('h2', '其他入口'),
          m('p', '微信群没有可直接读取的公开列表。群内消息由登录成员提交线索，再由管理员审核。'),
          link('/campus/aggregation/submit', '提交无链接线索'),
          m('p', '公众号订阅与扫码授权目前仍在独立采集工具中完成；登记文章链接本身不会启动订阅。')
        ])
      ]),
      m('section.Aggregation-sourcePanel.Aggregation-sourceManage', [
        m('h2', '已登记来源'),
        this.data.length ? m('ul.Aggregation-sourceRows', this.data.map(source => m('li', { key: source.id }, [
          m('div.Aggregation-entryTop', [m('span.Aggregation-type', sourceKind(source)), m('span.Aggregation-status', { className: sourceStatus(source) === '已启用' ? 'is-verified' : 'is-pending' }, sourceStatus(source))]),
          m('strong', source.name),
          source.listing_url ? m('a', { href: source.listing_url, target: '_blank', rel: 'noopener noreferrer nofollow' }, '查看来源 ↗') : null,
          m('small', source.last_checked_at ? '上次检查：' + formatTime(source.last_checked_at) : '尚未检查'),
          source.last_error ? m('small.Aggregation-caution', '抓取报错：' + source.last_error) : null
        ]))) : m('p', '还没有登记来源。')
      ])
    ]);
  }
}

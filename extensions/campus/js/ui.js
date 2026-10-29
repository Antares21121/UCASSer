import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Link from 'flarum/common/components/Link';
import Button from './button';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
export const api = (path, method = 'GET', body) => app.request({
  method,
  url: app.forum.attribute('apiUrl') + '/campus/' + path,
  body
});
export const link = (href, text, attrs = {}) => m(Link, {
  href,
  ...attrs
}, text);
export const symbol = name => m('i', {
  className: 'fas fa-' + name,
  'aria-hidden': 'true'
});
export const stateLabels = {
  pending: '待处理',
  approved: '已通过',
  rejected: '未通过',
  revoked: '已撤销',
  resolved: '已处理',
  in_progress: '处理中',
  needs_info: '待补充材料',
  appealed: '申诉中',
  reviewed: '已复核',
  discussion: '讨论',
  post: '评论',
  user: '用户行为',
  correction: '内容纠错',
  invalid_link: '链接失效',
  source: '来源问题',
  report: '违规举报',
  created: '已提交',
  supplement: '补充材料',
  supplemented: '补充材料',
  processed: '处理记录',
  maintenance: '维护公告',
  rule: '社区公约',
  team: '职责与交接',
  decision: '治理决定',
  recruit: '志愿招募'
};
export const errorMessage = (e, fallback = '操作失败，请稍后重试。') => {
  if (e.status === 429) return '操作过于频繁，请稍后再试。输入内容已保留。';
  if (e.status === 401) return '请先登录，再继续此操作。';
  if (e.status === 403) return '当前账号没有此操作的权限。';
  if (e.status === 404) return '内容不存在或当前账号无法访问。';
  if (e.status === 0) return '网络连接中断，请检查连接后重试。输入内容已保留。';
  return e.response?.errors?.map(x => x.detail).filter(Boolean).join('；') || fallback;
};
const utcDate = value => {
  if (!value) return null;
  const raw = String(value).replace(' ', 'T');
  const date = new Date(/(?:Z|[+-]\d\d:\d\d)$/.test(raw) ? raw : raw + 'Z');
  return Number.isNaN(date.getTime()) ? null : date;
};
export const formatTime = (value, dateOnly = false) => {
  const date = utcDate(value);
  return date ? date.toLocaleString('zh-CN', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    ...(dateOnly ? {} : {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    })
  }) : '时间未提供';
};
export const localInput = value => {
  const date = utcDate(value);
  return date ? new Date(date.getTime() + 8 * 3600000).toISOString().slice(0, 16) : '';
};
export const utcInput = value => {
  if (!value) return '';
  const date = new Date(value + '+08:00');
  return Number.isNaN(date.getTime()) ? value : date.toISOString().slice(0, 16);
};
export const field = (name, label, value, onchange, options = {}) => {
  const {
    textarea,
    utc,
    hint,
    options: selectOptions,
    ...attrs
  } = options;
  if (textarea) delete attrs.type;
  const control = selectOptions ? m('select.FormControl', {
    name,
    value: value ?? '',
    oninput: e => onchange(e.target.value),
    ...attrs
  }, selectOptions.map(([optionValue, title]) => m('option', {
    value: optionValue
  }, title))) : m(textarea ? 'textarea.FormControl' : 'input.FormControl', {
    name,
    value: utc ? localInput(value) : value ?? '',
    oninput: e => onchange(utc ? utcInput(e.target.value) : e.target.value),
    ...attrs
  });
  return m('label.Campus-field', [m('span.Campus-fieldLabel', label + (utc ? '（北京时间）' : '')), control, hint ? m('small.Campus-hint', hint) : null]);
};
export const pageHeading = (title, description, actions) => m('header.Campus-pageHeader', [m('div', [m('h1', title), description ? m('p.Campus-lead', description) : null]), actions ? m('div.Campus-actions', actions) : null]);
export const emptyState = (title, description, action) => m('div.Campus-empty', [symbol('seedling'), m('strong', title), m('p', description), action]);
export const loginPrompt = (title = '登录后继续', description = '使用你的账号发布、收藏内容，并查看属于自己的记录。') => m('section.Campus-panel.Campus-gate', [symbol('user-circle'), m('h1', title), m('p', description), m('div.Campus-actions', [m(Button, {
  className: 'Button Button--primary',
  onclick: () => app.modal.show(() => import('flarum/forum/components/LogInModal'))
}, '登录账号'), app.forum.attribute('allowSignUp') ? m(Button, {
  className: 'Button',
  onclick: () => app.modal.show(() => import('flarum/forum/components/SignUpModal'))
}, '创建账号') : null]), link('/campus', '返回校园首页')]);
export const shell = (page, content) => m(PageStructure, {
  className: 'CampusPage',
  hero: page.hero ? page.hero.bind(page) : undefined,
  sidebar: () => m(IndexSidebar)
}, m('div.Campus', [page.notice ? m('div.Campus-notice', {
  role: page.noticeError ? 'alert' : 'status',
  className: page.noticeError ? 'is-error' : 'is-success'
}, page.notice) : null, page.busy ? m('p', {
  role: 'status'
}, '正在提交，请稍候…') : null, m('fieldset.Campus-interaction', {
  disabled: Boolean(page.busy),
  'aria-busy': Boolean(page.busy)
}, content), m('footer.Campus-footer', [link('/campus/rules', '社区公约'), link('/campus/governance', '公开治理'), link('/campus/cases', '举报与申诉')])]));
const routeTitles = {
  '/campus/me': '我的校园空间',
  '/campus/cases': '举报、处理与申诉',
  '/campus/identity': '校园身份与权限',
  '/campus/moderation': '分区内容维护',
  '/campus/courses': '课程目录',
  '/campus/search': '全站搜索与发现',
  '/campus/governance': '公开治理与志愿维护',
  '/campus/rules': '社区公约与治理',
  '/campus/aggregation': '信息汇集',
  '/campus/aggregation/submit': '提交信息线索',
  '/campus/aggregation/review': '信息汇集审核',
  '/campus/aggregation/sources': '信息来源管理',
  '/campus/control': '站点控制台'
};
export class DataPage extends Page {
  bodyClass = 'CampusApp';
  oninit(v) {
    super.oninit(v);
    this.notice = '';
    this.error = '';
    this.routeKey = m.route.get();
    this.epoch = 0;
    this.busy = false;
    this.load();
  }
  onbeforeupdate() {
    if (this.routeKey !== m.route.get()) {
      this.routeKey = m.route.get();
      this.notice = '';
      this.load();
    }
    return true;
  }
  onremove(v) {
    this.epoch++;
    super.onremove(v);
  }
  async load() {
    const epoch = ++this.epoch;
    if (this.requiresLogin && !app.session.user) {
      this.loading = false;
      this.error = '';
      app.setTitle(this.pageTitle());
      return;
    }
    this.loading = true;
    this.error = '';
    this.errorStatus = 0;
    // Isolate asynchronous results so a slower previous filter cannot overwrite
    // a newer route. Preserve the existing form only when refreshing this route.
    const draft = Object.create(this);
    draft.params = {
      ...m.route.param()
    };
    try {
      await draft.fetch();
      if (epoch === this.epoch) {
        Object.assign(this, draft);
        app.setTitle(this.pageTitle());
      }
    } catch (e) {
      if (epoch === this.epoch) {
        this.error = errorMessage(e, '加载失败，请稍后重试。');
        this.errorStatus = e.status;
        app.setTitle(this.pageTitle());
      }
    } finally {
      if (epoch === this.epoch) {
        this.loading = false;
        m.redraw();
      }
    }
  }
  pageTitle() {
    return this.data?.title || routeTitles[m.route.get().split('?')[0]] || {
      information: '校园信息',
      resource: '共享资料',
      question: '校园问答',
      review: '课程评价'
    }[this.type || this.form?.type] || '校园公共社区';
  }
  view() {
    if (this.requiresLogin && !app.session.user) return shell(this, loginPrompt(this.pageTitle()));
    return shell(this, this.loading ? m('div.Campus-loading', {
      role: 'status'
    }, [m('div.Campus-skeleton'), m('div.Campus-skeleton'), m('p', '正在加载…')]) : this.error ? this.errorStatus === 401 && !app.session.user ? loginPrompt(this.pageTitle()) : m('section.Campus-panel.Campus-gate', {
      role: 'alert'
    }, [symbol('exclamation-circle'), m('h1', this.errorStatus === 404 ? '内容暂时无法访问' : '页面加载遇到问题'), m('p', this.error), m('div.Campus-actions', [m(Button, {
      onclick: () => this.load()
    }, '重新加载'), link('/campus', '返回首页')])]) : this.content());
  }
  async perform(work, {
    message = '操作已完成',
    refresh = true,
    after
  } = {}) {
    if (this.busy) return false;
    this.busy = true;
    this.notice = '';
    this.noticeError = false;
    m.redraw();
    try {
      const result = await work();
      if (after) await after(result);
      this.notice = message;
      if (refresh) {
        try {
          await this.load();
        } catch (_) {}
      }
      return true;
    } catch (e) {
      this.notice = errorMessage(e);
      this.noticeError = true;
      return false;
    } finally {
      this.busy = false;
      m.redraw();
    }
  }
  act(path, body, options = {}) {
    return this.perform(() => api(path, 'POST', body), options);
  }
}

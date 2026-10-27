import app from 'flarum/forum/app';
import Button from './button';
import PostControls from 'flarum/forum/utils/PostControls';
import { extend } from 'flarum/common/extend';
import { DataPage, api, field, link } from './records';
import { pageHeading, emptyState, stateLabels, formatTime } from './ui';
const actions = {
  none: '记录说明或要求修改',
  hide: '隐藏对象',
  restore: '恢复对象',
  delete: '永久删除（仅管理员）',
  suspend: '限制发布 / 临时封禁（仅管理员）',
  unban: '解除封禁（仅管理员）'
};
const statuses = ['pending', 'in_progress', 'needs_info', 'resolved', 'rejected', 'appealed', 'reviewed'];
export class CasesPage extends DataPage {
  requiresLogin = true;
  async fetch() {
    this.id = this.params.id;
    this.filterStatus = this.params.status || '';
    this.mine = this.params.mine === '1';
    const r = await api(this.id ? 'cases/' + this.id : 'cases?' + new URLSearchParams(this.params));
    this.cases = this.id ? [r.data] : r.data;
    this.meta = r.meta || {
      page: 1,
      total: 1,
      has_more: false
    };
    this.page = this.meta.page;
    const context = [this.params.discussion_id || '', this.params.target_type || 'discussion', this.params.target_id || ''].join(':');
    if (!this.form || context !== this.formContext) {
      this.formContext = context;
      this.form = {
        discussion_id: this.params.discussion_id || '',
        target_type: this.params.target_type || 'discussion',
        target_id: this.params.target_id || '',
        reason: ''
      };
      this.showReport = Boolean(this.params.discussion_id);
      this.contextTitle = '';
    }
    if (this.form.discussion_id && !this.contextTitle) {
      try {
        this.contextTitle = (await app.request({
          method: 'GET',
          url: app.forum.attribute('apiUrl') + '/discussions/' + this.form.discussion_id
        })).data.attributes.title;
      } catch (_) {
        this.contextTitle = '当前账号无法读取讨论，请确认来源。';
      }
    }
  }
  process(c, kind, body) {
    return this.perform(() => api('cases/' + c.id + (kind ? '/' + kind : ''), kind ? 'POST' : 'PATCH', {
      version: c.version,
      ...body
    }), {
      message: '状态已更新并记录处理过程'
    });
  }
  select(label, value, set, items) {
    return m('label.Campus-field', [m('span.Campus-fieldLabel', label), m('select.FormControl', {
      value,
      onchange: e => set(e.target.value)
    }, items.map(([v, text]) => m('option', {
      value: v
    }, text)))]);
  }
  reportForm() {
    return m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        this.perform(() => api('cases', 'POST', {
          ...this.form,
          discussion_id: Number(this.form.discussion_id),
          target_id: Number(this.form.target_id || this.form.discussion_id)
        }), {
          refresh: false,
          message: '举报已提交，可以查看处理进度',
          after: r => {
            this.form.reason = '';
            this.showReport = false;
            m.route.set('/campus/cases', {
              id: r.data.id
            });
          }
        });
      }
    }, [m('h2', '提交举报'), m('p.Campus-hint', '请提供具体事实与必要证据，不要提交无关的个人材料。举报不会自动处罚。'), this.contextTitle ? m('p', [m('strong', '关联讨论：'), this.contextTitle]) : null, !this.params.discussion_id ? [field('discussion_search', '按标题查找讨论', this.discussionQuery, v => this.discussionQuery = v, {
      type: 'search'
    }), m(Button, {
      type: 'button',
      onclick: () => this.perform(async () => {
        this.discussionChoices = (await api('search?q=' + encodeURIComponent(this.discussionQuery || ''))).data.slice(0, 8);
      }, {
        refresh: false,
        message: '请选择关联讨论'
      })
    }, '查找讨论'), (this.discussionChoices || []).map(d => m('p', [m(Button, {
      type: 'button',
      onclick: () => {
        this.form.discussion_id = d.id;
        this.form.target_type = 'discussion';
        this.form.target_id = d.id;
        this.contextTitle = d.title;
      }
    }, '选择'), d.title]))] : null, m('details.Campus-filters', {
      open: !this.form.discussion_id
    }, [m('summary', '查看或修改举报对象'), field('discussion_id', '讨论编号', this.form.discussion_id, v => {
      this.form.discussion_id = v;
      this.contextTitle = '';
    }, {
      type: 'number',
      min: 1,
      required: true
    }), this.select('举报对象', this.form.target_type, v => {
      this.form.target_type = v;
      this.form.target_id = '';
    }, [['discussion', '讨论'], ['post', '评论'], ['user', '用户行为（需要该讨论上下文）']]), field('target_id', '对象编号（讨论举报可留空）', this.form.target_id, v => this.form.target_id = v, {
      type: 'number',
      min: 1
    })]), field('reason', '举报原因与必要证据', this.form.reason, v => this.form.reason = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 2000
    }), m('div.Campus-actions', [m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '提交举报'), m(Button, {
      type: 'button',
      onclick: () => this.showReport = false
    }, '取消')])]);
  }
  caseCard(c) {
    const canProcess = c.can_manage && ['pending', 'in_progress', 'needs_info', 'appealed'].includes(c.status) && (c.status !== 'appealed' || c.can_review);
    return m('article.Campus-panel', [m('div.Campus-badges', [m('span.Campus-badge', {
      className: 'is-' + c.status
    }, stateLabels[c.status]), m('span.Campus-badge', (stateLabels[c.target_type] || c.target_type) + ' #' + c.target_id)]), m('h2', '处理记录 #' + c.id), m('small', '更新 ' + formatTime(c.updated_at) + '（北京时间）'), c.reason ? m('p.Campus-text', '举报说明：' + c.reason) : null, m('p.Campus-text', '处理结果：' + (c.resolution || '等待处理')), m('div.Campus-actions', [link('/d/' + c.discussion_id, '查看关联讨论'), this.id ? link('/campus/cases', '返回全部记录') : link('/campus/cases?id=' + c.id, '查看此记录')]), m('details.Campus-filters', [m('summary', '查看处理过程'), m('ul.Campus-timeline', c.events.map(ev => m('li', [m('strong', stateLabels[ev.action] || ev.action), m('small', formatTime(ev.created_at)), m('p.Campus-text', ev.message)])))]), canProcess && !c.editing ? m(Button, {
      onclick: () => c.editing = true
    }, c.status === 'appealed' ? '独立复核申诉' : '处理举报') : null, canProcess && c.editing ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.process(c, '', {
          status: c.nextStatus || (c.status === 'appealed' ? 'reviewed' : 'resolved'),
          action: c.action || 'none',
          reason: c.note || '',
          confirmed: c.confirmed === true,
          until: c.until ? new Date(c.until + 'Z').toISOString() : null
        });
      }
    }, [m('h3', c.status === 'appealed' ? '独立复核申诉' : '处理举报'), this.select('处理状态', c.nextStatus || (c.status === 'appealed' ? 'reviewed' : 'resolved'), v => c.nextStatus = v, (c.status === 'appealed' ? ['reviewed'] : ['resolved', 'in_progress', 'needs_info', 'rejected']).map(v => [v, stateLabels[v]])), this.select('处置操作', c.action || 'none', v => {
      c.action = v;
      c.confirmed = false;
    }, (c.allowed_actions || ['none', 'hide', 'restore']).map(v => [v, actions[v]])), c.action === 'suspend' ? field('until', '封禁截止时间', c.until, v => c.until = v, {
      type: 'datetime-local',
      utc: true,
      required: true
    }) : null, field('resolution', '公开给当事人的处理理由', c.note, v => c.note = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500,
      hint: '不要引用举报者身份与私有材料。'
    }), c.action === 'delete' ? m('label', [m('input', {
      type: 'checkbox',
      required: true,
      checked: c.confirmed,
      onchange: e => c.confirmed = e.target.checked
    }), ' 明确确认永久删除，此操作不能撤销']) : null, m('div.Campus-actions', [m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '记录并执行处置'), m(Button, {
      type: 'button',
      onclick: () => c.editing = false
    }, '取消处理')])]) : null, c.status === 'appealed' && c.can_manage && !c.can_review ? m('p.Campus-hint', '你是原处理人，本次申诉需要由另一位获授权人员独立复核。') : null, c.can_appeal ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.process(c, 'appeal', {
          reason: c.appeal || ''
        });
      }
    }, [field('appeal', '申诉说明', c.appeal, v => c.appeal = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit'
    }, '提交申诉')]) : null, c.can_supplement ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.process(c, 'supplement', {
          reason: c.supplement || ''
        });
      }
    }, [field('supplement', '补充举报说明', c.supplement, v => c.supplement = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit'
    }, '补充材料')]) : null]);
  }
  content() {
    return [pageHeading('举报、处理与申诉', '材料仅本人和获授权人员可见；当事人可以申诉，由独立人员复核。', m(Button, {
      onclick: () => this.showReport = !this.showReport,
      className: 'Button Button--primary'
    }, this.showReport ? '收起举报表单' : '提交新举报')), this.showReport ? this.reportForm() : null, !this.id ? m('form.Campus-panel.Campus-filterGrid', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set('/campus/cases', {
          status: this.filterStatus || '',
          mine: this.mine ? '1' : '',
          page: 1
        });
      }
    }, [this.select('处理状态', this.filterStatus ?? this.params.status ?? '', v => this.filterStatus = v, [['', '全部状态'], ['open', '未结案件'], ...statuses.map(v => [v, stateLabels[v]])]), m('label', [m('input', {
      type: 'checkbox',
      checked: this.mine ?? this.params.mine === '1',
      onchange: e => this.mine = e.target.checked
    }), ' 只看我提交的举报']), m(Button, {
      type: 'submit'
    }, '筛选记录')]) : null, ...this.cases.map(c => this.caseCard(c)), this.cases.length ? null : emptyState('暂无可查看的处理记录', '可以从具体帖子或内容详情发起举报，再在这里跟踪处理。'), !this.id ? m('nav.Campus-pagination', {
      'aria-label': '举报记录分页'
    }, [this.page > 1 ? link('/campus/cases?' + new URLSearchParams({
      ...this.params,
      page: this.page - 1
    }), '上一页') : null, this.meta.has_more ? link('/campus/cases?' + new URLSearchParams({
      ...this.params,
      page: this.page + 1
    }), '下一页') : null, m('span', `第 ${this.page} 页 · 共 ${this.meta.total} 条`)]) : null];
  }
}
export function registerReports() {
  extend(PostControls, 'userControls', function (items, post) {
    if (!app.session.user) return;
    items.add('campus-case', m(Button, {
      icon: 'fas fa-flag',
      onclick: () => m.route.set('/campus/cases', {
        discussion_id: post.discussion().id(),
        target_type: 'post',
        target_id: post.id()
      })
    }, '举报并跟踪处理'), 1);
  });
}

import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
export const api = (path, method = 'GET', body) => app.request({
  method,
  url: app.forum.attribute('apiUrl') + '/campus/' + path,
  body
});
export const labels = {
  information: '校园信息',
  resource: '共享资料',
  question: '校园问答',
  review: '课程评价',
  active: '有效',
  ended: '已结束',
  resolved: '已解决',
  invalid: '已失效',
  sold: '已成交',
  expired: '已过期',
  needs_verification: '待核验',
  waiting: '等待回答',
  discussing: '讨论中',
  withdrawn: '已撤回',
  source: '来源',
  source_url: '原始链接',
  published_at: '来源发布时间',
  location: '地点或线上参与方式',
  organizer: '主办方或发布单位',
  starts_at: '开始时间',
  ends_at: '结束时间',
  deadline: '截止时间',
  apply_url: '报名或申请链接',
  conditions: '参与条件',
  contact: '联系方式（请确认允许公开）',
  offer_type: '需求或供给说明',
  tags: '标签（逗号分隔）',
  license: '版权或使用说明',
  course: '适用课程',
  topic: '主题',
  grade: '年级',
  edition: '版本',
  updated_at: '来源更新时间',
  alternate_url: '替代链接',
  last_verified: '最近核验时间',
  background: '补充背景',
  assessment: '考核方式',
  participation: '课堂参与要求',
  gains: '学习收获',
  prerequisites: '先修知识',
  study: '复习与备考经验',
  experience: '学习体验',
  advice: '后续建议',
  overall: '总体体验（1 不满意，5 满意）',
  difficulty: '难度（1 易，5 难）',
  workload: '工作量（1 少，5 多）',
  semester: '适用学期'
};
export const link = (href, text) => m(Link, {
  href
}, text);
export const field = (name, label, value, onchange, options = {}) => m('label.Campus-field', [label, m(options.textarea ? 'textarea.FormControl' : 'input.FormControl', {
  name,
  value: value ?? '',
  oninput: e => onchange(e.target.value),
  ...options
})]);
export class DataPage extends Page {
  oninit(v) {
    super.oninit(v);
    this.notice = '';
    this.error = '';
    this.routeKey = m.route.get();
    this.load();
  }
  onbeforeupdate() {
    if (this.routeKey !== m.route.get()) {
      this.routeKey = m.route.get();
      this.load();
    }
    return true;
  }
  async load() {
    this.loading = true;
    this.error = '';
    try {
      await this.fetch();
    } catch (e) {
      this.error = e.status === 401 || e.status === 403 ? '请登录或申请所需权限。' : e.status === 404 ? '内容不存在或无法访问。' : '加载失败，请稍后重试。';
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
  view() {
    return m('div.Campus.container', [m('nav.Campus-nav', [link('/campus', '首页'), link('/campus/records?type=information', '校园信息'), link('/campus/records?type=resource', '共享资料'), link('/campus/records?type=question', '问答'), link('/campus/courses', '课程'), link('/campus/identity', '身份与账号'), link('/campus/rules', '社区公约')]), m('p', {
      role: 'status'
    }, this.notice), this.loading ? m('p', '正在加载…') : this.error ? m('div', {
      role: 'alert'
    }, [m('p', this.error), m(Button, {
      onclick: () => this.load()
    }, '重新加载')]) : this.content()]);
  }
  async act(path, body) {
    try {
      await api(path, 'POST', body);
      this.notice = '操作已完成';
      await this.fetch();
    } catch (e) {
      this.notice = e.response?.errors?.map(x => x.detail).join('；') || '操作失败，请检查字段与权限。';
    }
    m.redraw();
  }
}
export class RecordsPage extends DataPage {
  async fetch() {
    this.type = m.route.param('type') || 'information';
    this.search = m.route.param('search') || '';
    this.category = m.route.param('category') || '';
    this.status = m.route.param('status') || '';
    this.resourceFilters = {
      course: m.route.param('course') || '',
      topic: m.route.param('topic') || '',
      grade: m.route.param('grade') || ''
    };
    this.page = Number(m.route.param('page') || 1);
    this.catalog = (await api('catalog')).data;
    const r = await api('records?' + new URLSearchParams({
      ...m.route.param(),
      type: this.type,
      page: this.page
    }));
    this.items = r.data;
    this.meta = r.meta;
  }
  card(d) {
    return m('article.Campus-panel', [m('h2', link('/campus/record/' + d.id, d.title)), m('p', [labels[d.type] || d.type, ' · ', this.catalog.categories[d.type]?.[d.category] || d.category, ' · ', labels[d.status] || d.status, d.featured ? ' · 精选' : '']), m('small', `${d.author} · 更新 ${d.updated_at} UTC · ${d.comments} 条评论`), d.fields.source ? m('p', '来源：' + d.fields.source) : null]);
  }
  content() {
    const cats = this.catalog.categories[this.type] || {};
    return [m('h1', labels[this.type] || '内容检索'), app.session.user ? m('p', [link('/campus/publish?type=' + this.type, '发布' + (labels[this.type] || '内容')), ' · ', link('/campus/records?type=' + this.type + '&bookmarked=1', '我的收藏'), ' · ', link('/campus/records?type=' + this.type + '&mine=1', '我的发布')]) : m('p', '登录后可发布、收藏和反馈。'), m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set('/campus/records', {
          ...m.route.param(),
          page: 1,
          type: this.type,
          search: this.search,
          category: this.category,
          status: this.status,
          ...this.resourceFilters
        });
      }
    }, [field('search', '搜索标题、正文与标签', this.search, v => this.search = v), m('label', ['分类', m('select.FormControl', {
      value: this.category,
      onchange: e => this.category = e.target.value
    }, [m('option', {
      value: ''
    }, '全部'), ...Object.entries(cats).map(([value, text]) => m('option', {
      value
    }, text))])]), m('label', ['状态', m('select.FormControl', {
      value: this.status,
      onchange: e => this.status = e.target.value
    }, [m('option', {
      value: ''
    }, '全部'), ...(this.catalog.states[this.type] || []).map(value => m('option', {
      value
    }, labels[value] || value))])]), this.type === 'resource' ? Object.entries(this.resourceFilters).map(([k, v]) => field(k, labels[k], v, value => this.resourceFilters[k] = value)) : null, m(Button, {
      type: 'submit'
    }, '查询')]), this.items.length ? this.items.map(d => this.card(d)) : m('p.Campus-empty', '暂无匹配内容，请调整条件或发布第一条。'), m('nav.Campus-nav', [this.page > 1 ? link('/campus/records?' + new URLSearchParams({
      ...m.route.param(),
      page: this.page - 1
    }), '上一页') : null, this.page * 20 < this.meta.total ? link('/campus/records?' + new URLSearchParams({
      ...m.route.param(),
      page: this.page + 1
    }), '下一页') : null, m('span', `第 ${this.page} 页 · 共 ${this.meta.total} 条`)])];
  }
}
export class RecordPage extends DataPage {
  async fetch() {
    this.data = (await api('records/' + m.route.param('id'))).data;
    this.catalog = (await api('catalog')).data;
  }
  content() {
    const d = this.data;
    return [m('h1', d.title), m('p', `${d.author} · ${labels[d.status] || d.status} · 更新 ${d.updated_at} UTC`), d.can_edit ? link('/campus/publish?id=' + d.id, '编辑内容或状态') : null, m('section.Campus-panel', [m('p.Campus-text', d.body), ...Object.entries(d.fields).filter(([k, v]) => k !== 'related' && v !== '' && v != null).map(([k, v]) => m('p', [m('strong', (labels[k] || k) + '：'), k.endsWith('_url') ? m('a', {
      href: v,
      target: '_blank',
      rel: 'noopener noreferrer nofollow'
    }, v) : String(v)])), d.fields.related?.length ? m('p', ['关联内容：', d.fields.related.map(r => link('/campus/record/' + r.id, r.title + ' '))]) : null]), app.session.user ? m(Button, {
      onclick: () => this.act('records/' + d.id + '/bookmark', {
        enabled: !d.bookmarked,
        following: d.following
      })
    }, d.bookmarked ? '取消收藏' : '收藏内容') : null, app.session.user ? m(Button, {
      onclick: () => this.act('records/' + d.id + '/bookmark', {
        enabled: d.bookmarked,
        following: !d.following
      })
    }, d.following ? '取消关注讨论' : '关注讨论') : null, d.type === 'review' ? m('p', ['有用反馈 ' + d.useful + ' 次 · ', app.session.user && app.session.user.id() !== String(d.author_id) && d.status === 'active' ? m(Button, {
      onclick: () => this.act('records/' + d.id + '/useful', {
        enabled: !d.voted
      })
    }, d.voted ? '取消有用反馈' : '这条评价有帮助') : null]) : null, link('/d/' + d.discussion_id, '前往原生讨论（格式化正文、帖子操作与举报）'), m('h2', d.type === 'question' ? '答案与讨论' : '评论'), d.answers.length ? d.answers.map(p => m('article.Campus-panel', [m('small', p.author + ' · ' + p.created_at), m('p.Campus-text', p.body), d.accepted_post_id === p.id ? m('strong', '已采纳答案') : null, d.type === 'question' && app.session.user?.id() === String(d.author_id) ? m(Button, {
      onclick: () => this.act('records/' + d.id + '/accept', {
        post_id: d.accepted_post_id === p.id ? null : p.id
      })
    }, d.accepted_post_id === p.id ? '取消采纳' : '采纳此答案') : null, d.type === 'question' && d.can_moderate ? m(Button, {
      onclick: () => this.convert = p.id
    }, '整理为资料') : null])) : m('p', '暂无评论。'), this.convert ? m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        const s = this.catalog.sections.find(s => s.key === 'resources');
        try {
          const r = await api('records/' + d.id + '/convert', 'POST', {
            post_id: this.convert,
            title: this.convertTitle || '',
            category: 'faq',
            section_id: s?.id,
            license: this.convertLicense || ''
          });
          m.route.set('/campus/record/' + r.data.id);
        } catch (_) {
          this.notice = '整理失败，请检查资料维护权限、访问范围、标题与使用授权。';
          m.redraw();
        }
      }
    }, [m('h2', '整理答案为公共资料'), m('p', '请先核实答案的转载授权，不得扩大受限内容的访问范围。来源与原答案关联会被保留。'), field('convert_title', '资料名称', this.convertTitle, v => this.convertTitle = v, {
      required: true,
      minlength: 3,
      maxlength: 80
    }), field('convert_license', '版权或使用授权说明', this.convertLicense, v => this.convertLicense = v, {
      required: true,
      minlength: 5
    }), m(Button, {
      type: 'submit'
    }, '创建关联资料')]) : null, app.session.user ? m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        this.act('records/' + d.id + '/comments', {
          body: this.comment || ''
        }).then(() => this.comment = '');
      }
    }, [field('comment', '评论', this.comment, v => this.comment = v, {
      textarea: true,
      required: true,
      minlength: 5
    }), m(Button, {
      type: 'submit'
    }, '发表评论')]) : null, app.session.user ? m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        this.act('records/' + d.id + '/feedback', {
          kind: this.kind || 'correction',
          message: this.message || ''
        });
      }
    }, [m('h2', '纠错与举报'), m('select.FormControl', {
      onchange: e => this.kind = e.target.value
    }, [['correction', '内容纠错'], ['invalid_link', '链接失效'], ['source', '来源问题'], ['report', '侵权、隐私或违规举报']].map(([value, text]) => m('option', {
      value
    }, text))), field('message', '具体说明（仅本人和获授权人员可见）', this.message, v => this.message = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 2000
    }), m(Button, {
      type: 'submit'
    }, '提交反馈')]) : null, m('h2', '我的反馈与处理结果'), d.feedback.length ? d.feedback.map(f => m('article.Campus-panel', [m('p', `${f.kind} · ${f.status} · ${f.message}`), m('p', f.resolution || '等待处理'), d.can_moderate && f.status === 'pending' ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.act('records/' + d.id + '/feedback/resolve', {
          feedback_id: f.id,
          status: f.decision || 'resolved',
          reason: f.note || ''
        });
      }
    }, [m('select.FormControl', {
      onchange: e => f.decision = e.target.value
    }, [m('option', {
      value: 'resolved'
    }, '已处理'), m('option', {
      value: 'rejected'
    }, '不予处理')]), field('resolution', '处理理由', f.note, v => f.note = v, {
      required: true,
      minlength: 5
    }), m(Button, {
      type: 'submit'
    }, '记录处理结果')]) : null])) : m('p', '暂无可查看反馈。'), m('h2', '修改记录'), d.history.length ? m('ul', d.history.map(h => m('li', `版本 ${h.version} · ${h.created_at} UTC · ${h.reason}`))) : m('p', '尚未修改。')];
  }
}
export class PublishPage extends DataPage {
  async fetch() {
    this.catalog = (await api('catalog')).data;
    this.id = m.route.param('id');
    if (this.id) {
      const d = (await api('records/' + this.id)).data;
      this.form = {
        ...d,
        ...d.fields,
        status: d.stored_status,
        related: d.fields.related.map(x => x.id)
      };
    } else {
      const type = m.route.param('type') || 'information';
      const sectionKey = {
        resource: 'resources',
        question: 'questions',
        review: 'courses'
      }[type];
      this.form = {
        type,
        category: Object.keys(this.catalog.categories[type] || {})[0],
        status: this.catalog.states[type]?.[0],
        section_id: (this.catalog.sections.find(s => s.key === sectionKey) || this.catalog.sections[0])?.id,
        related: [],
        featured: false,
        title: '',
        body: ''
      };
    }
  }
  async loadCourses() {
    this.courses = (await api('courses?' + new URLSearchParams(this.courseSearch ? {
      search: this.courseSearch
    } : this.form.course_id ? {
      id: this.form.course_id
    } : {}))).data;
    if (!this.form.course_id && this.courses[0]) this.form.course_id = this.courses[0].id;
    if (!this.form.semester) this.form.semester = m.route.param('semester') || this.courses.find(c => c.id === this.form.course_id)?.semester || '';
    m.redraw();
  }
  onbeforeupdate() {
    const changed = super.onbeforeupdate();
    if (this.form?.type === 'review' && !this.courses && !this.courseLoading) {
      this.courseLoading = true;
      this.form.course_id = this.form.course_id || Number(m.route.param('course_id') || 0);
      for (const k of ['overall', 'difficulty', 'workload']) this.form[k] = this.form[k] || 3;
      this.loadCourses().catch(() => {
        this.notice = '课程加载失败，请重试搜索。';
        m.redraw();
      }).finally(() => this.courseLoading = false);
    }
    return changed;
  }
  async save() {
    if (this.saving) return;
    this.saving = true;
    try {
      const d = (await api(this.id ? 'records/' + this.id : 'records', this.id ? 'PATCH' : 'POST', this.form)).data;
      m.route.set('/campus/record/' + d.id);
    } catch (e) {
      this.notice = e.response?.errors?.map(x => x.detail).join('；') || '保存失败，请检查字段、权限或刷新后重新编辑。';
    } finally {
      this.saving = false;
      m.redraw();
    }
  }
  content() {
    if (!app.session.user) return m('p', '请登录后发布内容。');
    const f = this.form;
    return [m('h1', (this.id ? '编辑' : '发布') + (labels[f.type] || '内容')), m('p', '提交内容将使用当前正常账号公开发布。日期时间使用 UTC；联系方式请确认允许公开。'), m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        this.save();
      }
    }, [field('title', '标题', f.title, v => f.title = v, {
      required: true,
      minlength: 3,
      maxlength: 80
    }), field('body', '详细内容', f.body, v => f.body = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 30000
    }), m('label', ['分区', m('select.FormControl', {
      value: f.section_id,
      onchange: e => f.section_id = Number(e.target.value)
    }, this.catalog.sections.map(s => m('option', {
      value: s.id
    }, s.name)))]), m('label', ['分类', m('select.FormControl', {
      value: f.category,
      onchange: e => f.category = e.target.value
    }, Object.entries(this.catalog.categories[f.type] || {}).map(([value, text]) => m('option', {
      value
    }, text)))]), m('label', ['状态', m('select.FormControl', {
      value: f.status,
      onchange: e => f.status = e.target.value
    }, (this.catalog.states[f.type] || []).map(value => m('option', {
      value
    }, labels[value] || value)))]), f.type === 'review' ? [field('course_search', '查找课程', this.courseSearch, v => this.courseSearch = v), m(Button, {
      type: 'button',
      onclick: () => this.loadCourses().catch(() => {
        this.notice = '搜索课程失败';
        m.redraw();
      })
    }, '搜索可选课程'), m('label', ['关联课程', m('select.FormControl', {
      required: true,
      value: f.course_id,
      onchange: e => {
        f.course_id = Number(e.target.value);
        f.semester = this.courses.find(c => c.id === f.course_id)?.semester || f.semester;
      }
    }, [m('option', {
      value: ''
    }, '选择课程'), ...(this.courses || []).map(c => m('option', {
      value: c.id
    }, c.name + ' · ' + c.semester))])]), field('semester', labels.semester, f.semester, v => f.semester = v, {
      required: true,
      minlength: 2,
      maxlength: 80
    }), ...['overall', 'difficulty', 'workload'].map(k => m('label', [labels[k], m('select.FormControl', {
      value: f[k],
      onchange: e => f[k] = Number(e.target.value)
    }, [1, 2, 3, 4, 5].map(value => m('option', {
      value
    }, String(value))))])), m('p', '撤回会隐藏评价及其讨论，并从样本统计中移除；作者和管理员仍可查看。恢复后重新进入统计。')] : null, ...(this.catalog.fields[f.type] || []).map(k => field(k, labels[k] || k, f[k], v => f[k] = v, {
      type: k.endsWith('_url') ? 'url' : ['published_at', 'starts_at', 'ends_at', 'deadline', 'updated_at', 'last_verified'].includes(k) ? 'datetime-local' : 'text',
      required: k === 'source'
    })), field('related', '关联内容编号（逗号分隔，最多十条）', f.related.join(','), v => f.related = v ? v.split(',').map(Number) : []), this.id ? field('reason', '修改理由', f.reason, v => f.reason = v, {
      required: true,
      minlength: 5,
      maxlength: 500
    }) : null, this.id && f.can_moderate ? m('label', [m('input', {
      type: 'checkbox',
      checked: f.featured,
      onchange: e => f.featured = e.target.checked
    }), ' 精选内容']) : null, m(Button, {
      type: 'button',
      onclick: () => this.preview = !this.preview
    }, this.preview ? '关闭预览' : '预览正文'), this.preview ? m('article.Campus-panel', [m('h2', f.title), m('p.Campus-text', f.body)]) : null, m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, this.id ? '保存修改' : '发布内容')])];
  }
}

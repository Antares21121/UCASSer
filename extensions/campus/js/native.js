import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Page from 'flarum/common/components/Page';
import Modal from 'flarum/common/components/Modal';
import IndexPage from 'flarum/forum/components/IndexPage';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import UserPage from 'flarum/forum/components/UserPage';
import HeaderPrimary from 'flarum/forum/components/HeaderPrimary';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import WelcomeHero from 'flarum/forum/components/WelcomeHero';
import GlobalSearchState from 'flarum/forum/states/GlobalSearchState';
import DiscussionListState from 'flarum/forum/states/DiscussionListState';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { extend, override } from 'flarum/common/extend';
import Button from './button';
import { labels } from './records';
import { DataPage, api, field, link, pageHeading, emptyState, symbol, formatTime, errorMessage, loginPrompt, shell } from './ui';
import { PersonalPage as Collections } from './personal';
import { boards, BoardHero, routeBoard, boardKey, boardParams, recordOverview, registerBoards } from './boards';
const icons = {
  news: 'bullhorn',
  careers: 'briefcase',
  academic: 'microscope',
  life: 'hands-helping',
  market: 'exchange-alt',
  resources: 'book-open',
  courses: 'graduation-cap',
  questions: 'comment-dots',
  chat: 'comments',
  governance: 'balance-scale'
};
const primary = [['/campus', '首页', 'compass'], ['/campus/records?type=information', '校园信息', 'bullhorn'], ['/t/campus-resources', '共享资料', 'book-open'], ['/t/campus-questions', '问答', 'comment-dots'], ['/t/campus-courses', '课程经验', 'graduation-cap']];
const extras = [['/campus/aggregation', '信息汇集', 'stream'], ['/campus/courses', '课程目录', 'graduation-cap'], ['/campus/search', '详细检索', 'search'], ['/campus/identity', '校园身份', 'id-card'], ['/campus/governance', '公开治理', 'balance-scale'], ['/campus/cases', '举报与申诉', 'flag'], ['/campus/rules', '社区公约', 'handshake']];
const isCampusHome = () => ['/', '/campus', '/all'].includes((m.route.get() || '').split('?')[0]);
const homePaths = [
  ['/campus/aggregation', '查看校园信息', '学校、学院与同学分享的消息，逐条标明来源和核验状态。', 'bullhorn'],
  ['/resources', '寻找学习资料', '按学院和课程查找资料链接，也能查看校外学习资源。', 'book-open'],
  ['/t/campus-questions', '提出一个问题', '从已有问答里找经验，或向社区发起讨论。', 'comment-dots'],
  ['/campus/tools', '打开校园工具', '日历、食堂、课程与出行入口集中在这里。', 'th-large']
];
const homeSearch = () => m('form.Campus-homeSearch', {
  onsubmit: event => {
    event.preventDefault();
    const q = event.target.elements.q.value.trim();
    if (q) m.route.set('/campus/search?q=' + encodeURIComponent(q));
  }
}, [m('label', [m('span.sr-only', '搜索论坛内容'), symbol('search'),
  m('input', { name: 'q', type: 'search', placeholder: '搜索校园信息、课程经验与资料…', required: true })]),
  m('button', { type: 'submit', 'aria-label': '开始搜索' }, symbol('arrow-right'))]);
const homeQuick = () => m('aside.Campus-homeQuick', [
  m('h2', '常用校园入口'),
  m('p', '从正在发生的事开始，再找到需要的资料与同学经验。'),
  m('nav', { 'aria-label': '常用校园入口' }, [
    ['/campus/aggregation', '学校与学院通知', 'bullhorn'],
    ['/campus/tools', '校历与校园工具', 'calendar-alt'],
    ['/campus/courses', '课程目录与评价', 'graduation-cap'],
    ['/t/campus-questions', '向同学提问', 'comment-dots']
  ].map(([href, title, icon]) => link(href, [symbol(icon), m('span', title), symbol('arrow-right')])))
]);
const campusArtwork = () => m('svg.Campus-homeArtwork', {
  viewBox: '0 0 520 280',
  fill: 'none',
  'aria-hidden': 'true'
}, [
  m('path.Campus-artAxis', { d: 'M260 10V258M22 214H498' }),
  m('path.Campus-artRoof', { d: 'M66 90H454M90 76H430L405 53H115L90 76ZM118 53L148 35H372L402 53' }),
  m('path.Campus-artStructure', { d: 'M79 90V196H441V90M95 110H425M95 184H425M215 184V143C215 118 235 100 260 100C285 100 305 118 305 143V184M225 184V144C225 125 239 111 260 111C281 111 295 125 295 144V184' }),
  ...[110, 145, 180, 340, 375, 410].map(x => m('path.Campus-artColumn', { d: `M${x} 111V184M${x - 6} 111H${x + 6}M${x - 6} 184H${x + 6}` })),
  m('path.Campus-artSteps', { d: 'M60 196H460M44 206H476M29 214H491' }),
  m('path.Campus-artBook', { d: 'M260 241C210 227 162 226 119 236V221C162 211 210 212 260 227C310 212 358 211 401 221V236C358 226 310 227 260 241ZM260 227V241M119 249C164 239 211 241 260 254C309 241 356 239 401 249' })
]);
const homeGuide = () => m('section.Campus-homeGuide', { 'aria-labelledby': 'campus-home-guide-title' }, [
  m('div.Campus-homeGuideHeading', [
    m('h2#campus-home-guide-title', '今天从哪里开始？'),
    m('p', '选一个需要的入口，或继续往下看同学们的最新讨论。')
  ]),
  m('nav.Campus-homePaths', { 'aria-label': '校园常用入口' }, homePaths.map(([href, title, description, icon], index) =>
    link(href, [m('span.Campus-homePathIcon', symbol(icon)), m('span.Campus-homePathText', [m('strong', title), m('small', description)]), m('span.Campus-homePathArrow', { 'aria-hidden': 'true' }, '→')], {
      className: 'Campus-homePath' + (index === 0 ? ' Campus-homePath--lead' : '')
    })
  ))
]);
// Native sorting serializes filters into the URL; keep the campus criteria
// when the original IndexPage changes sort order or loads another page.
const campusFilters = () => {
  let saved = {};
  try {
    saved = JSON.parse(m.route.param('filter')?.campus || '{}');
  } catch (_) {}
  return {
    ...saved,
    ...m.route.param()
  };
};
const currentType = () => (m.route.get().split('?')[0] === '/campus/courses' ? 'review' : campusFilters().type) || {
  'campus-resources': 'resource',
  'campus-questions': 'question',
  'campus-courses': 'review'
}[m.route.param('tags')] || '';
const refreshDiscussion = async id => {
  const discussion = await app.store.find('discussions', String(id));
  m.redraw();
  return discussion;
};
export async function openCampusComposer(params = {}) {
  if (!app.session.user) return app.modal.show(() => import('flarum/forum/components/LogInModal'));
  const [{
    default: DiscussionComposer
  }, catalogResponse, {
    default: ComposerPostPreview
  }] = await Promise.all([import('flarum/forum/components/DiscussionComposer'), api('catalog'), import('flarum/forum/components/ComposerPostPreview')]);
  const catalog = catalogResponse.data;
  const existing = params.id ? (await api('records/' + params.id)).data : null;
  if (existing && !existing.can_edit) throw {
    status: 403
  };
  const type = existing?.type || params.type || 'information';
  if (!catalog.categories[type]) throw {
    status: 404
  };
  const key = {
    resource: 'resources',
    question: 'questions',
    review: 'courses'
  }[type];
  const sections = catalog.sections.filter(s => key ? s.key === key : s.key !== 'governance' || app.session.user.isAdmin());
  if (!sections.length || !Object.keys(catalog.categories[type]).length) {
    app.alerts.show({
      type: 'error'
    }, '当前没有可发布的分区或分类，请检查校园身份与权限。');
    return;
  }
  const initial = existing ? {
    ...existing,
    ...existing.fields,
    status: existing.stored_status,
    related: existing.fields.related.map(x => x.id)
  } : {
    type,
    section_id: sections.find(s => Number(s.id) === Number(params.section_id) || s.key === params.section_key)?.id || sections[0].id,
    category: catalog.categories[type][params.category] ? params.category : Object.keys(catalog.categories[type])[0],
    status: catalog.states[type][0],
    related: [],
    title: '',
    body: '',
    course_id: Number(params.course_id) || '',
    semester: params.semester || '',
    overall: 3,
    difficulty: 3,
    workload: 3
  };
  class CampusComposer extends DiscussionComposer {
    static initAttrs(attrs) {
      super.initAttrs(attrs);
      attrs.className += ' CampusComposer';
    }
    oninit(vnode) {
      super.oninit(vnode);
      this.form = {
        ...initial
      };
      this.title(initial.title);
      this.courseChoices = [];
      this.relatedInput = (initial.related || []).join(',');
      this.jumpToPreview = () => {
        this.preview = !this.preview;
        m.redraw();
      };
      if (type === 'review') this.loadCourses();
    }
    hasChanges() {
      if (!this.form) return false;
      return this.title() !== initial.title || this.composer.fields.content() !== (initial.body || '') || this.relatedInput !== (initial.related || []).join(',') || Object.keys(this.form).some(key => JSON.stringify(this.form[key]) !== JSON.stringify(initial[key]));
    }
    async loadCourses() {
      if (this.courseLoading) return;
      this.courseLoading = true;
      this.courseError = '';
      try {
        this.courseChoices = (await api('courses?' + new URLSearchParams(this.courseQuery ? {
          search: this.courseQuery
        } : this.form.course_id ? {
          id: this.form.course_id
        } : {}))).data;
        if (!this.courseChoices.some(c => Number(c.id) === Number(this.form.course_id))) this.form.course_id = this.courseChoices[0]?.id || '';
        if (!this.form.semester) this.form.semester = this.courseChoices.find(c => Number(c.id) === Number(this.form.course_id))?.semester || '';
      } catch (e) {
        this.courseError = errorMessage(e, '课程加载失败，请手动重试。');
      } finally {
        this.courseLoading = false;
        m.redraw();
      }
    }
    headerItems() {
      const items = super.headerItems();
      items.remove('tags');
      const title = items.get('discussionTitle')?.children?.[0];
      if (title?.attrs) {
        title.attrs['aria-label'] = '标题';
        title.attrs.maxlength = 80;
      }
      const select = (label, value, set, choices, disabled = false) => m('label.Campus-field', [m('span', label), m('select.FormControl', {
        'aria-label': label,
        value,
        disabled,
        required: true,
        onchange: e => set(e.target.value)
      }, choices.map(([value, title]) => m('option', {
        value
      }, title)))]);
      const f = this.form;
      items.add('campus-fields', m('div.Campus.Campus-composerFields', [m('p.Campus-hint', ({
        resource: '注明资料出处与使用授权，帮助他人放心访问。',
        question: '写清问题、已尝试的方法与需要的帮助。',
        review: '分享真实的学习体验；三项评分表达个人感受。'
      }[type] || '注明信息来源，补充时间地点，让读者快速判断是否适合参与。') + ' 日期按北京时间填写。'), this.error ? m('p.Campus-notice.is-error', {
        role: 'alert'
      }, this.error) : null, m('fieldset.Campus-interaction', {
        disabled: this.loading
      }, [m('div.Campus-formGrid', [select('分区', f.section_id, v => f.section_id = Number(v), sections.map(s => [s.id, s.name])), select('分类', f.category, v => f.category = v, Object.entries(catalog.categories[type])), select('状态', f.status, v => f.status = v, catalog.states[type].map(s => [s, labels[s]]), type === 'question')]), type === 'review' ? [m('div.Campus-formGrid', [field('course_search', '查找课程', this.courseQuery, v => this.courseQuery = v), m(Button, {
        loading: this.courseLoading,
        onclick: () => this.loadCourses()
      }, '搜索可选课程'), select('关联课程', f.course_id, v => {
        f.course_id = Number(v);
        f.semester = this.courseChoices.find(c => Number(c.id) === f.course_id)?.semester || f.semester;
      }, [['', '选择课程'], ...this.courseChoices.map(c => [c.id, c.name + ' · ' + c.semester])])]), this.courseError ? m('p', {
        role: 'alert'
      }, this.courseError) : null, field('semester', '评价学期', f.semester, v => f.semester = v, {
        required: true,
        minlength: 2,
        maxlength: 80
      }), m('div.Campus-formGrid', ['overall', 'difficulty', 'workload'].map(k => select(labels[k], f[k], v => f[k] = Number(v), [1, 2, 3, 4, 5].map(v => [v, String(v)]))))] : null, ...['source', 'source_url', 'license'].filter(k => catalog.fields[type].includes(k)).map(k => field(k, labels[k], f[k], v => f[k] = v, {
        required: k === 'source' || type === 'resource',
        type: k.endsWith('_url') ? 'url' : 'text',
        maxlength: 2000,
        textarea: k === 'license'
      })), m('details.Campus-filters', [m('summary', '补充详细信息与关联（可选）'), ...catalog.fields[type].filter(k => !['source', 'source_url', 'license'].includes(k)).map(k => field(k, labels[k] || k, f[k], v => f[k] = v, {
        type: k.endsWith('_url') ? 'url' : ['published_at', 'starts_at', 'ends_at', 'deadline', 'updated_at', 'last_verified'].includes(k) ? 'datetime-local' : 'text',
        utc: ['published_at', 'starts_at', 'ends_at', 'deadline', 'updated_at', 'last_verified'].includes(k),
        textarea: ['conditions', 'background', 'experience', 'study', 'advice', 'gains'].includes(k),
        maxlength: k === 'tags' ? 200 : 2000
      })), field('related', '关联内容编号（逗号分隔，最多十条）', this.relatedInput, v => this.relatedInput = v)]), existing ? field('reason', '修改理由', f.reason, v => f.reason = v, {
        required: true,
        minlength: 5,
        maxlength: 500
      }) : null])]), -20);
      if (this.preview) items.add('campus-preview', m('section.Campus.Campus-panel', [m('h2', this.title() || '正文预览'), m(ComposerPostPreview, {
        composer: this.composer,
        className: 'Post-body Campus-nativePreview'
      })]), -30);
      return items;
    }
    async onsubmit() {
      if (this.loading) return;
      const invalid = [...this.element.querySelectorAll('.Campus-composerFields input,.Campus-composerFields textarea,.Campus-composerFields select')].find(e => !e.checkValidity());
      if (invalid) {
        invalid.closest('details')?.setAttribute('open', '');
        invalid.reportValidity();
        return;
      }
      this.loading = true;
      this.error = '';
      m.redraw();
      try {
        const related = (this.relatedInput || '').split(',').filter(x => x.trim()).map(x => Number(x.trim()));
        if (related.some(x => !Number.isInteger(x) || x < 1) || related.length > 10) throw {
          response: {
            errors: [{
              detail: '关联内容最多十条，请填写有效编号。'
            }]
          }
        };
        const result = (await api(existing ? 'records/' + existing.id : 'records', existing ? 'PATCH' : 'POST', {
          ...this.form,
          title: this.title(),
          body: this.composer.fields.content(),
          related
        })).data;
        this.composer.hide();
        app.discussions.clear();
        // The write has committed. A failed follow-up read must not offer
        // another submit and accidentally create a duplicate discussion.
        try {
          const discussion = await refreshDiscussion(result.discussion_id);
          m.route.set(app.route.discussion(discussion));
        } catch (_) {
          m.route.set('/d/' + result.discussion_id);
        }
      } catch (e) {
        this.error = errorMessage(e, '保存失败，输入已保留，请稍后重试。');
      } finally {
        this.loaded();
      }
    }
  }
  await app.composer.load(CampusComposer, {
    user: app.session.user,
    originalContent: initial.body || '',
    submitLabel: existing ? '保存修改' : '发布内容',
    titlePlaceholder: {
      resource: '资料名称：让读者一眼知道是什么',
      question: '用一句话说明你的问题',
      review: '课程名称与最想分享的体验'
    }[type] || '清楚的标题，让信息更容易被发现',
    placeholder: '详细内容',
    confirmExit: '尚有未保存的内容，确认放弃吗？'
  });
  if (app.composer.body.componentClass === CampusComposer) {
    await app.composer.show();
    await app.composer.editorReady();
    const input = document.querySelector('#composer .TextEditor-editorContainer textarea');
    if (input) {
      input.setAttribute('aria-label', '详细内容');
      input.maxLength = 30000;
    }
    app.composer.height = Math.min(720, app.composer.maximumHeight());
    m.redraw();
  }
}
export class NativeRecordsPage extends IndexPage {
  oninit(v) {
    super.oninit(v);
    this.filters = campusFilters();
    delete this.filters.filter;
    this.loadCatalog();
  }
  async loadCatalog(retry = false) {
    this.catalogLoading = true;
    this.catalogError = '';
    try {
      const cached = app.cache.campusCatalog;
      const response = !retry && cached?.data ? {
        data: cached.data
      } : !retry && cached?.promise ? await cached.promise : await api('catalog');
      if (!response?.data) throw new Error('Catalog unavailable');
      this.catalog = response.data;
      app.cache.campusCatalog = {
        data: this.catalog
      };
    } catch (e) {
      this.catalogError = errorMessage(e, '筛选选项加载失败，请重试。');
    } finally {
      this.catalogLoading = false;
      m.redraw();
    }
  }
  setTitle() {
    app.setTitle(m.route.get().startsWith('/campus/search') ? '详细检索' : labels[currentType()] || '校园内容');
  }
  hero() {
    const search = m.route.get().startsWith('/campus/search'),
      type = currentType();
    const base = {
      resource: boards.resources,
      question: boards.questions,
      review: boards.courses
    }[type];
    const board = search ? {
      icon: 'search',
      title: '详细检索',
      kind: 'search',
      subtitle: '从校园讨论中找到答案，按内容类型与时间进一步筛选。'
    } : {
      ...base,
      icon: base?.icon || 'bullhorn',
      title: labels[type] || '校园内容',
      kind: base?.kind || 'calendar',
      tabs: undefined,
      subtitle: base?.subtitle || '汇集校园资讯、活动与机会，快速找到与你有关的信息。',
      action: type ? '发布' + labels[type] : undefined
    };
    return m(BoardHero, {
      board,
      publish: () => openCampusComposer({
        type
      }).catch(e => app.alerts.show({
        type: 'error'
      }, errorMessage(e)))
    });
  }
  contentItems() {
    const items = super.contentItems(),
      search = m.route.get().startsWith('/campus/search'),
      type = currentType();
    const selectedType = this.filters.type || type;
    const select = (label, name, options) => m('label.Campus-field', [m('span.Campus-fieldLabel', label), m('select.FormControl', {
      'aria-label': label,
      name,
      disabled: name !== 'type' && !this.catalog,
      value: this.filters[name] || '',
      onchange: e => {
        this.filters[name] = e.target.value;
        if (name === 'type') {
          for (const key of ['category', 'status', 'course_id', 'semester']) delete this.filters[key];
        }
      }
    }, [m('option', {
      value: ''
    }, name !== 'type' && this.catalogLoading ? '正在加载…' : '全部' + label), ...options.map(([value, title]) => m('option', {
      value
    }, title))])]);
    const dates = ['from', 'to', 'deadline_from', 'deadline_to', 'starts_from', 'starts_to'];
    const advancedCount = ['course_id', 'semester', ...dates].filter(key => this.filters[key]).length;
    items.add('campus-filters', m('section.Campus.Campus-nativeFilters', {
      'aria-label': '筛选校园内容'
    }, [this.catalogError ? m('p.Campus-notice.is-error', {
      role: 'alert'
    }, [this.catalogError, m(Button, {
      onclick: () => this.loadCatalog(true)
    }, '重新加载')]) : null, m('form.Campus-searchForm', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set(search ? '/campus/search' : '/campus/records', {
          ...this.filters,
          page: 1
        });
      }
    }, [m('div.Campus-searchBar', [m('label.Campus-searchInput', [m('span.sr-only', '搜索标题、正文与标签'), symbol('search'), m('input.FormControl', {
      type: 'search',
      name: 'q',
      placeholder: '搜索标题、正文与标签',
      value: this.filters.q || this.filters.search || '',
      oninput: e => {
        this.filters.q = e.target.value;
        delete this.filters.search;
      }
    })]), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '查询')]), m('div.Campus-searchOptions', {
      className: search ? 'Campus-searchOptions--global' : ''
    }, [search ? select('内容类型', 'type', Object.entries({
      discussion: '普通讨论',
      information: '校园信息',
      resource: '共享资料',
      question: '校园问答',
      review: '课程评价'
    })) : null, select('分类', 'category', Object.entries(this.catalog?.categories[selectedType] || {})), select('状态', 'status', (this.catalog?.states[selectedType] || []).map(s => [s, labels[s]]))]), m('div.Campus-searchRefinements', [m('details.Campus-searchAdvanced', [m('summary', [symbol('sliders-h'), '更多筛选', advancedCount ? m('span.Campus-badge', advancedCount) : null]), m('div.Campus-searchAdvancedFields', [field('course_id', '关联课程编号', this.filters.course_id, v => this.filters.course_id = v, {
      type: 'number',
      min: 1
    }), field('semester', '评价学期', this.filters.semester, v => this.filters.semester = v), ...dates.map(k => field(k, {
      from: '发布起始日期',
      to: '发布结束日期',
      deadline_from: '截止起始日期',
      deadline_to: '截止结束日期',
      starts_from: '活动起始日期',
      starts_to: '活动结束日期'
    }[k], this.filters[k], v => this.filters[k] = v, {
      type: 'date'
    }))]), m('small.Campus-hint', '日期范围按 UTC 自然日筛选。'), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '应用更多筛选')]), link(search ? '/campus/search' : '/campus/records?type=' + type, '清空筛选', {
      className: 'Button Button--link Campus-searchReset'
    })])])]), 110);
    return items;
  }
}
export class RecordRedirect extends DataPage {
  async fetch() {
    this.data = (await api('records/' + this.params.id)).data;
  }
  content() {
    return [pageHeading(this.data.title), m(LoadingIndicator), link('/d/' + this.data.discussion_id, '打开原生讨论')];
  }
  oncreate(v) {
    super.oncreate(v);
    this.redirect();
  }
  onupdate() {
    this.redirect();
  }
  redirect() {
    if (this.data && !this.error && !this.loading && !this.redirected) {
      this.redirected = true;
      m.route.set('/d/' + this.data.discussion_id, {}, {
        replace: true
      });
    }
  }
}
export class ComposerEntry extends DataPage {
  async fetch() {
    this.catalog = (await api('catalog')).data;
  }
  content() {
    return app.session.user ? [pageHeading(this.params.id ? '编辑校园内容' : '发布校园内容', '分享校园信息与学习经验，发布前可预览正文。'), m(Button, {
      className: 'Button Button--primary',
      onclick: () => this.open()
    }, this.params.id ? '编辑内容或状态' : '打开发布编辑器')] : loginPrompt('登录后发布内容');
  }
  async open() {
    if (this.opening) return;
    this.opened = true;
    this.opening = true;
    try {
      await openCampusComposer(this.params);
    } catch (e) {
      this.notice = errorMessage(e);
      this.noticeError = true;
      m.redraw();
    } finally {
      this.opening = false;
    }
  }
  oncreate(v) {
    super.oncreate(v);
    this.maybeOpen();
  }
  onupdate() {
    this.maybeOpen();
  }
  maybeOpen() {
    if (!this.loading && !this.error && app.session.user && !this.opened) this.open();
  }
}
class RecordActionModal extends Modal {
  className() {
    return 'CampusRecordActionModal Modal--large';
  }
  title() {
    return this.attrs.title;
  }
  content() {
    const d = this.attrs.record;
    return m('div.Modal-body.Campus', [this.error ? m('p.Campus-notice.is-error', {
      role: 'alert'
    }, this.error) : null, this.attrs.kind === 'history' ? [this.history ? this.history.map(h => m('p', [`版本 ${h.version} · ${formatTime(h.created_at)} · ${h.reason}`, d.can_edit ? m(Button, {
      onclick: () => this.action(async () => {
        this.revision = (await api('records/' + d.id + '/revisions/' + h.version)).data;
      }, false)
    }, '查看历史版本 ' + h.version) : null])) : m(Button, {
      onclick: () => this.action(async () => {
        this.history = (await api('records/' + d.id)).data.history;
      }, false)
    }, '加载修改记录'), this.revision ? m('section.Campus-panel', [m('h3', this.revision.title), m('p.Campus-text', this.revision.body)]) : null] : m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.action(() => api('records/' + d.id + (this.attrs.kind === 'convert' ? '/convert' : '/feedback'), 'POST', this.attrs.kind === 'convert' ? {
          post_id: this.attrs.post.id(),
          title: this.name,
          license: this.license,
          section_id: this.attrs.section_id,
          category: 'faq'
        } : {
          kind: this.kind || 'correction',
          message: this.message
        }), true);
      }
    }, [this.attrs.kind === 'convert' ? [field('title', '资料名称', this.name, v => this.name = v, {
      required: true,
      minlength: 3,
      maxlength: 80
    }), field('license', '版权或使用授权说明', this.license, v => this.license = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 2000
    })] : [m('label.Campus-field', [m('span', '反馈类型'), m('select.FormControl', {
      value: this.kind || 'correction',
      onchange: e => this.kind = e.target.value
    }, [['correction', '内容纠错'], ['invalid_link', '链接失效'], ['source', '来源问题'], ['report', '违规举报']].map(([value, title]) => m('option', {
      value
    }, title)))]), field('message', '具体说明（仅本人和获授权人员可见）', this.message, v => this.message = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 2000
    })], m(Button, {
      type: 'submit',
      className: 'Button Button--primary',
      loading: this.loading
    }, this.attrs.kind === 'convert' ? '创建关联资料' : '提交反馈')])]);
  }
  async action(work, close) {
    if (this.loading) return;
    this.loading = true;
    this.error = '';
    try {
      const result = await work();
      if (close) {
        app.modal.close();
        app.alerts.show({
          type: 'success'
        }, '已提交并保存');
        this.attrs.onSaved?.();
        if (result?.data?.discussion_id) m.route.set('/d/' + result.data.discussion_id);
      }
    } catch (e) {
      this.error = errorMessage(e);
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
class RecordDetails extends Component {
  oninit(v) {
    super.oninit(v);
    this.load();
  }
  async load() {
    this.error = '';
    this.refreshPending = true;
    try {
      this.data = (await api('records/' + this.attrs.record.id)).data;
    } catch (e) {
      this.error = errorMessage(e);
    }
    this.refreshPending = false;
    m.redraw();
  }
  onbeforeupdate(vnode) {
    super.onbeforeupdate(vnode);
    if (this.data && this.data.version !== this.attrs.record.version && !this.refreshPending) this.load();
    return true;
  }
  async act(path, body) {
    if (this.busy) return;
    this.busy = true;
    this.error = '';
    try {
      await api('records/' + this.data.id + '/' + path, 'POST', body);
      await this.load();
      await refreshDiscussion(this.data.discussion_id);
    } catch (e) {
      this.error = errorMessage(e);
    } finally {
      this.busy = false;
      m.redraw();
    }
  }
  view() {
    const d = this.data || this.attrs.record;
    const key = boardKey(this.attrs.discussion, d);
    if (key === 'chat' && !app.session.user && !this.error) return null;
    return m('section.Campus.Campus-nativeRecord', {
      className: 'Campus-nativeRecord--' + (boards[key]?.kind || d.type),
      'aria-label': key === 'chat' ? '讨论收藏与反馈' : '内容详情'
    }, [this.error ? m('p', {
      role: 'alert'
    }, [this.error, m(Button, {
      onclick: () => this.load()
    }, '重新加载')]) : null, recordOverview({
      ...this.attrs.record,
      ...d
    }, this.attrs.discussion), app.session.user ? m('div.Campus-actions', [this.data ? m(Button, {
      disabled: this.busy,
      onclick: () => this.act('bookmark', {
        enabled: !d.bookmarked,
        following: d.following
      })
    }, d.bookmarked ? '取消收藏' : '收藏内容') : null, d.can_edit ? m(Button, {
      onclick: () => openCampusComposer({
        id: d.id
      }).catch(e => app.alerts.show({
        type: 'error'
      }, errorMessage(e)))
    }, '编辑内容或状态') : null, d.type === 'review' ? m(Button, {
      disabled: this.busy || String(d.author_id) === app.session.user.id() || d.status !== 'active',
      onclick: () => this.act('useful', {
        enabled: !d.voted
      })
    }, (d.voted ? '取消有用反馈' : '这条评价有帮助') + ' · ' + (d.useful || 0)) : null, m(Button, {
      onclick: () => app.modal.show(RecordActionModal, {
        record: d,
        kind: 'feedback',
        onSaved: () => this.load(),
        title: '纠错与反馈'
      })
    }, '纠错与反馈'), m(Button, {
      onclick: () => app.modal.show(RecordActionModal, {
        record: d,
        kind: 'history',
        title: '修改记录'
      })
    }, '修改记录')]) : null, this.data?.feedback?.length ? m('details.Campus-filters', [m('summary', d.can_moderate ? '内容反馈与处理' : '我的反馈与处理结果'), this.data.feedback.map(f => m('section.Campus-panel', [m('p.Campus-text', f.message + ' · ' + (f.resolution || '等待处理')), d.can_moderate && f.status === 'pending' ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.act('feedback/resolve', {
          feedback_id: f.id,
          status: this.feedbackStatus?.[f.id] || 'resolved',
          reason: this.feedbackReasons?.[f.id] || ''
        });
      }
    }, [m('label.Campus-field', [m('span', '处理结果'), m('select.FormControl', {
      value: this.feedbackStatus?.[f.id] || 'resolved',
      onchange: e => {
        this.feedbackStatus ||= {};
        this.feedbackStatus[f.id] = e.target.value;
      }
    }, [m('option', {
      value: 'resolved'
    }, '已处理'), m('option', {
      value: 'rejected'
    }, '不采纳')])]), field('resolution-' + f.id, '处理说明', this.feedbackReasons?.[f.id], value => {
      this.feedbackReasons ||= {};
      this.feedbackReasons[f.id] = value;
    }, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit',
      disabled: this.busy
    }, '保存反馈处理')]) : null]))]) : null]);
  }
}
export class NativeCollectionsPage extends UserPage {
  oninit(v) {
    super.oninit(v);
    this.user = app.session.user;
    this.load();
  }
  hero() {
    return this.user ? super.hero() : null;
  }
  async load() {
    if (!app.session.user) {
      this.loading = false;
      return;
    }
    this.error = '';
    this.loading = true;
    try {
      this.data = (await api('me?page=' + (m.route.param('page') || 1))).data;
    } catch (e) {
      this.error = errorMessage(e);
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
  content() {
    return m('div.Campus', this.loading ? m(LoadingIndicator) : this.error ? m('p', {
      role: 'alert'
    }, [this.error, m(Button, {
      onclick: () => this.load()
    }, '重新加载')]) : Collections.prototype.content.call(this));
  }
  view() {
    return this.user ? super.view() : shell(this, loginPrompt('登录后查看个人记录'));
  }
}
export function registerNative() {
  if (app.translator.getLocale().startsWith('zh') && window.dayjs) {
    window.dayjs.locale({
      name: 'zh-campus',
      weekdays: '星期日_星期一_星期二_星期三_星期四_星期五_星期六'.split('_'),
      weekdaysShort: '周日_周一_周二_周三_周四_周五_周六'.split('_'),
      weekdaysMin: '日_一_二_三_四_五_六'.split('_'),
      months: Array.from({
        length: 12
      }, (_, i) => i + 1 + '月'),
      monthsShort: Array.from({
        length: 12
      }, (_, i) => i + 1 + '月'),
      weekStart: 1,
      formats: {
        LT: 'HH:mm',
        LTS: 'HH:mm:ss',
        L: 'YYYY/MM/DD',
        LL: 'YYYY年M月D日',
        LLL: 'YYYY年M月D日 HH:mm',
        LLLL: 'YYYY年M月D日 dddd HH:mm'
      },
      relativeTime: {
        future: '%s后',
        past: value => value === '刚刚' ? value : value + '前',
        s: '刚刚',
        m: '1 分钟',
        mm: '%d 分钟',
        h: '1 小时',
        hh: '%d 小时',
        d: '1 天',
        dd: '%d 天',
        M: '1 个月',
        MM: '%d 个月',
        y: '1 年',
        yy: '%d 年'
      }
    });
  }
  extend(HeaderSecondary.prototype, 'items', items => items.add('campus-publish', m(Button, {
    className: 'Button Button--primary Campus-headerPublish',
    icon: 'fas fa-plus',
    onclick: () => openCampusComposer(routeBoard()?.type ? boardParams(routeBoard()) : {
      type: currentType() || 'information'
    }).catch(e => app.alerts.show({
      type: 'error'
    }, errorMessage(e)))
  }, '发布'), 5));
  extend(IndexSidebar.prototype, 'items', items => {
    const board = routeBoard(),
      button = items.get('newDiscussion'),
      type = currentType(),
      action = board?.type ? board.action : labels[type] ? '发布' + labels[type] : null;
    if (action && button) {
      button.children = [action];
      button.attrs['aria-label'] = action;
      button.attrs.icon = 'fas fa-plus';
    }
  });
  extend(DiscussionListState.prototype, 'requestParams', params => {
    params.include ||= [];
    if (!params.include.includes('firstPost')) params.include.push('firstPost');
  });
  extend(WelcomeHero.prototype, 'contentItems', items => {
    if (app.forum.attribute('welcomeTitle') === 'Welcome to Flarum') {
      items.setContent('title', m('h1.Hero-title', app.forum.attribute('title')));
      items.setContent('subtitle', m('div.Hero-subtitle', app.forum.attribute('description') || '共同维护校园信息，让经验与知识帮助更多同学。'));
    }
    if (isCampusHome()) items.add('campus-home-identity', m('p.Campus-homeIdentity', [
      '中国社会科学院大学同学共建', m('span', { 'aria-hidden': 'true' }, '·'), '独立社区，非学校官方平台'
    ]), -500);
    if (isCampusHome()) items.add('campus-home-actions', m('nav.Campus-homeHeroActions', { 'aria-label': '首页快捷入口' }, [
      link('/campus/aggregation', [symbol('bullhorn'), '看校园信息'], { className: 'Campus-homeHeroAction Campus-homeHeroAction--primary' }),
      link('/resources', [symbol('book-open'), '找学习资料'], { className: 'Campus-homeHeroAction' })
    ]), -1000);
    if (isCampusHome()) items.add('campus-home-search', homeSearch(), -800);
    if (isCampusHome()) items.add('campus-home-quick', homeQuick(), -1200);
    if (isCampusHome()) items.add('campus-home-artwork', campusArtwork(), -1100);
  });
  override(GlobalSearchState.prototype, 'params', function (original) {
    const params = original();
    if (/^\/campus\/(records|search)/.test(m.route.get())) {
      const route = campusFilters(),
        fields = ['type', 'category', 'status', 'course_id', 'semester', 'section_id', 'tag', 'course', 'topic', 'grade', 'tags', 'from', 'to', 'deadline_from', 'deadline_to', 'starts_from', 'starts_to'];
      const campus = {};
      for (const field of fields) if (route[field]) campus[field] = route[field];
      if (route.q || route.search) campus.q = route.q || route.search;
      params.filter = {
        ...params.filter,
        campus: JSON.stringify(campus)
      };
      delete params.q;
      if (!['latest', 'newest', 'oldest', 'top', 'az', 'za'].includes(params.sort)) delete params.sort;
    }
    return params;
  });
  extend(HeaderPrimary.prototype, 'items', items => primary.forEach(([href, title, icon], i) => items.add('campus-' + i, m(LinkButton, {
    href,
    icon: 'fas fa-' + icon,
    className: 'Button Button--link',
    active: i === 0 ? ['/', '/campus'].includes((m.route.get() || '').split('?')[0]) : (m.route.get() || '').split('?')[0] === href.split('?')[0],
    'aria-label': title
  }, title), 100 - i)));
  extend(IndexSidebar.prototype, 'navItems', items => extras.forEach(([href, title, icon], i) => items.add('campus-extra-' + i, m(LinkButton, {
    href,
    icon: 'fas fa-' + icon,
    active: (m.route.get() || '').split('?')[0] === href
  }, title), i < 2 ? -20 - i : -21 - i)));
  extend(SessionDropdown.prototype, 'items', items => {
    if (app.session.user) items.add('campus-collections', m(LinkButton, {
      href: '/campus/me',
      icon: 'far fa-bookmark'
    }, '我的收藏与校园记录'), 40);
  });
  extend(UserPage.prototype, 'navItems', function (items) {
    if (this.user === app.session.user) items.add('campus-collections', m(LinkButton, {
      href: '/campus/me',
      icon: 'far fa-bookmark'
    }, '收藏与校园记录'), 15);
  });
  override(IndexSidebar.prototype, 'newDiscussionAction', function (original) {
    const board = routeBoard();
    if (board?.type) return openCampusComposer(boardParams(board));
    const type = currentType();
    return labels[type] ? openCampusComposer({
      type
    }) : original();
  });
  extend(IndexPage.prototype, 'oninit', function () {
    if (!app.cache.campusCatalog) {
      const pending = app.cache.campusCatalog = {
        loading: true
      };
      pending.promise = api('catalog').then(r => {
        app.cache.campusCatalog = {
          data: r.data
        };
        m.redraw();
        return r;
      }).catch(() => {
        app.cache.campusCatalog = {
          error: true
        };
        m.redraw();
        return null;
      });
    }
  });
  extend(IndexPage.prototype, 'contentItems', function (items) {
    if (this instanceof NativeRecordsPage || app.search.state.params().q || m.route.param('tags')) return;
    if (isCampusHome()) items.add('campus-home-guide', homeGuide(), 130);
    const cache = app.cache.campusCatalog;
    if (cache?.data) items.add('campus-sections', m('nav.Campus.Campus-nativeExplore', {
      'aria-label': '探索校园板块'
    }, [m('div.Campus-sectionHeading', [m('h2', '按主题浏览'), link('/tags', '全部板块')]), m('div.Campus-grid', cache.data.sections.map(s => link('/t/' + s.slug, [m('span.Campus-sectionIcon', symbol(icons[s.key] || 'folder-open')), m('strong', s.name)], {
      className: 'Campus-section',
      title: s.description
    })))]), 120);
  });
  extend(DiscussionListItem.prototype, 'infoItems', function (items) {
    const post = this.attrs.discussion.firstPost();
    if (!items.has('excerpt') && post?.contentType() === 'comment') {
      const excerpt = (post.contentPlain() || '').replace(/\s+/g, ' ').trim();
      if (excerpt) items.add('excerpt', m('span.Campus-feedExcerpt', excerpt.slice(0, 200)), -100);
    }
    const r = this.attrs.discussion.attribute('campusRecord');
    if (r?.id) items.add('campus-meta', m('span.Campus-listMetadata', [labels[r.type], ' · ', labels[r.status] || r.status]), 5);
  });
  extend(DiscussionPage.prototype, 'view', function (vnode) {
    const r = this.discussion?.attribute('campusRecord');
    if (!r?.id || this.loading) return;
    const stream = vnode.children?.find(child => child?.attrs?.className === 'DiscussionPage-stream');
    if (stream) stream.children.unshift(m(RecordDetails, {
      record: r,
      discussion: this.discussion,
      key: 'campus-record-' + r.id
    }));
  });
  import('flarum/forum/components/CommentPost').then(({
    default: CommentPost
  }) => {
    extend(CommentPost.prototype, 'oninit', function () {
      this.subtree.check(() => this.attrs.post.discussion()?.freshness, () => this.campusBusy);
    });
    extend(CommentPost.prototype, 'footerItems', function (items) {
      const post = this.attrs.post,
        d = post.discussion(),
        r = d?.attribute('campusRecord');
      if (!r?.id || r.type !== 'question' || post.number() === 1) return;
      if (String(r.accepted_post_id) === post.id()) items.add('campus-accepted', m('strong.Campus-accepted', '已采纳答案'), 30);
      if (app.session.user?.id() === String(r.author_id)) items.add('campus-accept', m(Button, {
        disabled: this.campusBusy,
        onclick: async () => {
          if (this.campusBusy) return;
          this.campusBusy = true;
          try {
            await api('records/' + r.id + '/accept', 'POST', {
              post_id: String(r.accepted_post_id) === post.id() ? null : Number(post.id())
            });
            await refreshDiscussion(d.id());
          } catch (e) {
            app.alerts.show({
              type: 'error'
            }, errorMessage(e));
          } finally {
            this.campusBusy = false;
            m.redraw();
          }
        }
      }, String(r.accepted_post_id) === post.id() ? '取消采纳' : '采纳此答案'), 20);
      if (r.can_moderate) items.add('campus-convert', m(Button, {
        onclick: async () => {
          const c = (await api('catalog')).data.sections.find(s => s.key === 'resources');
          app.modal.show(RecordActionModal, {
            record: r,
            post,
            section_id: c?.id,
            kind: 'convert',
            title: '整理答案为公共资料'
          });
        }
      }, '整理为资料'), 10);
    });
  });
  registerBoards(openCampusComposer);
}

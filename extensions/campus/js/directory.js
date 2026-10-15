import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from './button';
import { DataPage, field, pageHeading, emptyState, symbol, formatTime, errorMessage, link } from './ui';
import { pagination } from './navigation';
const request = (path, method = 'GET', body) => app.request({
  method,
  url: app.forum.attribute('apiUrl') + '/ucasser/' + path,
  body
});
const pathOf = (categories, item) => {
  const path = [],
    seen = new Set();
  while (item && !seen.has(item.id)) {
    path.unshift(item);
    seen.add(item.id);
    item = categories.find(c => c.id === item.parent_id);
  }
  return path;
};
const kindNames = {
  college: '学院',
  course: '课程'
};
class DirectoryForm extends Modal {
  className() {
    return 'CampusRecordActionModal Campus-directoryModal';
  }
  title() {
    return this.attrs.mode === 'report' ? '反馈资料问题' : this.attrs.mode === 'category' ? this.attrs.item ? '修改分类名称' : '新增' + kindNames[this.attrs.kind] : this.attrs.item ? '修改资料链接' : '新增资料链接';
  }
  oninit(v) {
    super.oninit(v);
    this.form = {
      ...(this.attrs.item || {
        category_id: this.attrs.category || '',
        status: 'active'
      }),
      reason: ''
    };
    this.error = '';
    this.busy = false;
  }
  async submit(e) {
    e.preventDefault();
    if (this.busy) return;
    this.busy = true;
    this.error = '';
    try {
      const mode = this.attrs.mode;
      const id = this.attrs.item?.id;
      const body = mode === 'report' ? {
        resource_id: id,
        reason: this.form.reason
      } : mode === 'category' ? {
        name: this.form.name,
        parent_id: this.attrs.parent,
        kind: this.attrs.kind
      } : this.form;
      await request(mode === 'report' ? 'reports' : (mode === 'category' ? 'categories' : 'resources') + (id ? '/' + id : ''), mode !== 'report' && id ? 'PATCH' : 'POST', body);
      this.hide();
      this.attrs.onSaved();
    } catch (error) {
      this.error = errorMessage(error, '保存失败，输入内容已保留。');
    } finally {
      this.busy = false;
      m.redraw();
    }
  }
  content() {
    const mode = this.attrs.mode,
      item = this.attrs.item,
      f = this.form;
    return m('form.Campus', {
      onsubmit: e => this.submit(e)
    }, [this.error ? m('p.Campus-notice.is-error', {
      role: 'alert'
    }, this.error) : null, m('fieldset.Campus-interaction', {
      disabled: this.busy,
      'aria-busy': this.busy
    }, [mode === 'report' ? [m('p.Campus-hint', item.title), field('reason', '问题说明', f.reason, v => f.reason = v, {
      textarea: true,
      required: true,
      maxlength: 255,
      placeholder: '例如：访问链接失效，或来源与说明不一致。'
    })] : mode === 'category' ? field('name', '分类名称', f.name, v => f.name = v, {
      required: true,
      maxlength: 120
    }) : [m('label.Campus-field', ['所属课程或校外类别', m('select.FormControl', {
      value: f.category_id,
      required: true,
      onchange: e => f.category_id = e.target.value
    }, [m('option', {
      value: ''
    }, '请选择'), this.attrs.categories.filter(c => ['course', 'external_topic'].includes(c.kind)).map(c => m('option', {
      value: c.id
    }, pathOf(this.attrs.categories, c).map(x => x.name).join(' / ')))])]), field('title', '资料名称', f.title, v => f.title = v, {
      required: true,
      maxlength: 180
    }), field('url', '访问链接', f.url, v => f.url = v, {
      type: 'url',
      required: true,
      maxlength: 2048
    }), field('source_url', '原始来源链接', f.source_url, v => f.source_url = v, {
      type: 'url',
      maxlength: 2048,
      hint: '优先填写作者、出版方或官方发布入口，方便后来核验。'
    }), field('description', '资料说明', f.description, v => f.description = v, {
      textarea: true,
      maxlength: 2000
    }), m('label.Campus-field', ['链接状态', m('select.FormControl', {
      value: f.status,
      onchange: e => f.status = e.target.value
    }, [m('option', {
      value: 'active'
    }, '有效'), m('option', {
      value: 'needs_review'
    }, '待核验')])]), item ? field('reason', '修订原因', f.reason, v => f.reason = v, {
      required: true,
      maxlength: 255
    }) : null], m('div.Campus-actions', [m(Button, {
      type: 'submit',
      className: 'Button Button--primary',
      loading: this.busy
    }, mode === 'report' ? '提交反馈' : '保存'), m(Button, {
      onclick: () => this.hide()
    }, '取消')])])]);
  }
}
class DirectoryHistory extends Modal {
  className() {
    return 'CampusRecordActionModal';
  }
  title() {
    return '资料修订记录';
  }
  oninit(v) {
    super.oninit(v);
    this.load();
  }
  async load() {
    this.loading = true;
    this.error = '';
    try {
      this.rows = (await request('resources/' + this.attrs.item.id + '/revisions')).revisions;
    } catch (error) {
      this.error = errorMessage(error, '修订记录加载失败。');
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
  content() {
    const display = (key, value) => key === 'status' ? {
      active: '有效',
      needs_review: '待核验'
    }[value] || value : key === 'category_id' ? this.attrs.categories?.find(c => c.id === Number(value))?.name || value : value;
    return m('div.Campus', this.loading ? m(LoadingIndicator) : this.error ? [m('p', {
      role: 'alert'
    }, this.error), m(Button, {
      onclick: () => this.load()
    }, '重新加载')] : [m('p.Campus-hint', this.attrs.item.title), this.rows.length ? this.rows.map(row => m('section.Campus-directoryRevision', {
      key: row.id
    }, [m('h3', row.reason || '首次收录'), m('p.Campus-hint', row.editor + ' · ' + formatTime(row.created_at)), Object.keys(row.changes).map(key => m('div.Campus-revisionChange', [m('strong', {
      title: key
    }, {
      title: '资料名称',
      url: '访问链接',
      source_url: '原始来源',
      description: '资料说明',
      status: '状态',
      category_id: '所属分类'
    }[key] || key), m('del', display(key, row.changes[key].old) ?? '未填写'), m('span', symbol('arrow-right')), m('ins', display(key, row.changes[key].new) ?? '未填写')]))])) : emptyState('暂无修订记录', '保存资料的更新时，系统会记录修订原因与前后变化。')]);
  }
}
export class DirectoryPage extends DataPage {
  async fetch() {
    const [categories, resources] = await Promise.all([request('categories'), request('resources?' + new URLSearchParams(this.params))]);
    this.data = {
      ...resources,
      categories: categories.categories
    };
    this.query = this.params.q || '';
  }
  pageTitle() {
    return '资料分类目录';
  }
  filter(category, q = this.query, page = 1) {
    return '/resources?' + new URLSearchParams({
      ...(category ? {
        category_id: category
      } : {}),
      ...(q ? {
        q
      } : {}),
      ...(page > 1 ? {
        page
      } : {})
    });
  }
  open(mode, attrs = {}) {
    app.modal.show(DirectoryForm, {
      mode,
      categories: this.data.categories,
      category: this.params.category_id,
      ...attrs,
      onSaved: () => {
        this.notice = mode === 'report' ? '反馈已提交，等待维护者核对。' : '已保存，修订记录同步更新。';
        this.load();
      }
    });
  }
  async loadReports() {
    await this.perform(async () => {
      this.reports = (await request('reports')).reports;
      this.showReports = true;
    }, {
      refresh: false,
      message: '已加载待处理反馈。'
    });
  }
  categoryNode(category, depth = 0) {
    const children = this.data.categories.filter(c => c.parent_id === category.id).sort((a, b) => a.position - b.position || a.id - b.id);
    const canCollege = category.kind === 'root' && category.parent_id === null && !children.some(c => c.kind === 'external_topic');
    return m('li', {
      key: category.id
    }, [m('div.Campus-directoryNode', {
      className: Number(this.params.category_id) === category.id ? 'is-active' : ''
    }, [m(Link, {
      href: this.filter(category.id),
      className: 'Campus-directoryCategory'
    }, [symbol(depth === 0 ? 'folder-open' : category.kind === 'course' ? 'book' : 'folder'), m('span', category.name)]), this.data.can_manage ? m('span.Campus-directoryNodeActions', [canCollege || category.kind === 'course_group' ? m(Button, {
      className: 'Button Button--icon',
      icon: 'fas fa-plus',
      'aria-label': (canCollege ? '新增学院' : '新增课程') + '：' + category.name,
      onclick: () => this.open('category', {
        parent: category.id,
        kind: canCollege ? 'college' : 'course'
      })
    }) : null, ['college', 'course'].includes(category.kind) ? m(Button, {
      className: 'Button Button--icon',
      icon: 'fas fa-pen',
      'aria-label': '修改分类：' + category.name,
      onclick: () => this.open('category', {
        item: category
      })
    }) : null]) : null]), children.length ? m('ul', children.map(c => this.categoryNode(c, depth + 1))) : category.kind === 'root' ? m('p.Campus-directoryTreeEmpty', '学院由维护者按实际情况补充。') : null]);
  }
  content() {
    const d = this.data,
      selected = d.categories.find(c => c.id === Number(this.params.category_id));
    const crumbs = pathOf(d.categories, selected);
    return [pageHeading('资料分类目录', '按学院、课程与校外考试查找来源链接。', [link('/resources/assist', '用问题找链接', {
      className: 'Button Button--primary'
    }), link('/t/campus-resources', '返回资料讨论', {
      className: 'Campus-directoryBack'
    })]), m('div.Campus-directoryLayout', [m('aside.Campus-directoryTree', [m('h2', [symbol('folder-open'), ' 分类导航']), m(Link, {
      href: this.filter(null),
      className: 'Campus-directoryAll' + (!selected ? ' is-active' : '')
    }, '全部资料'), m('ul', d.categories.filter(c => c.parent_id === null).map(c => this.categoryNode(c)))]), m('section.Campus-directoryMain', [m('nav.Campus-directoryBreadcrumbs', {
      'aria-label': '资料位置'
    }, [link(this.filter(null), '全部资料'), ...crumbs.flatMap(c => [m('span', {
      'aria-hidden': 'true'
    }, '/'), link(this.filter(c.id), c.name)])]), m('form.Campus-directorySearch', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set(this.filter(this.params.category_id));
      }
    }, [m('label.sr-only', {
      for: 'directory-search'
    }, '搜索资料'), m('input.FormControl', {
      id: 'directory-search',
      type: 'search',
      value: this.query,
      placeholder: '搜索资料名称或说明',
      oninput: e => this.query = e.target.value
    }), m(Button, {
      type: 'submit',
      className: 'Button Button--primary',
      icon: 'fas fa-search'
    }, '搜索')]), d.can_manage ? m('div.Campus-actions', [m(Button, {
      icon: 'fas fa-plus',
      onclick: () => this.open('resource')
    }, '新增资料链接'), m(Button, {
      icon: 'far fa-flag',
      onclick: () => this.loadReports()
    }, '查看待处理反馈')]) : null, d.resources.length ? m('div.Campus-directoryList', d.resources.map(item => m('article.Campus-directoryCard', {
      key: item.id
    }, [m('div.Campus-directoryCardHeader', [m('span.Campus-directoryDocIcon', symbol('file-alt')), m('div', [m('h2', item.title), m('p.Campus-hint', pathOf(d.categories, d.categories.find(c => c.id === item.category_id)).map(c => c.name).join(' / '))]), m('span.Campus-badge', {
      className: item.status === 'active' ? 'is-approved' : ''
    }, item.status === 'active' ? '有效' : '待核验')]), item.description ? m('p.Campus-directoryDescription', item.description) : null, m('div.Campus-directorySource', [symbol('link'), m('span', new URL(item.url).hostname)]), m('div.Campus-directoryCardFooter', [m('div.Campus-actions', [m('a.Button.Button--primary', {
      href: item.url,
      target: '_blank',
      rel: 'noopener noreferrer'
    }, ['访问资料', symbol('external-link-alt')]), item.source_url ? m('a', {
      href: item.source_url,
      target: '_blank',
      rel: 'noopener noreferrer'
    }, '原始来源') : null]), m('div.Campus-directorySecondary', [app.session.user ? m(Button, {
      className: 'Button Button--link',
      icon: 'far fa-flag',
      onclick: () => this.open('report', {
        item
      })
    }, '反馈问题') : null, d.can_manage ? [m(Button, {
      className: 'Button Button--link',
      icon: 'fas fa-pen',
      onclick: () => this.open('resource', {
        item
      })
    }, '修改'), m(Button, {
      className: 'Button Button--link',
      icon: 'fas fa-history',
      onclick: () => app.modal.show(DirectoryHistory, {
        item,
        categories: d.categories
      })
    }, '修订记录')] : null])]), m('p.Campus-directoryUpdated', '更新于 ' + formatTime(item.updated_at))]))) : emptyState('没有找到资料', this.params.q ? '试试其他关键词，或切换分类。' : '维护者补充来源链接后，会展示在这里。'), pagination({
      page: d.page,
      total: d.total,
      size: 25,
      hasNext: d.has_more,
      href: page => this.filter(this.params.category_id, this.params.q || '', page),
      label: '资料目录分页'
    }), d.can_manage && this.showReports ? m('section.Campus-panel', [m('h2', '待处理反馈'), this.reports.length ? this.reports.map(report => m('div.Campus-directoryReport', {
      key: report.id
    }, [m('div', [m('strong', report.title), m('p', report.reason), m('a', {
      href: report.url,
      target: '_blank',
      rel: 'noopener noreferrer'
    }, '核对访问链接')]), m(Button, {
      onclick: () => this.perform(async () => {
        await request('reports/' + report.id, 'PATCH', {
          status: 'resolved'
        });
        this.reports = this.reports.filter(r => r.id !== report.id);
      }, {
        refresh: false,
        message: '反馈已标记处理。'
      })
    }, '标记已处理')])) : emptyState('反馈已处理完', '新的失效或内容反馈会出现在这里。')]) : null, m('p.Campus-directoryFootnote', '本站整理合法来源链接，原文件由来源网站提供。')])])];
  }
}
export class ResourceAssistPage extends DataPage {
  oninit(vnode) {
    this.query = '';
    this.provider = 'gemini';
    this.model = '';
    this.apiKey = '';
    this.answer = '';
    super.oninit(vnode);
  }
  onremove(vnode) {
    this.apiKey = '';
    super.onremove(vnode);
  }
  async fetch() {
    this.query = this.params.q || '';
    this.answer = '';
    this.data = this.query.trim() ? (await request('resources/assist?q=' + encodeURIComponent(this.query.trim()))).resources : [];
  }
  pageTitle() {
    return '按问题查找资料链接';
  }
  async ask() {
    if (this.busy || !this.apiKey || !this.model || !this.data.length) return;
    if (this.query.trim() !== (this.params.q || '').trim()) {
      this.notice = '问题已修改，请先点击“查找链接”查看将用于总结的结果。';
      this.noticeError = true;
      return;
    }
    this.busy = true;
    this.notice = '';
    this.answer = '';
    m.redraw();
    try {
      const result = await request('resources/assist', 'POST', {
        q: this.query.trim(), provider: this.provider, model: this.model.trim(), api_key: this.apiKey
      });
      this.answer = result.answer;
      this.data = result.resources;
    } catch (error) {
      this.notice = error.response?.error || errorMessage(error, '生成失败，目录搜索仍可使用。');
      this.noticeError = true;
    } finally {
      this.apiKey = '';
      this.busy = false;
      m.redraw();
    }
  }
  content() {
    return [pageHeading('按问题查找资料链接', '先搜索人工维护的目录，再选择是否使用自己的 API Key 生成辅助说明。', link('/resources', '返回资料目录')),
      m('section.Campus-panel.Campus-resourceAssist', [
        m('form.Campus-resourceAssistSearch', { onsubmit: e => {
          e.preventDefault();
          m.route.set('/resources/assist?q=' + encodeURIComponent(this.query.trim()));
        } }, [
          field('q', '想找什么资料？', this.query, value => this.query = value, { required: true, maxlength: 100, placeholder: '例如：社会学概论复习资料' }),
          m(Button, { type: 'submit', className: 'Button Button--primary', icon: 'fas fa-search' }, '查找链接')
        ]),
        m('p.Campus-hint', '这里只查找资料标题、学院／课程分类和维护者填写的说明，不读取网盘文件。'),
        this.data.length ? m('ol.Campus-resourceAssistResults', this.data.map((item, index) => m('li', { key: item.id }, [
          m('span.Campus-resourceAssistNumber', '[' + (index + 1) + ']'),
          m('div', [m('h2', item.title), m('p.Campus-hint', item.category_path), item.description ? m('p', item.description) : m('p.Campus-hint', '维护者尚未填写简介。'),
            m('div.Campus-actions', [m('a', { href: item.url, target: '_blank', rel: 'noopener noreferrer nofollow' }, '访问链接 ↗'), item.source_url ? m('a', { href: item.source_url, target: '_blank', rel: 'noopener noreferrer nofollow' }, '核对原始来源 ↗') : null])])
        ]))) : this.params.q ? emptyState('没有找到相关链接', '换个课程名称或更短的关键词试试。') : null
      ]),
      this.data.length ? m('section.Campus-panel.Campus-resourceAssistAI', [
        m('h2', '用自己的 API Key 整理结果'),
        m('p.Campus-hint', '可选功能。请求经本站服务器转发给所选服务商，只发送上方链接的标题、分类和简短说明；密钥用后清空，不存入数据库。服务商可能收费，请自行查看额度。'),
        app.session.user ? m('form', { onsubmit: e => { e.preventDefault(); this.ask(); } }, [
          m('label.Campus-field', [m('span.Campus-fieldLabel', '服务商'), m('select.FormControl', { value: this.provider, onchange: e => this.provider = e.target.value }, [
            m('option', { value: 'gemini' }, 'Google Gemini'), m('option', { value: 'siliconflow' }, '硅基流动'),
            m('option', { value: 'deepseek' }, 'DeepSeek'), m('option', { value: 'openai' }, 'OpenAI')
          ])]),
          field('model', '模型名称', this.model, value => this.model = value, { required: true, maxlength: 120, placeholder: '从服务商控制台复制模型名称', hint: '各服务商可用模型及额度会变化，请以其控制台显示为准。' }),
          field('api_key', '你的 API Key', this.apiKey, value => this.apiKey = value, { type: 'password', required: true, autocomplete: 'off', maxlength: 512 }),
          m(Button, { type: 'submit', className: 'Button Button--primary', loading: this.busy }, '生成目录说明')
        ]) : m('p.Campus-hint', '登录后可使用自己的 API Key；不登录也能查看上面的搜索结果。'),
        this.answer ? m('div.Campus-resourceAssistAnswer', [m('h3', '辅助说明'), m('p', this.answer), m('small', '编号对应上方链接；AI 没有读取网盘内的文件，请打开原链接核对。')]) : null
      ]) : null
    ];
  }
}
export function registerDirectory() {
  // The resources extension registers its directory route in JavaScript. The
  // assistant is a new client route, so it cannot be gated on already existing.
  if (!app.routes['ucasser.resources']) return;
  app.routes['ucasser.resources'] = {
    path: '/resources',
    component: DirectoryPage
  };
  app.routes['ucasser.resources.assist'] = {
    path: '/resources/assist',
    component: ResourceAssistPage
  };
}

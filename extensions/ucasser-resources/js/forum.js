(function () {
  'use strict';

  var app = window.app;
  var m = window.m;
  var api = function (path) { return app.forum.attribute('apiUrl') + '/ucasser/' + path; };

  function childCategories(categories, parentId) {
    return categories.filter(function (category) { return category.parent_id === parentId; })
      .sort(function (a, b) { return a.position - b.position || a.id - b.id; });
  }

  function categoryPath(categories, category) {
    var names = [];
    while (category) {
      names.unshift(category.name);
      category = categories.find(function (item) { return item.id === category.parent_id; });
    }
    return names.join(' / ');
  }

  function ResourcePage() {
    this.categories = [];
    this.resources = [];
    this.selected = null;
    this.query = '';
    this.page = 1;
    this.hasMore = false;
    this.canManage = false;
    this.loading = true;
    this.error = '';
    this.notice = '';
    this.editing = null;
    this.form = { category_id: '', title: '', url: '', description: '', source_url: '', status: 'active', reason: '' };
    this.reports = [];
  }

  ResourcePage.prototype.oninit = function () {
    var page = this;
    app.request({ url: api('categories') }).then(function (data) {
      page.categories = data.categories;
      page.load();
    }).catch(function () {
      page.error = '分类暂时无法加载，请稍后重试。';
      page.loading = false;
      m.redraw();
    });
  };

  ResourcePage.prototype.load = function () {
    var page = this;
    page.loading = true;
    var query = '?page=' + page.page + (page.selected ? '&category_id=' + page.selected : '') + (page.query ? '&q=' + encodeURIComponent(page.query) : '');
    return app.request({ url: api('resources') + query }).then(function (data) {
      page.resources = data.resources;
      page.hasMore = data.has_more;
      page.canManage = data.can_manage;
      page.loading = false;
      m.redraw();
    }).catch(function () {
      page.error = '资料暂时无法加载，请稍后重试。';
      page.loading = false;
      m.redraw();
    });
  };

  ResourcePage.prototype.refreshCategories = function () {
    var page = this;
    return app.request({ url: api('categories') }).then(function (data) {
      page.categories = data.categories;
      m.redraw();
    });
  };

  ResourcePage.prototype.addCategory = function (parent, kind) {
    var page = this;
    var name = window.prompt(kind === 'college' ? '学院名称' : '课程名称');
    if (!name) return;
    app.request({ method: 'POST', url: api('categories'), body: { parent_id: parent.id, kind: kind, name: name } })
      .then(function () { page.notice = '分类已添加'; page.error = ''; return page.refreshCategories(); })
      .catch(function (error) { page.error = error.message || '添加分类失败'; m.redraw(); });
  };

  ResourcePage.prototype.renameCategory = function (category) {
    var page = this;
    var name = window.prompt('修改名称', category.name);
    if (!name || name === category.name) return;
    app.request({ method: 'PATCH', url: api('categories/' + category.id), body: { name: name } })
      .then(function () { page.notice = '名称已修改'; page.error = ''; return page.refreshCategories(); })
      .catch(function (error) { page.error = error.message || '修改名称失败'; m.redraw(); });
  };

  ResourcePage.prototype.categoryNode = function (category, depth) {
    var page = this;
    var children = childCategories(page.categories, category.id);
    var leaf = category.kind === 'course' || category.kind === 'external_topic';
    return m('div.ResourceDirectory-category', { key: category.id }, [
      m('div.ResourceDirectory-categoryRow', { style: { paddingLeft: (depth * 16) + 'px' } }, [
        leaf ? m('button.Button' + (page.selected === category.id ? '.Button--primary' : ''), {
          type: 'button', onclick: function () { page.selected = category.id; page.page = 1; page.load(); }
        }, category.name) : m('strong', category.name),
        page.canManage && (category.kind === 'college' || category.kind === 'course') ? m('button.Button', {
          type: 'button', onclick: function () { page.renameCategory(category); }, title: '修改名称'
        }, '改名') : null,
        page.canManage && category.kind === 'root' && category.name === '校内资料' ? m('button.Button', {
          type: 'button', onclick: function () { page.addCategory(category, 'college'); }
        }, '+ 学院') : null,
        page.canManage && category.kind === 'course_group' ? m('button.Button', {
          type: 'button', onclick: function () { page.addCategory(category, 'course'); }
        }, '+ 课程') : null
      ]),
      children.length ? children.map(function (child) { return page.categoryNode(child, depth + 1); }) :
        category.kind === 'root' && category.name === '校内资料' ? m('p.ResourceDirectory-empty', '暂无学院，管理员可以添加。') :
        category.kind === 'course_group' ? m('p.ResourceDirectory-empty', '暂无课程。') : null
    ]);
  };

  ResourcePage.prototype.startEdit = function (item) {
    this.editing = item ? item.id : 0;
    this.form = item ? {
      category_id: item.category_id, title: item.title, url: item.url,
      description: item.description || '', source_url: item.source_url || '', status: item.status, reason: ''
    } : { category_id: this.selected || '', title: '', url: '', description: '', source_url: '', status: 'active', reason: '' };
  };

  ResourcePage.prototype.save = function (event) {
    event.preventDefault();
    var page = this;
    var editing = page.editing;
    var path = editing ? 'resources/' + editing : 'resources';
    app.request({ method: editing ? 'PATCH' : 'POST', url: api(path), body: page.form })
      .then(function () { page.editing = null; page.notice = editing ? '资料已更新' : '资料已添加'; page.error = ''; return page.load(); })
      .catch(function (error) { page.error = error.message || '保存失败，请检查填写内容'; m.redraw(); });
  };

  ResourcePage.prototype.report = function (item) {
    var page = this;
    var reason = window.prompt('请说明链接失效或资料错误的情况');
    if (!reason) return;
    app.request({ method: 'POST', url: api('reports'), body: { resource_id: item.id, reason: reason } })
      .then(function () { page.notice = '反馈已提交，等待维护者核对'; page.error = ''; m.redraw(); })
      .catch(function (error) { page.error = error.message || '提交反馈失败，请先登录'; m.redraw(); });
  };

  ResourcePage.prototype.loadReports = function () {
    var page = this;
    app.request({ url: api('reports') }).then(function (data) { page.reports = data.reports; m.redraw(); })
      .catch(function () { page.error = '反馈列表加载失败'; m.redraw(); });
  };

  ResourcePage.prototype.resolveReport = function (report) {
    var page = this;
    app.request({ method: 'PATCH', url: api('reports/' + report.id), body: { status: 'resolved' } })
      .then(function () { page.notice = '反馈已处理'; page.loadReports(); })
      .catch(function () { page.error = '处理反馈失败'; m.redraw(); });
  };

  ResourcePage.prototype.view = function () {
    var page = this;
    var roots = childCategories(page.categories, null);
    var leafCategories = page.categories.filter(function (category) { return category.kind === 'course' || category.kind === 'external_topic'; });
    return m('div.ResourceDirectory.container', [
      m('h1', '资源汇总'),
      m('p', '按学院、课程或校外考试查找资料。本站只整理来源链接，不托管原文件。'),
      page.notice ? m('p.ResourceDirectory-notice', page.notice) : null,
      page.error ? m('p.ResourceDirectory-error', page.error) : null,
      m('div.ResourceDirectory-layout', [
        m('nav.ResourceDirectory-tree', { 'aria-label': '资料分类' }, [
          m('button.Button' + (!page.selected ? '.Button--primary' : ''), {
            type: 'button', onclick: function () { page.selected = null; page.page = 1; page.load(); }
          }, '全部资料'),
          roots.map(function (root) { return page.categoryNode(root, 0); })
        ]),
        m('main.ResourceDirectory-main', [
          m('form.ResourceDirectory-search', { onsubmit: function (event) { event.preventDefault(); page.page = 1; page.load(); } }, [
            m('input.FormControl', { type: 'search', placeholder: '搜索资料名称或简介', value: page.query,
              oninput: function (event) { page.query = event.target.value; } }),
            m('button.Button.Button--primary', { type: 'submit' }, '搜索')
          ]),
          page.canManage ? m('div.ResourceDirectory-actions', [
            m('button.Button', { type: 'button', onclick: function () { page.startEdit(null); } }, '+ 新增资料'),
            m('button.Button', { type: 'button', onclick: function () { page.loadReports(); } }, '查看待处理反馈')
          ]) : null,
          page.canManage && page.editing !== null ? m('form.ResourceDirectory-form', { onsubmit: function (event) { page.save(event); } }, [
            m('h2', page.editing ? '修改资料' : '新增资料'),
            m('label', '所属课程或校外类别'),
            m('select.FormControl', { value: page.form.category_id, onchange: function (event) { page.form.category_id = event.target.value; } }, [
              m('option', { value: '' }, '请选择'),
              leafCategories.map(function (category) { return m('option', { value: category.id }, categoryPath(page.categories, category)); })
            ]),
            [['title', '资料名称'], ['url', '访问链接'], ['source_url', '原始来源链接'], ['description', '简介']].map(function (field) {
              return m('label', { key: field[0] }, [field[1], m('input.FormControl', { type: field[0].includes('url') ? 'url' : 'text', value: page.form[field[0]],
                oninput: function (event) { page.form[field[0]] = event.target.value; } })]);
            }),
            m('label', ['状态', m('select.FormControl', { value: page.form.status, onchange: function (event) { page.form.status = event.target.value; } }, [
              m('option', { value: 'active' }, '有效'), m('option', { value: 'needs_review' }, '待核验')
            ])]),
            page.editing ? m('label', ['修订原因', m('input.FormControl', { value: page.form.reason, required: true,
              oninput: function (event) { page.form.reason = event.target.value; } })]) : null,
            m('button.Button.Button--primary', { type: 'submit' }, '保存'),
            m('button.Button', { type: 'button', onclick: function () { page.editing = null; } }, '取消')
          ]) : null,
          page.loading ? m('p', '正在加载…') : page.resources.length ? page.resources.map(function (item) {
            return m('article.ResourceDirectory-item', { key: item.id }, [
              m('h2', item.title),
              m('small', categoryPath(page.categories, page.categories.find(function (category) { return category.id === item.category_id; }))),
              item.description ? m('p', item.description) : null,
              m('p', [m('a', { href: item.url, target: '_blank', rel: 'noopener noreferrer' }, '访问资料 ↗'),
                item.source_url ? [' · ', m('a', { href: item.source_url, target: '_blank', rel: 'noopener noreferrer' }, '查看原始来源')] : null]),
              m('small', [item.status === 'active' ? '有效' : '待核验', ' · 更新于 ', item.updated_at]),
              m('div', [m('button.Button', { type: 'button', onclick: function () { page.report(item); } }, '反馈问题'),
                page.canManage ? m('button.Button', { type: 'button', onclick: function () { page.startEdit(item); } }, '修改') : null])
            ]);
          }) : m('p.ResourceDirectory-empty', '这里还没有资料链接。'),
          m('div.ResourceDirectory-pages', [
            page.page > 1 ? m('button.Button', { type: 'button', onclick: function () { page.page--; page.load(); } }, '上一页') : null,
            page.hasMore ? m('button.Button', { type: 'button', onclick: function () { page.page++; page.load(); } }, '下一页') : null
          ]),
          page.canManage && page.reports.length ? m('section.ResourceDirectory-reports', [m('h2', '待处理反馈'), page.reports.map(function (report) {
            return m('p', { key: report.id }, ['资料 #', report.resource_id, '：', report.reason, ' ',
              m('button.Button', { type: 'button', onclick: function () { page.resolveReport(report); } }, '标记已处理')]);
          })]) : null
        ])
      ])
    ]);
  };

  app.initializers.add('ucasser-resources', function () {
    app.routes['ucasser.resources'] = { path: '/resources', resolver: {
      onmatch: function () { return ResourcePage; },
      render: function (vnode) { return vnode; }
    } };
    flarum.reg.onLoad('core', 'forum/components/HeaderPrimary', function (HeaderPrimary) {
      var original = HeaderPrimary.prototype.items;
      HeaderPrimary.prototype.items = function () {
        var items = original.call(this);
        items.add('ucasser-resources', m('a.Button', { href: app.route('ucasser.resources') }, '资源汇总'), 50);
        return items;
      };
    });
  });
  module.exports = {};
})();

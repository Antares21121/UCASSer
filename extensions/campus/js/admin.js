import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import BasicsPage from 'flarum/admin/components/BasicsPage';
import { extend } from 'flarum/common/extend';
import Button from './button';
const types = {
  information: '校园信息',
  resource: '共享资料',
  question: '校园问答',
  review: '课程评价'
};
const tabs = [['sections', '分区设置'], ['categories', '内容分类'], ['identity', '身份审核'], ['roles', '志愿授权'], ['audit', '操作审计']];
const field = (label, value, set, attrs = {}) => m('label.Campus-field', [m('span.Campus-fieldLabel', label), m(attrs.textarea ? 'textarea.FormControl' : 'input.FormControl', {
  value: value ?? '',
  oninput: e => set(e.target.value),
  ...attrs
})]);
const select = (label, value, set, items) => m('label.Campus-field', [m('span.Campus-fieldLabel', label), m('select.FormControl', {
  value,
  required: true,
  onchange: e => set(e.target.value)
}, items.map(([v, text]) => m('option', {
  value: v
}, text)))]);
const time = value => value ? new Date(value.replace(' ', 'T') + 'Z').toLocaleString('zh-CN', {
  timeZone: 'Asia/Shanghai',
  hour12: false
}) : '';
class CampusAdmin extends ExtensionPage {
  oninit(v) {
    super.oninit(v);
    this.tab = 'sections';
    this.notice = '';
    this.grant = {
      user_id: '',
      section_id: '',
      enabled: true,
      reason: ''
    };
    this.load();
  }
  api(path, method = 'GET', body) {
    return app.request({
      method,
      url: app.forum.attribute('apiUrl') + '/campus/' + path,
      body
    });
  }
  async load() {
    this.loading = true;
    this.error = '';
    try {
      const [s, c, q, a, r] = await Promise.all(['sections', 'categories', 'identity/queue', 'audit', 'moderators'].map(p => this.api(p)));
      this.sectionRows = s.data;
      this.categories = c.data;
      this.queue = q.data;
      this.audit = a.data;
      this.roles = r.data;
    } catch (_) {
      this.error = '加载管理数据失败，请重试。';
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
  async run(work, message) {
    if (this.busy) return;
    this.busy = true;
    this.notice = '';
    this.noticeError = false;
    try {
      await work();
      this.notice = message;
      await this.load();
    } catch (e) {
      this.notice = e.response?.errors?.map(x => x.detail).filter(Boolean).join('；') || '操作失败，请检查输入与权限后重试。';
      this.noticeError = true;
    } finally {
      this.busy = false;
      m.redraw();
    }
  }
  sectionsContent() {
    return [m('h2', '校园分区设置'), m('p.Campus-lead', '调整名称、顺序和访问范围。更改范围会同时影响页面、搜索、通知与导出。'), ...this.sectionRows.map(s => m('section.Campus-panel', [m('h3', s.name), m('div.Campus-badges', [m('span.Campus-badge', s.is_open ? '开放中' : '已关闭'), m('span.Campus-badge', {
      public: '公开',
      members: '登录成员',
      verified: '校内验证成员'
    }[s.visibility])]), m('details.Campus-filters', [m('summary', '编辑分区'), m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.run(() => this.api('sections/' + s.id, 'PATCH', {
          name: s.name,
          description: s.description,
          position: Number(s.position),
          is_open: Boolean(s.is_open),
          visibility: s.visibility
        }), '分区设置已保存');
      }
    }, [field('名称', s.name, v => s.name = v, {
      required: true,
      minlength: 2,
      maxlength: 100
    }), field('简介', s.description, v => s.description = v, {
      maxlength: 1000
    }), m('div.Campus-formGrid', [field('顺序', s.position, v => s.position = v, {
      type: 'number',
      min: 0,
      required: true
    }), select('访问范围', s.visibility, v => s.visibility = v, [['public', '公开'], ['members', '登录成员'], ['verified', '校内验证成员']])]), m('label', [m('input', {
      type: 'checkbox',
      checked: s.is_open,
      onchange: e => s.is_open = e.target.checked
    }), ' 开放分区']), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '保存分区')])])]))];
  }
  categoriesContent() {
    return [m('h2', '内容分类维护'), m('p.Campus-lead', '关闭分类只限制新发布，已有内容与历史记录会保留。'), ...Object.entries(types).map(([type, title]) => m('section.Campus-panel', [m('h3', title), ...this.categories.filter(c => c.type === type).map(c => m('form.Campus-formGrid', {
      onsubmit: e => {
        e.preventDefault();
        this.run(() => this.api('categories', 'PATCH', {
          type: c.type,
          key: c.key,
          name: c.name,
          enabled: Boolean(c.enabled)
        }), '分类已保存');
      }
    }, [field('分类名称', c.name, v => c.name = v, {
      required: true,
      minlength: 2,
      maxlength: 80
    }), m('label', [m('input', {
      type: 'checkbox',
      checked: c.enabled,
      onchange: e => c.enabled = e.target.checked
    }), ' 允许新内容使用']), m(Button, {
      type: 'submit'
    }, '保存分类')]))]))];
  }
  identityContent() {
    return [m('h2', '人工身份审核'), m('p.Campus-lead', '仅查看必要材料。批准或拒绝后删除原始说明；审核理由不得包含个人材料。'), ...this.queue.map(r => m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        this.run(() => this.api('identity/' + r.id, 'PATCH', {
          status: r.decision || 'approved',
          reason: r.note || ''
        }), '审核已完成，原始说明已删除');
      }
    }, [m('h3', r.display_name + ' · 申请 #' + r.id), m('small', time(r.created_at)), m('p.Campus-text', r.evidence), select('审核决定', r.decision || 'approved', v => r.decision = v, [['approved', '批准'], ['rejected', '拒绝']]), field('审核理由', r.note, v => r.note = v, {
      textarea: true,
      placeholder: '审核理由（5–500 字）',
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '处理申请')])), this.queue.length ? null : m('div.Campus-empty', [m('strong', '暂无待审核申请'), m('p', '新的申请会显示在这里。')]), m('details.Campus-panel.Campus-filters', [m('summary', '撤销已通过的身份验证'), m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.run(() => this.api('identity/' + Number(this.revokeId), 'PATCH', {
          status: 'revoked',
          reason: this.revokeReason || ''
        }), '验证已撤销');
      }
    }, [field('已批准申请编号', this.revokeId, v => this.revokeId = v, {
      type: 'number',
      min: 1,
      required: true
    }), field('撤销理由', this.revokeReason, v => this.revokeReason = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit'
    }, '撤销验证')])])];
  }
  rolesContent() {
    return [m('h2', '分区志愿者授权与撤销'), m('p.Campus-lead', '授权只覆盖选中的分区。交接时先撤销离任人员，再授权新成员。'), m('section.Campus-panel', [m('h3', '当前授权'), this.roles.length ? m('ul.Campus-list', this.roles.map(r => m('li', [r.display_name + ' · ' + (this.sectionRows.find(s => s.id === r.section_id)?.name || '分区 #' + r.section_id), m(Button, {
      onclick: () => this.grant = {
        user_id: r.user_id,
        section_id: r.section_id,
        enabled: false,
        reason: ''
      }
    }, '准备撤销')]))) : m('p.Campus-hint', '当前没有分区志愿者授权。')]), m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        this.run(() => this.api('moderators', 'POST', {
          user_id: Number(this.grant.user_id),
          section_id: Number(this.grant.section_id),
          enabled: this.grant.enabled,
          reason: this.grant.reason
        }), '分区授权已更新');
      }
    }, [m('h3', this.grant.enabled ? '添加授权' : '撤销授权'), field('搜索成员', this.userQuery, v => this.userQuery = v, {
      type: 'search'
    }), m(Button, {
      type: 'button',
      onclick: () => this.run(async () => {
        this.userChoices = await app.store.find('users', {
          filter: {
            q: this.userQuery || ''
          },
          page: {
            limit: 10
          }
        });
      }, '请选择要授权的成员')
    }, '查找成员'), (this.userChoices || []).map(u => m('p', [m(Button, {
      type: 'button',
      onclick: () => this.grant.user_id = u.id()
    }, '选择'), u.displayName() + ' · #' + u.id()])), field('用户编号', this.grant.user_id, v => this.grant.user_id = v, {
      type: 'number',
      min: 1,
      required: true
    }), select('分区', this.grant.section_id, v => this.grant.section_id = v, [['', '选择分区'], ...this.sectionRows.map(s => [s.id, s.name])]), m('label', [m('input', {
      type: 'checkbox',
      checked: this.grant.enabled,
      onchange: e => this.grant.enabled = e.target.checked
    }), ' 授权（取消勾选即撤销）']), field('理由', this.grant.reason, v => this.grant.reason = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '更新授权')])];
  }
  auditContent() {
    const names = {
      'verification.approved': '批准验证',
      'verification.rejected': '拒绝验证',
      'verification.revoked': '撤销验证',
      'moderator.grant': '分区授权',
      'moderator.revoke': '撤销授权',
      'discussion.hide': '隐藏讨论',
      'discussion.restore': '恢复讨论',
      'record.update': '更新内容',
      'course.save': '维护课程',
      'case.hide': '举报隐藏',
      'case.restore': '举报恢复',
      'case.delete': '永久删除',
      'case.suspend': '封禁账号',
      'case.unban': '解除封禁'
    };
    return [m('h2', '近期操作审计'), m('p.Campus-lead', '仅管理员可见。近期记录用于核对操作与理由，不公开个人材料。'), m('ul.Campus-list', this.audit.map(r => m('li', [m('strong', names[r.action] || r.action), m('small', time(r.created_at) + ' · 操作者 #' + (r.actor_id || '已移除') + ' · 对象 ' + r.target), m('p.Campus-text', r.reason || '无补充理由')]))), this.audit.length ? null : m('p.Campus-empty', '暂无审计记录。')];
  }
  content() {
    return m('div.Campus.Campus-admin', [m('header.Campus-pageHeader', [m('div', [m('p.Campus-eyebrow', 'UCASSer / 社区管理'), m('h1', '校园公共社区'), m('p.Campus-lead', '清晰的分区、必要的权限和可追溯的管理操作。')])]), m('nav.Campus-adminTabs', {
      role: 'tablist',
      'aria-label': '校园管理模块'
    }, tabs.map(([key, title], i) => m(Button, {
      role: 'tab',
      id: 'campus-tab-' + key,
      'aria-controls': 'campus-admin-panel',
      'aria-selected': this.tab === key,
      tabindex: this.tab === key ? 0 : -1,
      className: 'Button' + (this.tab === key ? ' Button--primary' : ''),
      onclick: () => this.tab = key,
      onkeydown: e => {
        if (['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(e.key)) {
          e.preventDefault();
          const index = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
          this.tab = tabs[index][0];
          m.redraw.sync();
          document.getElementById('campus-tab-' + this.tab).focus();
        }
      }
    }, title + (key === 'identity' && this.queue?.length ? ' · ' + this.queue.length : '')))), this.notice ? m('p.Campus-notice', {
      role: this.noticeError ? 'alert' : 'status',
      className: this.noticeError ? 'is-error' : ''
    }, this.notice) : null, this.loading ? m('p', {
      role: 'status'
    }, '正在加载管理数据…') : this.error ? m('section.Campus-panel', {
      role: 'alert'
    }, [m('p', this.error), m(Button, {
      onclick: () => this.load()
    }, '重新加载')]) : m('fieldset#campus-admin-panel.Campus-interaction', {
      role: 'tabpanel',
      'aria-labelledby': 'campus-tab-' + this.tab,
      disabled: Boolean(this.busy)
    }, this[this.tab + 'Content']()), this.busy ? m('p', {
      role: 'status'
    }, '正在保存，请稍候…') : null]);
  }
}
app.initializers.add('ucasser-campus', () => {
  app.registry.for('ucasser-campus').registerPage(CampusAdmin);
  extend(BasicsPage, 'homePageItems', items => items.add('campus', {
    path: '/campus',
    label: '校园公共社区首页'
  }, 50));
});

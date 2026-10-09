import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
class CampusAdmin extends ExtensionPage {
  oninit(v) {
    super.oninit(v);
    this.sectionRows = [];
    this.queue = [];
    this.audit = [];
    this.categories = [];
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
    try {
      const [s, q, a, c] = await Promise.all([this.api('sections'), this.api('identity/queue'), this.api('audit'), this.api('categories')]);
      this.sectionRows = s.data;
      this.queue = q.data;
      this.audit = a.data;
      this.categories = c.data;
    } catch (_) {
      this.notice = '加载管理数据失败，请刷新重试。';
    } finally {
      m.redraw();
    }
  }
  governance() {
    return [m('h2', '内容分类维护'), ...this.categories.map(c => m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await this.api('categories', 'PATCH', {
            type: c.type,
            key: c.key,
            name: c.name,
            enabled: Boolean(c.enabled)
          });
          this.notice = '分类已保存';
        } catch (_) {
          this.notice = '分类保存失败';
        }
        m.redraw();
      }
    }, [m('p', c.type + ' / ' + c.key), m('input.FormControl', {
      value: c.name,
      oninput: e => c.name = e.target.value,
      required: true,
      minlength: 2,
      maxlength: 80
    }), m('label', [m('input', {
      type: 'checkbox',
      checked: c.enabled,
      onchange: e => c.enabled = e.target.checked
    }), ' 允许新内容使用']), m(Button, {
      type: 'submit'
    }, '保存分类')])), m('h2', '人工身份审核'), m('p', '验证说明仅用于必要审核；处理后删除原始材料。不要将个人材料粘贴进审核理由。'), ...this.queue.map(r => m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await this.api('identity/' + r.id, 'PATCH', {
            status: r.decision || 'approved',
            reason: r.note || ''
          });
          this.notice = '审核已完成，原始说明已删除';
          await this.load();
        } catch (_) {
          this.notice = '审核失败，请检查理由或刷新状态。';
        }
        m.redraw();
      }
    }, [m('p', `申请 #${r.id} · 用户 #${r.user_id}`), m('p.Campus-text', r.evidence), m('select.FormControl', {
      onchange: e => r.decision = e.target.value
    }, [m('option', {
      value: 'approved'
    }, '批准'), m('option', {
      value: 'rejected'
    }, '拒绝')]), m('textarea.FormControl', {
      placeholder: '审核理由（5–500 字）',
      required: true,
      minlength: 5,
      maxlength: 500,
      oninput: e => r.note = e.target.value
    }), m(Button, {
      type: 'submit'
    }, '处理申请')])), this.queue.length ? null : m('p', '暂无待审核申请。'), m('h2', '分区志愿者授权与撤销'), m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await this.api('moderators', 'POST', {
            user_id: Number(this.grant.user_id),
            section_id: Number(this.grant.section_id),
            enabled: this.grant.enabled,
            reason: this.grant.reason
          });
          this.notice = '分区授权已更新';
          await this.load();
        } catch (_) {
          this.notice = '授权失败，请检查用户、分区与理由。';
        }
        m.redraw();
      }
    }, [m('label', ['用户编号', m('input.FormControl', {
      type: 'number',
      min: 1,
      required: true,
      value: this.grant.user_id,
      oninput: e => this.grant.user_id = e.target.value
    })]), m('label', ['分区', m('select.FormControl', {
      required: true,
      value: this.grant.section_id,
      onchange: e => this.grant.section_id = e.target.value
    }, [m('option', {
      value: ''
    }, '选择分区'), ...this.sectionRows.map(s => m('option', {
      value: s.id
    }, s.name))])]), m('label', [m('input', {
      type: 'checkbox',
      checked: this.grant.enabled,
      onchange: e => this.grant.enabled = e.target.checked
    }), ' 授权（取消勾选即撤销）']), m('label', ['理由', m('textarea.FormControl', {
      required: true,
      minlength: 5,
      maxlength: 500,
      value: this.grant.reason,
      oninput: e => this.grant.reason = e.target.value
    })]), m(Button, {
      type: 'submit'
    }, '更新授权')]), m('h2', '身份验证撤销'), m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await this.api('identity/' + Number(this.revokeId), 'PATCH', {
            status: 'revoked',
            reason: this.revokeReason
          });
          this.notice = '验证已撤销';
          await this.load();
        } catch (_) {
          this.notice = '撤销失败，请检查已批准申请编号与理由。';
        }
        m.redraw();
      }
    }, [m('input.FormControl', {
      type: 'number',
      min: 1,
      required: true,
      placeholder: '已批准申请编号',
      oninput: e => this.revokeId = e.target.value
    }), m('textarea.FormControl', {
      required: true,
      minlength: 5,
      placeholder: '撤销理由',
      oninput: e => this.revokeReason = e.target.value
    }), m(Button, {
      type: 'submit'
    }, '撤销验证')]), m('h2', '近期审计记录'), m('ul', this.audit.map(r => m('li', `${r.created_at} · 操作者 #${r.actor_id || '已移除'} · ${r.action} · 对象 ${r.target} · ${r.reason || ''}`)))];
  }
  content() {
    return m('div.container', [m('h2', '校园分区设置'), m('p', {
      role: 'status'
    }, this.notice), ...this.sectionRows.map(s => m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        this.notice = '';
        try {
          await app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/campus/sections/' + s.id,
            body: {
              name: s.name,
              description: s.description,
              position: Number(s.position),
              is_open: Boolean(s.is_open),
              visibility: s.visibility
            }
          });
          this.notice = '已保存';
        } catch (_) {
          this.notice = '保存失败，请检查字段与权限';
        }
        m.redraw();
      }
    }, [m('label', ['名称', m('input.FormControl', {
      value: s.name,
      oninput: e => s.name = e.target.value
    })]), m('label', ['简介', m('input.FormControl', {
      value: s.description,
      oninput: e => s.description = e.target.value
    })]), m('label', ['顺序', m('input.FormControl', {
      type: 'number',
      min: 0,
      value: s.position,
      oninput: e => s.position = e.target.value
    })]), m('label', ['访问范围', m('select.FormControl', {
      value: s.visibility,
      onchange: e => s.visibility = e.target.value
    }, [['public', '公开'], ['members', '登录成员'], ['verified', '校内验证成员']].map(([value, text]) => m('option', {
      value
    }, text)))]), m('label', [m('input', {
      type: 'checkbox',
      checked: s.is_open,
      onchange: e => s.is_open = e.target.checked
    }), ' 开放分区']), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '保存')])), ...this.governance()]);
  }
}
app.initializers.add('ucasser-campus', () => {
  app.registry.for('ucasser-campus').registerPage(CampusAdmin);
});

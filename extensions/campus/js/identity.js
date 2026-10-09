import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
export default class IdentityPage extends Page {
  oninit(v) {
    super.oninit(v);
    this.notice = '';
    this.evidence = '';
    this.consent = false;
    this.load();
  }
  async load() {
    this.loading = true;
    try {
      this.data = (await app.request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/campus/identity'
      })).data;
    } catch (_) {
      this.notice = '请登录后查看身份与权限。';
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
  view() {
    return m('div.Campus.container', [m('h1', '校园身份与权限'), m('p', {
      role: 'status'
    }, this.notice), this.loading ? m('p', '正在加载…') : this.data ? [m('p', this.data.verified ? '身份状态：已通过人工审核' : '身份状态：尚未通过校园审核'), m('p', '只用于决定校内分区访问权限。请提交最少必要说明，不要填写身份证号、学号、密码或上传证件。材料仅管理员可见，审核后删除原始说明。'), !this.data.verified ? m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await app.request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/campus/identity',
            body: {
              evidence: this.evidence,
              consent: this.consent
            }
          });
          this.notice = '申请已提交，等待人工审核。';
          this.evidence = '';
          await this.load();
        } catch (_) {
          this.notice = '提交失败，请检查同意选项、说明长度及是否已有待审申请。';
        }
        m.redraw();
      }
    }, [m('label', ['验证说明', m('textarea.FormControl', {
      value: this.evidence,
      minlength: 5,
      maxlength: 1000,
      required: true,
      oninput: e => this.evidence = e.target.value
    })]), m('label', [m('input', {
      type: 'checkbox',
      checked: this.consent,
      required: true,
      onchange: e => this.consent = e.target.checked
    }), ' 我同意上述用途与材料处理方式']), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '提交人工验证')]) : null, m('h2', '我的申请'), this.data.requests.length ? m('ul', this.data.requests.map(r => m('li', `${r.status} · ${r.created_at} · ${r.reason || '等待处理'}`))) : m('p', '暂无申请。'), m('p', '授权分区编号：' + (this.data.moderationSections.join('、') || '无')), m('p', m('a', {
      href: app.forum.attribute('baseUrl') + '/settings'
    }, '前往账号设置：密码、通知与个人数据申请')), this.data.moderationSections.length ? m('a', {
      href: app.forum.attribute('baseUrl') + '/campus/moderation'
    }, '前往分区内容维护') : null] : null]);
  }
}

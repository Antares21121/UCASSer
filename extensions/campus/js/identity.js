import Button from './button';
import { DataPage, api, field, link, pageHeading, emptyState, stateLabels, formatTime } from './ui';
export default class IdentityPage extends DataPage {
  requiresLogin = true;
  async fetch() {
    this.data = (await api('identity')).data;
    this.sections = (await api('catalog')).data.sections;
  }
  content() {
    const pending = this.data.requests.some(r => r.status === 'pending');
    return [pageHeading('校园身份与权限', '验证只用于决定校内分区的访问范围。提交最少必要说明，审核后删除原始材料。', link('/settings', '账号与隐私设置', {
      className: 'Button'
    })), m('section.Campus-panel', [m('div.Campus-badges', [m('span.Campus-badge', {
      className: this.data.verified ? 'is-approved' : ''
    }, this.data.verified ? '已通过人工审核' : pending ? '申请审核中' : '尚未通过校园审核')]), m('p', '请勿填写身份证号、学号、密码或上传证件。验证材料仅获授权的运营人员可见；审核理由不应包含个人材料。'), pending ? m('p.Campus-hint', '申请已提交，请等待人工审核。无需重复申请，可在下方查看结果。') : !this.data.verified ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.perform(() => api('identity', 'POST', {
          evidence: this.evidence || '',
          consent: this.consent === true
        }), {
          message: '申请已提交，等待人工审核。',
          after: () => {
            this.evidence = '';
            this.consent = false;
          }
        });
      }
    }, [field('evidence', '验证说明', this.evidence, v => this.evidence = v, {
      textarea: true,
      minlength: 5,
      maxlength: 1000,
      required: true
    }), m('label', [m('input', {
      type: 'checkbox',
      checked: this.consent,
      required: true,
      onchange: e => this.consent = e.target.checked
    }), ' 我同意上述用途与材料处理方式']), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '提交人工验证')]) : null]), m('section.Campus-panel', [m('h2', '我的申请'), this.data.requests.length ? m('ul.Campus-timeline', this.data.requests.map(r => m('li', [m('strong', stateLabels[r.status] || r.status), m('small', formatTime(r.created_at)), m('p', r.reason || '等待处理')]))) : emptyState('暂无申请', '公开分区可以直接浏览，需要访问校内分区时再提交申请。')]), m('section.Campus-panel', [m('h2', '分区维护授权'), this.data.moderationSections.length ? [m('p', this.data.moderationSections.map(id => this.sections.find(s => s.id === id)?.name || '分区 #' + id).join('、')), link('/campus/moderation', '前往分区内容维护', {
      className: 'Button'
    }), link('/campus/cases', '查看举报与处理记录')] : m('p.Campus-hint', '当前没有分区维护授权。校园身份验证不等于获得管理权限。')])];
  }
}

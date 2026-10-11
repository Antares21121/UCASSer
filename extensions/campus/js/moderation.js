import app from 'flarum/forum/app';
import Button from './button';
import { DataPage, api, field, link, pageHeading } from './ui';
export default class ModerationPage extends DataPage {
  requiresLogin = true;
  async fetch() {
    this.data = (await api('identity')).data;
    if (!app.session.user.isAdmin() && !this.data.moderationSections.length) throw {
      status: 403
    };
  }
  content() {
    return [pageHeading('分区内容维护', '仅限被授权分区。隐藏与恢复操作需要说明理由，并保留审计记录。', link('/campus/cases', '前往举报处理', {
      className: 'Button'
    })), m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        this.perform(() => api('moderate', 'POST', {
          discussion_id: Number(this.id),
          action: this.action || 'hide',
          reason: this.reason || ''
        }), {
          message: '操作已完成并记录理由。',
          refresh: false
        });
      }
    }, [field('discussion_id', '讨论编号', this.id, v => this.id = v, {
      type: 'number',
      min: 1,
      required: true
    }), m('label.Campus-field', ['操作', m('select.FormControl', {
      value: this.action || 'hide',
      onchange: e => this.action = e.target.value
    }, [m('option', {
      value: 'hide'
    }, '隐藏'), m('option', {
      value: 'restore'
    }, '恢复')])]), field('reason', '处理理由', this.reason, v => this.reason = v, {
      textarea: true,
      minlength: 5,
      maxlength: 500,
      required: true
    }), m('div.Campus-actions', [m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '执行并记录'), link('/campus/identity', '查看我的授权')])])];
  }
}

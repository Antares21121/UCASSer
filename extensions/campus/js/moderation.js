import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
export default class ModerationPage extends Page {
  oninit(v) {
    super.oninit(v);
    this.notice = '';
    this.id = '';
    this.reason = '';
    this.action = 'hide';
  }
  view() {
    return m('div.Campus.container', [m('h1', '分区内容维护'), m('p', '仅限被授权分区。所有隐藏与恢复操作必须说明理由，并留下审计记录。'), m('p', {
      role: 'status'
    }, this.notice), m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await app.request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/campus/moderate',
            body: {
              discussion_id: Number(this.id),
              action: this.action,
              reason: this.reason
            }
          });
          this.notice = '操作已完成并记录理由。';
        } catch (_) {
          this.notice = '操作失败，请确认讨论编号、授权分区与理由。';
        }
        m.redraw();
      }
    }, [m('label', ['讨论编号', m('input.FormControl', {
      type: 'number',
      min: 1,
      required: true,
      value: this.id,
      oninput: e => this.id = e.target.value
    })]), m('label', ['操作', m('select.FormControl', {
      value: this.action,
      onchange: e => this.action = e.target.value
    }, [m('option', {
      value: 'hide'
    }, '隐藏'), m('option', {
      value: 'restore'
    }, '恢复')])]), m('label', ['处理理由', m('textarea.FormControl', {
      minlength: 5,
      maxlength: 500,
      required: true,
      value: this.reason,
      oninput: e => this.reason = e.target.value
    })]), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '执行并记录')])]);
  }
}

import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import PostControls from 'flarum/forum/utils/PostControls';
import { extend } from 'flarum/common/extend';
import { DataPage, api, field, link } from './records';
export class CasesPage extends DataPage {
  async fetch() {
    this.id = m.route.param('id');
    const result = await api(this.id ? 'cases/' + this.id : 'cases?page=' + (m.route.param('page') || 1));
    this.cases = this.id ? [result.data] : result.data;
    this.page = Number(m.route.param('page') || 1);
    if (!this.form) this.form = {
      discussion_id: m.route.param('discussion_id') || '',
      target_type: m.route.param('target_type') || 'discussion',
      target_id: m.route.param('target_id') || '',
      reason: ''
    };
  }
  async process(c, kind, body) {
    try {
      await api('cases/' + c.id + (kind ? '/' + kind : ''), kind ? 'POST' : 'PATCH', {
        version: c.version,
        ...body
      });
      this.notice = '状态已更新并记录处理过程';
      await this.fetch();
    } catch (_) {
      this.notice = '操作失败，请检查权限、处理理由、状态和版本。申诉须由独立人员复核。';
    }
    m.redraw();
  }
  content() {
    return [m('h1', '举报、处理与申诉'), m('p', '举报材料仅本人和获授权处理人员可见。当事人可查看处理结果并申诉；独立复核人员处理申诉。请勿在处理结果中引用举报者隐私。'), m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await api('cases', 'POST', {
            ...this.form,
            discussion_id: Number(this.form.discussion_id),
            target_id: Number(this.form.target_id || this.form.discussion_id)
          });
          this.notice = '举报已提交，可在下方查看进度';
          this.form.reason = '';
          await this.fetch();
        } catch (_) {
          this.notice = '提交失败，请检查讨论上下文、说明或已有进行中的举报。';
        }
        m.redraw();
      }
    }, [m('h2', '提交举报'), field('discussion_id', '讨论编号', this.form.discussion_id, v => this.form.discussion_id = v, {
      type: 'number',
      min: 1,
      required: true
    }), m('label', ['举报对象', m('select.FormControl', {
      value: this.form.target_type,
      onchange: e => this.form.target_type = e.target.value
    }, [['discussion', '讨论'], ['post', '评论'], ['user', '用户行为（需要该讨论上下文）']].map(([value, text]) => m('option', {
      value
    }, text)))]), field('target_id', '对象编号（讨论举报可留空）', this.form.target_id, v => this.form.target_id = v, {
      type: 'number',
      min: 1
    }), field('reason', '举报原因与必要证据', this.form.reason, v => this.form.reason = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 2000
    }), m(Button, {
      type: 'submit'
    }, '提交举报')]), ...this.cases.map(c => m('article.Campus-panel', [m('h2', '处理记录 #' + c.id), m('p', `${c.target_type} #${c.target_id} · ${c.status} · 更新 ${c.updated_at} UTC`), c.reason ? m('p.Campus-text', '举报说明：' + c.reason) : null, m('p.Campus-text', '处理结果：' + (c.resolution || '等待处理')), link('/d/' + c.discussion_id, '查看讨论（须有内容访问权限）'), m('ul', c.events.map(ev => m('li', `${ev.created_at} · ${ev.action} · ${ev.message}`))), c.can_manage && ['pending', 'in_progress', 'needs_info', 'appealed'].includes(c.status) && (c.status !== 'appealed' || c.can_review) ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.process(c, '', {
          status: c.nextStatus || (c.status === 'appealed' ? 'reviewed' : 'resolved'),
          action: c.action || 'none',
          reason: c.note || '',
          confirmed: c.confirmed === true,
          until: c.until ? new Date(c.until).toISOString() : null
        });
      }
    }, [m('h3', '处理或复核'), m('select.FormControl', {
      onchange: e => c.nextStatus = e.target.value
    }, (c.status === 'appealed' ? ['reviewed'] : ['resolved', 'in_progress', 'needs_info', 'rejected']).map(value => m('option', {
      value
    }, value))), m('select.FormControl', {
      onchange: e => c.action = e.target.value
    }, [['none', '记录说明或要求修改'], ['hide', '隐藏对象'], ['restore', '恢复对象'], ['delete', '永久删除（仅管理员）'], ['suspend', '限制发布 / 临时或长期封禁（仅管理员）'], ['unban', '解除封禁（仅管理员）']].map(([value, text]) => m('option', {
      value
    }, text))), field('until', '封禁截止时间（管理员操作）', c.until, v => c.until = v, {
      type: 'datetime-local'
    }), field('resolution', '公开给当事人的处理理由', c.note, v => c.note = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500
    }), m('label', [m('input', {
      type: 'checkbox',
      checked: c.confirmed,
      onchange: e => c.confirmed = e.target.checked
    }), ' 明确确认永久删除（仅选择该操作时需要）']), m(Button, {
      type: 'submit'
    }, '记录并执行处置')]) : null, c.can_appeal ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.process(c, 'appeal', {
          reason: c.appeal || ''
        });
      }
    }, [field('appeal', '申诉说明', c.appeal, v => c.appeal = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit'
    }, '提交申诉')]) : null, ['pending', 'needs_info'].includes(c.status) && c.reason ? m('form', {
      onsubmit: e => {
        e.preventDefault();
        this.process(c, 'supplement', {
          reason: c.supplement || ''
        });
      }
    }, [field('supplement', '补充举报说明', c.supplement, v => c.supplement = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit'
    }, '补充材料')]) : null])), this.cases.length ? null : m('p.Campus-empty', '暂无可查看的处理记录。'), m('nav.Campus-nav', [this.page > 1 ? link('/campus/cases?page=' + (this.page - 1), '上一页') : null, this.cases.length === 20 ? link('/campus/cases?page=' + (this.page + 1), '下一页') : null])];
  }
}
export function registerReports() {
  extend(PostControls, 'userControls', function (items, post) {
    if (!app.session.user) return;
    items.add('campus-case', m(Button, {
      icon: 'fas fa-flag',
      onclick: () => m.route.set('/campus/cases', {
        discussion_id: post.discussion().id(),
        target_type: 'post',
        target_id: post.id()
      })
    }, '举报并跟踪处理'), 1);
  });
}

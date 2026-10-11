import app from 'flarum/forum/app';
import Button from './button';
import { DataPage, api, field, link, labels } from './records';
import { pageHeading, stateLabels, formatTime } from './ui';
export default class GovernancePage extends DataPage {
  async fetch() {
    this.data = (await api('governance?page=' + (this.params.page || 1))).data;
  }
  content() {
    const d = this.data;
    return [pageHeading('公开治理与志愿维护', '规则、职责和维护记录公开可查。志愿授权只覆盖必要范围。'), d.development ? m('p.Campus-hint', '当前为开发环境，示例内容和开发验收记录计入真实数据库统计。') : null, m('section.Campus-panel', [m('h2', '平台运行数据'), m('div.Campus-stats', Object.entries(d.statistics).map(([k, v]) => m('div.Campus-stat', [m('strong', v), m('span', labels[k] || '已解决问答')]))), m('p', '已关闭举报：' + (d.reports.closed === null ? '样本不足，暂不展示' : d.reports.closed + ' 条')), m('p.Campus-hint', d.reports.note)]), m('section.Campus-panel', [m('h2', '分区维护职责'), m('p', '信息整理维护来源与期限；资料维护核验链接与授权；分区版主处理举报与秩序；技术维护通过代码、部署和备份协作；社区协调处理规则与跨分区事项。上述职责可以由同一人员承担，授权只覆盖必要范围。'), m('ul.Campus-list', d.team.map(t => m('li', `${t.section}：当前 ${t.volunteers} 项志愿维护授权`))), link('/campus/cases', '提交举报与查看申诉进度')]), ...d.entries.map(e => m('article.Campus-panel', [m('div.Campus-badges', [m('span.Campus-badge', stateLabels[e.type] || e.type)]), m('h2', e.title), m('small', `版本 #${e.id} · 生效 ${formatTime(e.effective_at)}（北京时间）`), m('p.Campus-text', e.body)])), d.entries.length ? null : m('p.Campus-empty', '暂无已生效的公开治理记录。'), m('nav.Campus-pagination', {
      'aria-label': '治理记录分页'
    }, [d.page > 1 ? link('/campus/governance?page=' + (d.page - 1), '上一页') : null, d.entries.length === 20 ? link('/campus/governance?page=' + (d.page + 1), '下一页') : null]), d.can_publish ? m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        this.perform(() => api('governance', 'POST', {
          type: this.type || 'maintenance',
          title: this.title || '',
          body: this.body || '',
          effective_at: this.effective || ''
        }), {
          message: '公开版本已发布',
          after: () => {
            this.title = '';
            this.body = '';
            this.effective = '';
          }
        });
      }
    }, [m('h2', '发布新治理版本'), m('p', '这些内容完全公开，禁止包含个人材料或举报者身份。规则更新会保留旧版本。'), m('select.FormControl', {
      'aria-label': '治理记录类型',
      onchange: e => this.type = e.target.value
    }, [['maintenance', '运行维护公告'], ['rule', '社区公约新版本'], ['team', '职责与交接说明'], ['decision', '治理决定摘要'], ['recruit', '志愿者招募与参与方式']].map(([value, text]) => m('option', {
      value
    }, text))), field('title', '公开标题', this.title, v => this.title = v, {
      required: true,
      minlength: 3,
      maxlength: 80
    }), field('body', '公开正文', this.body, v => this.body = v, {
      textarea: true,
      required: true,
      minlength: 5,
      maxlength: 30000
    }), field('effective_at', '生效时间', this.effective, v => this.effective = v, {
      type: 'datetime-local',
      utc: true,
      required: true
    }), m(Button, {
      type: 'submit'
    }, '发布并保留版本')]) : null];
  }
}

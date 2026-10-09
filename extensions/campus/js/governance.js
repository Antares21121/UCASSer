import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import { DataPage, api, field, link, labels } from './records';
export default class GovernancePage extends DataPage {
  async fetch() {
    this.data = (await api('governance?page=' + (m.route.param('page') || 1))).data;
  }
  content() {
    const d = this.data;
    return [m('h1', '公开治理与志愿维护'), d.development ? m('p', '当前为开发环境，示例内容和开发验收记录计入真实数据库统计。') : null, m('section.Campus-panel', [m('h2', '平台运行数据'), Object.entries(d.statistics).map(([k, v]) => m('p', (labels[k] || '已解决问答') + '：' + v + ' 条')), m('p', '已关闭举报：' + (d.reports.closed === null ? '样本不足，暂不展示' : d.reports.closed + ' 条')), m('p', d.reports.note)]), m('section.Campus-panel', [m('h2', '分区维护职责'), m('p', '信息整理维护来源与期限；资料维护核验链接与授权；分区版主处理举报与秩序；技术维护通过代码、部署和备份协作；社区协调处理规则与跨分区事项。上述职责可以由同一人员承担，授权只覆盖必要范围。'), m('ul', d.team.map(t => m('li', `${t.section}：当前 ${t.volunteers} 项志愿维护授权`))), link('/campus/cases', '提交举报与查看申诉进度')]), ...d.entries.map(e => m('article.Campus-panel', [m('h2', e.title), m('small', `版本 #${e.id} · ${e.type} · 生效 ${e.effective_at} UTC`), m('p.Campus-text', e.body)])), d.entries.length ? null : m('p', '暂无已生效的公开治理记录。'), m('nav.Campus-nav', [d.page > 1 ? link('/campus/governance?page=' + (d.page - 1), '上一页') : null, d.entries.length === 20 ? link('/campus/governance?page=' + (d.page + 1), '下一页') : null]), d.can_publish ? m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await api('governance', 'POST', {
            type: this.type || 'maintenance',
            title: this.title || '',
            body: this.body || '',
            effective_at: this.effective || ''
          });
          this.notice = '公开版本已发布';
          await this.fetch();
        } catch (_) {
          this.notice = '发布失败，请检查字段、UTC 生效时间与管理员权限。';
        }
        m.redraw();
      }
    }, [m('h2', '发布新治理版本'), m('p', '这些内容完全公开，禁止包含个人材料或举报者身份。规则更新会保留旧版本。'), m('select.FormControl', {
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
      minlength: 5
    }), field('effective_at', '生效时间（UTC）', this.effective, v => this.effective = v, {
      type: 'datetime-local',
      required: true
    }), m(Button, {
      type: 'submit'
    }, '发布并保留版本')]) : null];
  }
}

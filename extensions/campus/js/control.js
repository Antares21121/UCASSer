import app from 'flarum/forum/app';
import Button from './button';
import { DataPage, api, formatTime, link, symbol } from './ui';

const workLink = (href, title, detail, count, icon, external = false) => {
  const content = [m('span.Campus-controlWorkIcon', symbol(icon)), m('span.Campus-controlWorkText', [m('strong', title), m('small', detail)]),
    m('span.Campus-controlWorkCount', String(count)), symbol(external ? 'external-link-alt' : 'arrow-right')];
  return m('li', { key: href }, external ? m('a.Campus-controlWorkLink', { href }, content) : link(href, content, { className: 'Campus-controlWorkLink' }));
};

const moduleLink = (href, title, detail, icon, external = false) => m('li', { key: href },
  external ? m('a.Campus-controlModule', { href }, [symbol(icon), m('span', [m('strong', title), m('small', detail)]), symbol('external-link-alt')])
    : link(href, [symbol(icon), m('span', [m('strong', title), m('small', detail)]), symbol('arrow-right')], { className: 'Campus-controlModule' })
);

export default class ControlPage extends DataPage {
  requiresLogin = true;

  async fetch() {
    this.data = (await api('control')).data;
  }

  toggleSource(source) {
    const next = !source.enabled;
    this.perform(() => api('aggregation/sources/' + source.id, 'PATCH', { enabled: next }), {
      message: next ? '网页来源已恢复，下一次定时任务会尝试抓取。' : '网页来源已暂停，不再参与定时抓取。'
    });
  }

  content() {
    const d = this.data;
    const adminRoot = (app.forum.attribute('baseUrl') || '').replace(/\/$/, '') + '/admin';
    const adminUrl = adminRoot + '#/extension/ucasser-campus';
    return m('div.Campus-control', [
      m('header.Campus-controlHeader', [
        m('div', [m('h1', '站点控制台'), m('p', '从一处查看需要处理的事，再进入对应模块。所有操作均保留原有权限与审核流程。')]),
        m(Button, { onclick: () => this.load(), disabled: this.loading || this.busy }, [symbol('sync-alt'), ' 刷新状态'])
      ]),
      m('p.Campus-controlTimestamp', '数据更新于 ' + formatTime(d.generated_at) + ' · 显示的是数据库记录，不代表服务器或外部订阅服务正在运行。'),
      m('div.Campus-controlLayout', [
        m('main.Campus-controlMain', [
          m('section.Campus-controlSection', [
            m('div.Campus-controlSectionHead', [m('h2', '现在需要处理'), m('span', '按待办进入工作页面')]),
            m('ul.Campus-controlWork', [
              workLink('/campus/aggregation/review', '汇集信息审核', '核对来源、合并重复内容', d.work.aggregation_pending, 'newspaper'),
              workLink(adminUrl + '?tab=identity', '校内身份审核', '处理新提交的身份材料', d.work.identity_pending, 'user-check', true),
              workLink('/campus/cases?status=open', '举报与申诉', '查看未结案件和待复核申诉', d.work.cases_pending, 'flag')
            ])
          ]),
          m('section.Campus-controlSection', [
            m('div.Campus-controlSectionHead', [m('h2', '内容与服务'), m('span', '直达日常维护入口')]),
            m('ul.Campus-controlModules', [
              moduleLink('/campus/aggregation/sources', '信息来源', '登记网页与公众号，查看抓取错误', 'rss'),
              moduleLink('/campus/aggregation', '聚合信息流', d.content.aggregation_published + ' 条已发布信息', 'stream'),
              moduleLink('/resources', '资料目录', '维护来源链接与分类', 'folder-open'),
              moduleLink('/campus/courses', '课程目录', d.content.courses + ' 门课程', 'book-open'),
              moduleLink('/campus/tools', '校园工具箱', '校历、食堂、课表与出行入口', 'th-large'),
              moduleLink(adminUrl, '校园模块设置', '分区、分类、身份授权与操作审计', 'sliders-h', true),
              moduleLink(adminRoot, '全站后台', '网站基础设置、用户与其他扩展', 'cog', true)
            ])
          ])
        ]),
        m('aside.Campus-controlAside', [
          m('section.Campus-controlSummary', [
            m('h2', '站点概况'),
            m('dl', [
              m('div', [m('dt', '用户'), m('dd', d.community.users)]),
              m('div', [m('dt', '讨论'), m('dd', d.community.discussions)]),
              m('div', [m('dt', '校园内容'), m('dd', d.content.records)]),
              m('div', [m('dt', '分区'), m('dd', d.community.sections)])
            ])
          ]),
          m('section.Campus-controlSources', [
            m('div.Campus-controlAsideHead', [m('h2', '采集来源'), link('/campus/aggregation/sources', '查看全部')]),
            m('p.Campus-controlSourceSummary', `${d.sources.total} 个来源 · ${d.sources.active} 个站内抓取已开启 · ${d.sources.errors} 个报错`),
            d.sources.recent.length ? m('ul', d.sources.recent.map(source => m('li', { key: source.id }, [
              m('div.Campus-controlSourceName', [m('strong', source.name), m('span', source.adapter === 'ucass-notices' ? source.enabled ? '已启用' : '已暂停' : '外部接入')]),
              source.last_error ? m('small.Campus-controlSourceError', source.last_error) : m('small', source.last_checked_at ? '上次检查 ' + formatTime(source.last_checked_at) : '尚未由本站检查'),
              source.adapter === 'ucass-notices' ? m(Button, { onclick: () => this.toggleSource(source), disabled: this.busy }, source.enabled ? '暂停抓取' : '恢复抓取') : null
            ]))) : m('p', '还没有登记来源。')
          ]),
          m('p.Campus-controlFootnote', '定时运行和外部公众号授权需在部署环境配置；这里不会替代服务器状态监控。')
        ])
      ])
    ]);
  }
}

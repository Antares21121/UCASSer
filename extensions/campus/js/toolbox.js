import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import { link, pageHeading, shell, symbol } from './ui';

// Keep the navigation and the future content areas in one place. No schedule,
// menu or guide information is implied until a maintainer adds verified data.
export const tools = [
  { key: 'calendar', title: '日历', icon: 'calendar-alt', description: '查看校园活动与重要日期。', empty: '活动与日期尚未录入。' },
  { key: 'dining', title: '食堂菜单', icon: 'utensils', description: '查看食堂餐次、菜品和更新时间。', empty: '食堂菜单尚未录入。' },
  { key: 'timetable', title: '课程表', icon: 'table', description: '集中查看课程时间与地点。', empty: '课程安排尚未录入。' },
  { key: 'shuttle', title: '摆渡车时间表', icon: 'bus', description: '查看线路、站点与发车时间。', empty: '摆渡车时刻尚未录入。' },
  { key: 'new-student', title: '新生导引', icon: 'map-signs', description: '按步骤查找报到与校园生活信息。', empty: '新生导引尚未录入。' }
];

const toolForRoute = () => tools.find(tool => tool.key === m.route.param('tool'));

export default class ToolboxPage extends Page {
  bodyClass = 'CampusApp';

  oninit(v) {
    super.oninit(v);
    const today = new Date();
    this.month = new Date(today.getFullYear(), today.getMonth(), 1);
    this.updateTitle();
  }

  onbeforeupdate() {
    this.updateTitle();
    return true;
  }

  updateTitle() {
    app.setTitle(toolForRoute()?.title || '校园工具箱');
  }

  calendar() {
    const year = this.month.getFullYear();
    const month = this.month.getMonth();
    const firstDay = new Date(year, month, 1).getDay();
    const days = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    const cells = [
      ...Array.from({ length: firstDay }, () => null),
      ...Array.from({ length: days }, (_, index) => index + 1)
    ];
    return m('section.Campus-panel.Campus-toolboxCalendar', { 'aria-label': '月历' }, [
      m('div.Campus-toolboxCalendarHeader', [
        m('h2', `${year} 年 ${month + 1} 月`),
        m('div.Campus-toolboxCalendarActions', [
          m('button.Button', { type: 'button', 'aria-label': '上个月', onclick: () => this.month = new Date(year, month - 1, 1) }, symbol('chevron-left')),
          m('button.Button', { type: 'button', 'aria-label': '回到本月', onclick: () => this.month = new Date(today.getFullYear(), today.getMonth(), 1) }, '本月'),
          m('button.Button', { type: 'button', 'aria-label': '下个月', onclick: () => this.month = new Date(year, month + 1, 1) }, symbol('chevron-right'))
        ])
      ]),
      m('div.Campus-toolboxCalendarGrid', { 'aria-label': `${year} 年 ${month + 1} 月` }, [
        ['日', '一', '二', '三', '四', '五', '六'].map(day => m('span.Campus-toolboxWeekday', day)),
        cells.map(day => day ? m('time.Campus-toolboxDay', {
          datetime: `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
          'aria-current': day === today.getDate() && year === today.getFullYear() && month === today.getMonth() ? 'date' : undefined,
          className: day === today.getDate() && year === today.getFullYear() && month === today.getMonth() ? 'is-today' : ''
        }, day) : m('span.Campus-toolboxDay', { 'aria-hidden': 'true' }))
      ]),
      m('p.Campus-hint', '校园活动与重要日期尚未录入。日期展示不代表学校日程。')
    ]);
  }

  view() {
    const selected = toolForRoute();
    if (m.route.param('tool') && !selected) {
      return shell(this, [pageHeading('工具暂未找到', '这个工具尚未加入工具箱。'), link('/campus/tools', '返回校园工具箱')]);
    }

    if (selected) {
      return shell(this, m('div.Campus-toolbox', [
        m('nav.Campus-toolboxBack', { 'aria-label': '工具箱导航' }, link('/campus/tools', '返回校园工具箱')),
        pageHeading(selected.title, selected.description),
        selected.key === 'calendar' ? this.calendar() : m('section.Campus-panel.Campus-toolboxEmpty', { 'aria-label': selected.title + '内容' }, [
          symbol(selected.icon),
          m('h2', selected.empty),
          m('p', '此页正在准备中；请以学校或有关部门的正式通知为准。')
        ])
      ]));
    }

    return shell(this, m('div.Campus-toolbox', [
      pageHeading('校园工具箱', '把常用校园信息放在一个地方。具体内容将逐步补充。'),
      m('nav.Campus-toolboxList', { 'aria-label': '校园工具' }, tools.map(tool =>
        link('/campus/tools/' + tool.key, [
          m('span.Campus-toolboxIcon', symbol(tool.icon)),
          m('span.Campus-toolboxText', [m('strong', tool.title), m('small', tool.description)]),
          m('span.Campus-toolboxStatus', '内容待录入'),
          m('span.Campus-toolboxArrow', symbol('chevron-right'))
        ], { className: 'Campus-toolboxItem', key: tool.key })
      ))
    ]));
  }
}

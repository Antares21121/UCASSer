import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import { link, pageHeading, shell, symbol } from './ui';

// Link to source pages without copying their content. Schedules and dates are
// only shown here once a maintainer has verified a current, reusable source.
export const tools = [
  {
    key: 'calendar', title: '日历', icon: 'calendar-alt', description: '按月浏览日期，并查阅学校校历。', status: '查看官方来源',
    source: { label: '查看历史校历栏目', url: 'https://jw.ucass.edu.cn/xnxl.htm', owner: '中国社会科学院大学教务处', note: '公开栏目仅列旧学年。现行电子校历请本人登录校园门户，在“学年校历”微应用中查询。', secondary: { label: '登录校园门户', url: 'https://info.ucass.edu.cn/' } }
  },
  { key: 'dining', title: '食堂菜单', icon: 'utensils', description: '查看食堂餐次、菜品和更新时间。', empty: '食堂菜单尚未录入。' },
  { key: 'timetable', title: '课程表', icon: 'table', description: '先查课程目录；上课时间与地点待核实。', empty: '课程安排尚未录入。' },
  {
    key: 'shuttle', title: '摆渡车时间表', icon: 'bus', description: '等待核实当前线路和时刻。', empty: '尚无可核实的当前摆渡车时刻表。', status: '时刻待核实',
    source: { label: '查看学校迎新交通说明', url: 'https://welcome.ucass.edu.cn/info/1003/1264.htm', owner: '中国社会科学院大学迎新网', note: '这份通知仅说明 2026 级新生报到时的接驳安排，不代表日常班次。出行前请向学校核实。' }
  },
  {
    key: 'new-student', title: '新生导引', icon: 'map-signs', description: '从学校迎新网查找报到与入学通知。', empty: '站内新生导引尚未录入。', status: '查看迎新网',
    source: { label: '打开学校迎新网', url: 'https://welcome.ucass.edu.cn/', owner: '中国社会科学院大学迎新网', note: '报到时间、手续和校区安排可能按年变化，请以迎新网最新通知为准。' }
  },
  {
    key: 'lecture-index', title: '讲座索引', icon: 'chalkboard-teacher', description: '查看独立维护的社科大讲座汇。', empty: '讲座内容由外部站点维护。', status: '外部索引',
    source: { label: '打开社科大讲座汇', url: 'https://muxin-ucass.github.io/LECTURE-INDEX/', owner: 'muxin-ucass · 独立站点', note: '在新标签页打开；本站不复制其内容。活动时间和地点请再向原主办方核实。' }
  }
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

  source(tool) {
    if (!tool.source) return null;
    const { label, url, owner, note, secondary } = tool.source;
    return m('section.Campus-panel.Campus-toolboxSource', { 'aria-label': tool.title + '来源' }, [
      m('div.Campus-toolboxSourceCopy', [m('h2', owner), m('p', note)]),
      m('div.Campus-toolboxSourceActions', [
        m('a.Button.Button--primary', { href: url, target: '_blank', rel: 'noopener noreferrer nofollow', 'aria-label': label + '（新标签页）' }, [label, symbol('external-link-alt')]),
        secondary ? m('a.Button', { href: secondary.url, target: '_blank', rel: 'noopener noreferrer nofollow', 'aria-label': secondary.label + '（新标签页）' }, [secondary.label, symbol('external-link-alt')]) : null
      ])
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
          m('p', selected.source ? '可从下方来源页面继续查找，使用前请核对发布时间和适用范围。' : '此页正在准备中；请以学校或有关部门的正式通知为准。'),
          selected.key === 'timetable' ? link('/campus/courses', '浏览课程目录', { className: 'Button' }) : null
        ]),
        this.source(selected)
      ]));
    }

    return shell(this, m('div.Campus-toolbox', [
      pageHeading('校园工具箱', '把常用校园信息放在一个地方。具体内容将逐步补充。'),
      m('nav.Campus-toolboxList', { 'aria-label': '校园工具' }, tools.map(tool =>
        link('/campus/tools/' + tool.key, [
          m('span.Campus-toolboxIcon', symbol(tool.icon)),
          m('span.Campus-toolboxText', [m('strong', tool.title), m('small', tool.description)]),
          m('span.Campus-toolboxStatus', tool.status || '内容待录入'),
          m('span.Campus-toolboxArrow', symbol('chevron-right'))
        ], { className: 'Campus-toolboxItem', key: tool.key })
      ))
    ]));
  }
}

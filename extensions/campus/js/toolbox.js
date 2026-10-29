import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import { link, pageHeading, shell, symbol } from './ui';

const WEEKDAYS = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'];

// Reference data ships with the extension: a schedule export, the two semester
// calendars published by the academic affairs office, and the shuttle table.
const loadData = () => app.cache.campusTools || (app.cache.campusTools = app
  .request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/campus/tools/data' })
  .then(response => response.data));

export const tools = [
  { key: 'calendar', title: '校历', icon: 'calendar-alt', description: '按学期查看周次、放假调休与考试周。' },
  { key: 'timetable', title: '课程表', icon: 'table', description: '按周次查看全校开课时间、教室与任课教师。' },
  {
    key: 'shuttle', title: '摆渡车时间表', icon: 'bus', description: '学校与地铁站之间的班次时刻。',
    source: { label: '查看学校迎新交通说明', url: 'https://welcome.ucass.edu.cn/info/1003/1264.htm', owner: '中国社会科学院大学迎新网', note: '节假日与临时调整以学校通知和现场调度为准。' }
  },
  { key: 'dining', title: '食堂菜单', icon: 'utensils', description: '查看食堂餐次、菜品和更新时间。', empty: '食堂菜单尚未录入。' },
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
const WEEK_RE = /^(\d+)\s*[-–~]\s*(\d+)$/;

function slotMatchesWeek(entry, week) {
  if (!week) return true;
  const match = WEEK_RE.exec(entry.weeks || '');
  if (!match) return true;
  const from = Number(match[1]);
  const to = Number(match[2]);
  if (week < from || week > to) return false;
  if (entry.parity === '单周' && week % 2 === 0) return false;
  if (entry.parity === '双周' && week % 2 === 1) return false;
  return true;
}

function isoDate(date) {
  return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
}

function addDays(date, days) {
  const copy = new Date(date.getTime());
  copy.setDate(copy.getDate() + days);
  return copy;
}

function shortDate(date) {
  return (date.getMonth() + 1) + '月' + date.getDate() + '日';
}

function weekRange(semester, week) {
  const start = addDays(new Date(semester.start + 'T00:00:00'), (week - 1) * 7);
  return { start, end: addDays(start, 6) };
}

export default class ToolboxPage extends Page {
  bodyClass = 'CampusApp';

  oninit() {
    this.data = null;
    this.failed = false;
    this.week = null;
    this.query = '';
    this.unit = '';
    this.calendarId = null;
    const today = new Date();
    loadData().then(data => {
      this.data = data;
      const semester = data?.calendars?.semesters?.[0];
      if (semester && !this.calendarId) this.calendarId = semester.id;
      if (!this.week) this.week = this.currentWeek();
      m.redraw();
    }).catch(() => {
      this.failed = true;
      m.redraw();
    });
    this.updateTitle();
  }

  currentWeek() {
    const semester = this.data?.calendars?.semesters?.[0];
    if (!semester) return null;
    const start = new Date(semester.start + 'T00:00:00');
    const diff = Math.floor((Date.now() - start.getTime()) / 86400000);
    if (diff < 0) return null;
    const week = Math.floor(diff / 7) + 1;
    return week <= semester.weeks ? week : null;
  }

  onbeforeupdate() {
    this.updateTitle();
    return true;
  }

  updateTitle() {
    app.setTitle(toolForRoute()?.title || '校园工具箱');
  }

  loading(what) {
    return m('section.Campus-panel.Campus-toolboxEmpty', { 'aria-label': what + '载入中' }, [
      symbol('circle-notch'),
      m('h2', this.failed ? what + '载入失败' : '正在载入' + what + '…'),
      m('p', this.failed ? '请刷新页面重试；若持续失败，请检查 /api/campus/tools/data。' : '数据随扩展一起提供，只需载入一次。')
    ]);
  }

  /* ---------------------------- 课程表 ---------------------------- */

  timetable() {
    const data = this.data?.timetable;
    if (!data) return this.loading('课程表');
    const query = this.query.trim().toLowerCase();
    const entries = data.entries.filter(entry => {
      if (this.unit && entry.unit !== this.unit) return false;
      if (!slotMatchesWeek(entry, this.week)) return false;
      if (!query) return true;
      return [entry.course, entry.teacher, entry.room, entry.klass, entry.unit].some(value => value && value.toLowerCase().includes(query));
    });
    const byCell = new Map();
    for (const entry of entries) {
      const key = entry.d + ':' + entry.s;
      if (!byCell.has(key)) byCell.set(key, []);
      byCell.get(key).push(entry);
    }
    const period = entry => (entry.e === entry.s ? '第' + entry.s + '节' : '第' + entry.s + '–' + entry.e + '节');
    const weeks = this.data?.calendars?.semesters?.[0]?.weeks || 18;
    // Only render weekday columns that carry data; Sunday is usually empty and
    // a permanently blank column squeezes the readable ones.
    const days = WEEKDAYS.map((label, index) => ({ label, no: index + 1 })).filter(day => day.no !== 7 || entries.some(entry => entry.d === 7));
    const slotNode = entry => m('article.Campus-ttSlot', { title: [entry.course, entry.teacher, entry.room, entry.weeks + '周'].filter(Boolean).join(' · ') }, [
      m('strong', entry.course),
      m('span', [entry.teacher || '教师待定', ' · ', entry.room || '教室待定']),
      m('small', [period(entry), ' · ', entry.weeks + '周' + (entry.parity ? '（' + entry.parity + '）' : '')])
    ]);
    return m('section.Campus-panel.Campus-toolboxSection', { 'aria-label': '课程表' }, [
      m('div.Campus-ttToolbar', [
        m('label.Campus-ttField', [m('small', '周次'), m('select', {
          onchange: e => { this.week = e.target.value ? Number(e.target.value) : null; }
        }, [m('option', { value: '', selected: !this.week }, '全部周次'), ...Array.from({ length: weeks }, (_, i) => i + 1).map(week =>
          m('option', { value: week, selected: this.week === week }, '第' + week + '周'))])]),
        m('label.Campus-ttField', [m('small', '开课单位'), m('select', {
          onchange: e => { this.unit = e.target.value; }
        }, [m('option', { value: '', selected: !this.unit }, '全部单位'), ...data.units.map(unit =>
          m('option', { value: unit, selected: this.unit === unit }, unit))])]),
        m('label.Campus-ttField.Campus-ttField--grow', [m('small', '搜索'), m('input.FormControl', {
          type: 'search', value: this.query, placeholder: '课程、教师、教室或班级',
          oninput: e => { this.query = e.target.value; }
        })])
      ]),
      m('p.Campus-hint', [
        data.source + '；本学期共 ' + data.entries.length + ' 条排课，当前筛选 ' + entries.length + ' 条。',
        m('br'), '临时调课、停课以教务系统和开课单位通知为准；教师姓名与教室属于公开教学信息。'
      ]),
      m('div.Campus-ttScroll', m('table.Campus-tt', [
        m('thead', m('tr', [m('th', { scope: 'col' }, '节次'), ...days.map(day => m('th', { scope: 'col' }, day.label))])),
        m('tbody', Array.from({ length: data.periods }, (_, index) => index + 1).map(periodNo =>
          m('tr', [
            m('th', { scope: 'row' }, [m('strong', '第' + periodNo + '节')]),
            ...days.map(day => {
              const slots = byCell.get(day.no + ':' + periodNo) || [];
              if (!slots.length) return m('td', m('span.Campus-ttEmpty', { 'aria-hidden': 'true' }, '·'));
              const head = slots.slice(0, 3).map(slotNode);
              return m('td', slots.length > 3
                ? [head, m('details.Campus-ttMore', [m('summary', '+' + (slots.length - 3) + ' 条'), ...slots.slice(3).map(slotNode)])]
                : head);
            })
          ])
        ))
      ]))
    ]);
  }

  /* ---------------------------- 校历 ---------------------------- */

  calendar() {
    const data = this.data?.calendars;
    if (!data) return this.loading('校历');
    const semester = data.semesters.find(item => item.id === this.calendarId) || data.semesters[0];
    const todayIso = isoDate(new Date());
    const markersFor = (start, end) => (semester.markers || []).filter(marker => marker.date >= isoDate(start) && marker.date <= isoDate(end));
    return m('section.Campus-panel.Campus-toolboxSection', { 'aria-label': '校历' }, [
      m('nav.Campus-calTabs', { 'aria-label': '选择学期' }, data.semesters.map(item =>
        m('button.Button', {
          type: 'button', className: this.calendarId === item.id ? 'Button Button--primary' : 'Button',
          'aria-pressed': this.calendarId === item.id,
          onclick: () => { this.calendarId = item.id; }
        }, item.title))),
      m('div.Campus-calWeeks', m('table.Campus-tt', [
        m('thead', m('tr', [m('th', { scope: 'col' }, '周次'), m('th', { scope: 'col' }, '日期'), m('th', { scope: 'col' }, '说明')])),
        m('tbody', Array.from({ length: semester.weeks }, (_, index) => {
          const week = index + 1;
          const range = weekRange(semester, week);
          const current = isoDate(range.start) <= todayIso && todayIso <= isoDate(range.end);
          const markers = markersFor(range.start, range.end);
          return m('tr', { className: current ? 'is-current' : '' }, [
            m('th', { scope: 'row' }, '第' + week + '周'),
            m('td', shortDate(range.start) + ' – ' + shortDate(range.end) + (current ? '（本周）' : '')),
            m('td', markers.length ? markers.map(marker => m('span.Campus-calMarker', marker.label)) : '—')
          ]);
        }))
      ])),
      m('div.Campus-calColumns', [
        m('section.Campus-calNotes', { 'aria-label': '校历说明' }, [
          m('h3', '校历说明'),
          m('ol', semester.notes.map(note => m('li', note)))
        ]),
        m('section.Campus-calEvents', { 'aria-label': '重要日期' }, [
          m('h3', '重要日期'),
          m('ul', semester.events.map(event => m('li', [
            m('time', { datetime: event.date }, event.date),
            m('span', event.label),
            event.detail ? m('small', event.detail) : null
          ])))
        ])
      ]),
      m('p.Campus-hint', ['数据来源：' + data.source + '；如学校调整安排，以教务处最新通知为准。'])
    ]);
  }

  /* -------------------------- 摆渡车 -------------------------- */

  shuttle() {
    const data = this.data?.shuttle;
    if (!data) return this.loading('摆渡车时刻');
    return m('section.Campus-panel.Campus-toolboxSection', { 'aria-label': '摆渡车时间表' }, [
      m('div.Campus-shuttleWrap', m('table.Campus-tt.Campus-shuttle', [
        m('thead', m('tr', [m('th', { scope: 'col' }, '班次'), m('th', { scope: 'col' }, '学校出发'), m('th', { scope: 'col' }, '地铁返回')])),
        m('tbody', data.rows.map((row, index) => row.stop
          ? m('tr.Campus-shuttleStop', { key: 'stop-' + index }, m('td', { colspan: 3 }, '停运'))
          : m('tr', { key: 'trip-' + row.n }, [
            m('th', { scope: 'row' }, '第 ' + row.n + ' 班'),
            m('td', row.depart),
            m('td', row.return)
          ])
        ))
      ])),
      m('p.Campus-hint', [data.note, m('br'), '共 ' + data.rows.filter(row => !row.stop).length + ' 班。'])
    ]);
  }

  source(tool) {
    if (!tool.source) return null;
    const { label, url, owner, note } = tool.source;
    return m('section.Campus-panel.Campus-toolboxSource', { 'aria-label': tool.title + '来源' }, [
      m('div.Campus-toolboxSourceCopy', [m('h2', owner), m('p', note)]),
      m('div.Campus-toolboxSourceActions', [
        m('a.Button.Button--primary', { href: url, target: '_blank', rel: 'noopener noreferrer nofollow', 'aria-label': label + '（新标签页）' }, [label, symbol('external-link-alt')])
      ])
    ]);
  }

  view() {
    const selected = toolForRoute();
    if (m.route.param('tool') && !selected) {
      return shell(this, [pageHeading('工具暂未找到', '这个工具尚未加入工具箱。'), link('/campus/tools', '返回校园工具箱')]);
    }

    if (selected) {
      const content = selected.key === 'calendar' ? this.calendar()
        : selected.key === 'timetable' ? this.timetable()
        : selected.key === 'shuttle' ? this.shuttle()
        : m('section.Campus-panel.Campus-toolboxEmpty', { 'aria-label': selected.title + '内容' }, [
          symbol(selected.icon),
          m('h2', selected.empty),
          m('p', selected.source ? '可从下方来源页面继续查找，使用前请核对发布时间和适用范围。' : '此页正在准备中；请以学校或有关部门的正式通知为准。')
        ]);
      return shell(this, m('div.Campus-toolbox', [
        m('nav.Campus-toolboxBack', { 'aria-label': '工具箱导航' }, link('/campus/tools', '返回校园工具箱')),
        pageHeading(selected.title, selected.description),
        content,
        this.source(selected)
      ]));
    }

    const filled = { calendar: true, timetable: true, shuttle: true };
    return shell(this, m('div.Campus-toolbox', [
      pageHeading('校园工具箱', '把常用校园信息放在一个地方：校历、课程表与摆渡车时刻已按下发文件录入。'),
      m('nav.Campus-toolboxList', { 'aria-label': '校园工具' }, tools.map(tool =>
        link('/campus/tools/' + tool.key, [
          m('span.Campus-toolboxIcon', symbol(tool.icon)),
          m('span.Campus-toolboxText', [m('strong', tool.title), m('small', tool.description)]),
          m('span.Campus-toolboxStatus', filled[tool.key] ? '已录入' : tool.status || '内容待录入'),
          m('span.Campus-toolboxArrow', symbol('chevron-right'))
        ], { className: 'Campus-toolboxItem', key: tool.key })
      ))
    ]));
  }
}

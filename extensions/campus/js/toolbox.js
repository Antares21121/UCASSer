import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Modal from 'flarum/common/components/Modal';
import Button from './button';
import { link, pageHeading, shell, symbol, errorMessage } from './ui';

const WEEKDAYS = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'];

// The schedule export ships with the extension; the two semester calendars and
// the shuttle table are published as page images and displayed as-is.
const loadTimetable = () => app.cache.campusTimetable || (app.cache.campusTimetable = app
  .request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/campus/tools/data' })
  .then(response => response.data.timetable));

const loadSubscriptions = () => app.request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/campus/timetable/subscriptions' })
  .then(response => response.data)
  .catch(() => ({ ids: [], logged_in: Boolean(app.session.user) }));

export const tools = [
  { key: 'calendar', title: '校历', icon: 'calendar-alt', description: '查看学校发布的秋、春两学期校历原图。' },
  { key: 'timetable', title: '课程表', icon: 'table', description: '按天与节次查看全校开课，并订阅成自己的课表。' },
  { key: 'shuttle', title: '摆渡车时间表', icon: 'bus', description: '学校与地铁站之间的班次时刻原图。' },
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

class TimetableSlotModal extends Modal {
  className() { return 'CampusRecordActionModal CampusSlotModal'; }
  title() { return this.attrs.dayLabel + ' 第 ' + this.attrs.period + ' 节'; }
  oninit(vnode) {
    super.oninit(vnode);
    this.busy = null;
    this.error = '';
  }
  async toggle(entry) {
    if (!app.session.user) { app.alerts.show({ type: 'error' }, '登录后可以订阅课程'); return; }
    this.busy = entry.id;
    this.error = '';
    try {
      const enabled = !this.attrs.subs.has(entry.id);
      await app.request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/campus/timetable/subscriptions',
        body: { entry_id: entry.id, enabled }
      });
      if (enabled) this.attrs.subs.add(entry.id); else this.attrs.subs.delete(entry.id);
      this.attrs.after?.();
    } catch (error) {
      this.error = errorMessage(error, '订阅失败，请稍后重试');
    } finally {
      this.busy = null;
    }
  }
  content() {
    const entries = this.attrs.entries || [];
    return m('div.Modal-body.Campus', [
      m('p.Campus-hint', this.attrs.subtitle || ''),
      m('div.Campus-slotList', entries.map(entry => m('article.Campus-slotItem', { key: entry.id }, [
        m('div.Campus-slotHead', [m('strong', entry.course), entry.credit ? m('span.Campus-badge', entry.credit + ' 学分') : null]),
        m('p.Campus-slotMeta', [entry.teacher || '教师待定', ' · ', entry.room || '教室待定', ' · 第' + entry.s + (entry.e !== entry.s ? '–' + entry.e : '') + '节', ' · ', entry.weeks + '周' + (entry.parity ? '（' + entry.parity + '）' : '')]),
        m('p.Campus-slotMeta', [entry.unit, entry.category, entry.exam, entry.klass].filter(Boolean).join(' · ')),
        m('div.Campus-actions', [
          m(Button, {
            className: this.attrs.subs.has(entry.id) ? '' : 'Button Button--primary',
            loading: this.busy === entry.id,
            disabled: Boolean(this.busy),
            onclick: () => this.toggle(entry)
          }, this.attrs.subs.has(entry.id) ? '已订阅 · 取消' : '订阅这门课')
        ])
      ]))),
      this.error ? m('p.Campus-controlSourceError', this.error) : null,
      m('div.Campus-actions', [m(Button, { type: 'button', onclick: () => this.hide() }, '关闭')])
    ]);
  }
}

export default class ToolboxPage extends Page {
  bodyClass = 'CampusApp';

  oninit() {
    this.data = null;
    this.failed = false;
    this.mode = 'all';
    this.week = null;
    this.unit = '';
    this.category = '';
    this.period = '';
    this.building = '';
    this.query = '';
    this.subs = new Set();
    this.subsReady = false;
    loadTimetable().then(data => {
      this.data = data;
      if (!this.week) this.week = this.currentWeek();
      m.redraw();
    }).catch(() => {
      this.failed = true;
      m.redraw();
    });
    loadSubscriptions().then(state => {
      this.subs = new Set(state.ids || []);
      this.subsReady = true;
      if (this.mode === 'mine' && !app.session.user) this.mode = 'all';
      m.redraw();
    });
    this.updateTitle();
  }

  currentWeek() {
    const start = new Date('2026-09-07T00:00:00');
    const diff = Math.floor((Date.now() - start.getTime()) / 86400000);
    const week = Math.floor(diff / 7) + 1;
    return week >= 1 && week <= 18 ? week : null;
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
      m('p', this.failed ? '请刷新页面重试。' : '数据随扩展一起提供，只需载入一次。')
    ]);
  }

  filteredEntries() {
    const data = this.data;
    if (!data) return [];
    const query = this.query.trim().toLowerCase();
    return data.entries.filter(entry => {
      if (this.mode === 'mine' && !this.subs.has(entry.id)) return false;
      if (this.unit && entry.unit !== this.unit) return false;
      if (this.category && entry.category !== this.category) return false;
      if (this.period && Number(this.period) !== entry.s) return false;
      if (this.building && entry.building !== this.building) return false;
      if (!slotMatchesWeek(entry, this.week)) return false;
      if (!query) return true;
      return [entry.course, entry.teacher, entry.room, entry.klass, entry.unit].some(value => value && value.toLowerCase().includes(query));
    });
  }

  timetable() {
    const data = this.data;
    if (!data) return this.loading('课程表');
    const entries = this.filteredEntries();
    const byCell = new Map();
    for (const entry of entries) {
      const key = entry.d + ':' + entry.s;
      if (!byCell.has(key)) byCell.set(key, []);
      byCell.get(key).push(entry);
    }
    const days = WEEKDAYS.map((label, index) => ({ label, no: index + 1 })).filter(day => day.no !== 7 || entries.some(entry => entry.d === 7));
    const open = (dayNo, period) => {
      const list = byCell.get(dayNo + ':' + period) || [];
      app.modal.show(TimetableSlotModal, {
        dayLabel: WEEKDAYS[dayNo - 1], period, entries: list, subs: this.subs,
        subtitle: list.length + ' 门课程；订阅后只出现在你自己的“我的课表”里。',
        after: () => { if (this.subs.size) this.mode = 'mine'; m.redraw(); }
      });
    };
    return m('section.Campus-panel.Campus-toolboxSection', { 'aria-label': '课程表' }, [
      m('div.Campus-ttToolbar', [
        m('label.Campus-ttField', [m('small', '院系'), m('select', { onchange: e => { this.unit = e.target.value; } },
          [m('option', { value: '', selected: !this.unit }, '全部院系'), ...data.units.map(unit => m('option', { value: unit, selected: this.unit === unit }, unit))])]),
        m('label.Campus-ttField', [m('small', '课程类别'), m('select', { onchange: e => { this.category = e.target.value; } },
          [m('option', { value: '', selected: !this.category }, '全部类型'), ...data.categories.map(category => m('option', { value: category, selected: this.category === category }, category))])]),
        m('label.Campus-ttField', [m('small', '节次'), m('select', { onchange: e => { this.period = e.target.value; } },
          [m('option', { value: '', selected: !this.period }, '全部'), ...Array.from({ length: data.periods }, (_, i) => i + 1).map(n => m('option', { value: n, selected: String(n) === this.period }, '第' + n + '节'))])]),
        m('label.Campus-ttField', [m('small', '楼宇'), m('select', { onchange: e => { this.building = e.target.value; } },
          [m('option', { value: '', selected: !this.building }, '全部楼宇'), ...data.buildings.map(building => m('option', { value: building, selected: this.building === building }, building))])]),
        m('label.Campus-ttField.Campus-ttField--grow', [m('small', '搜索'), m('input.FormControl', {
          type: 'search', value: this.query, placeholder: '课程名称、教师或教室', oninput: e => { this.query = e.target.value; }
        })])
      ]),
      m('div.Campus-ttSwitch', [
        m(Button, { className: this.mode === 'all' ? 'Button Button--primary' : '', onclick: () => { this.mode = 'all'; } }, '全部课程'),
        m(Button, {
          className: this.mode === 'mine' ? 'Button Button--primary' : '',
          onclick: () => {
            if (!app.session.user) { app.alerts.show({ type: 'error' }, '登录后可以使用“我的课表”'); return; }
            this.mode = 'mine';
          }
        }, '我的课表' + (this.subs.size ? '（' + this.subs.size + '）' : '')),
        m('span.Campus-hint', '点击任意格子查看该时段全部课程，再订阅成自己的课表。')
      ]),
      m('p.Campus-hint', ['当前筛选 ' + entries.length + ' 条排课；数据来源：' + data.source + '。临时调课以教务系统为准。']),
      m('div.Campus-ttGrid', { role: 'table', 'aria-label': '课程表' }, [
        m('div.Campus-ttGridHead', { role: 'row' }, [m('span.Campus-ttGridCorner', '节次'), ...days.map(day => m('span.Campus-ttGridDay', { role: 'columnheader' }, day.label))]),
        ...Array.from({ length: data.periods }, (_, index) => index + 1).map(period => m('div.Campus-ttGridRow', { role: 'row' }, [
          m('span.Campus-ttGridPeriod', { role: 'rowheader' }, '第' + period + '节'),
          ...days.map(day => {
            const list = byCell.get(day.no + ':' + period) || [];
            return m('button.Campus-ttCell', {
              type: 'button', role: 'cell',
              disabled: !list.length,
              className: list.length ? 'Campus-ttCell is-filled' + (this.mode === 'mine' ? ' is-mine' : '') : 'Campus-ttCell',
              'aria-label': day.label + ' 第' + period + '节，' + list.length + ' 门课程',
              onclick: () => open(day.no, period)
            }, list.length ? [m('strong', list.length + ' 门'), m('small', list.slice(0, 2).map(entry => entry.course).join(' / ') + (list.length > 2 ? ' …' : ''))] : m('span', '—'));
          })
        ]))
      ])
    ]);
  }

  /* ---------------------- 校历 / 摆渡车：原图 ---------------------- */

  imagePage(label, images) {
    return m('section.Campus-panel.Campus-toolboxSection', { 'aria-label': label }, [
      m('p.Campus-hint', '以下为学校发布的' + label + '原图，点击可在新标签页查看大图；如与最新通知不一致，以学校通知为准。'),
      ...images.map(image => m('figure.Campus-toolImage', [
        m('a', { href: '/api/campus/assets/' + image.file, target: '_blank', rel: 'noopener noreferrer' },
          m('img', { src: '/api/campus/assets/' + image.file, alt: image.alt, loading: 'lazy' })),
        m('figcaption', [image.caption, ' ', m('a', { href: '/api/campus/assets/' + image.file, target: '_blank', rel: 'noopener noreferrer' }, '查看原图 ↗')])
      ]))
    ]);
  }

  calendar() {
    return this.imagePage('校历', [
      { file: 'calendar-autumn.jpg', alt: '2026–2027 学年秋季学期校历', caption: '2026–2027 学年秋季学期校历' },
      { file: 'calendar-spring.jpg', alt: '2026–2027 学年春季学期校历', caption: '2026–2027 学年春季学期校历' }
    ]);
  }

  shuttle() {
    return this.imagePage('摆渡车时间表', [
      { file: 'shuttle.jpg', alt: '摆渡车时刻表', caption: '摆渡车发车时刻（学校出发 / 地铁返回）' }
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
      pageHeading('校园工具箱', '校历与摆渡车按学校原图展示，课程表可按天查看并订阅成自己的课表。'),
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

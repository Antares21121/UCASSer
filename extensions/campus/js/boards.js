import app from 'flarum/forum/app';
import Hero from 'flarum/forum/components/Hero';
import ItemList from 'flarum/common/utils/ItemList';
import Link from 'flarum/common/components/Link';
import IndexPage from 'flarum/forum/components/IndexPage';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import { extend, override } from 'flarum/common/extend';
import Button from './button';
import { labels } from './records';
import { symbol, formatTime, errorMessage } from './ui';

// Layout and copy follow the task of each board; all rows retain native links,
// pagination, permissions, unread state and discussion controls.
// Retired discussion boards (news/academic/market/chat/governance/courses and
// the legacy "general" tag) no longer exist as publish targets; their content
// was migrated by the backend mover. Review records keep their structured
// catalog under the internal archive section, displayed under the "courses"
// board key below.
export const boards = {
  careers: {
    icon: 'briefcase',
    title: '实习、就业与竞赛',
    subtitle: '找到适合自己的机会，看清要求与截止时间。',
    type: 'information',
    category: 'internship',
    action: '分享新机会',
    kind: 'opportunity',
    tabs: [['internship', '实习'], ['job', '就业'], ['competition', '竞赛'], ['recruit', '项目招募'], ['lecture', '讲座'], ['activity', '校园活动'], ['volunteer', '志愿']]
  },
  life: {
    icon: 'hands-helping',
    title: '校园生活与互助',
    subtitle: '分享生活经验，让每一个小问题都有人回应。',
    type: 'information',
    category: 'help',
    action: '发布互助信息',
    kind: 'help',
    tabs: [['help', '生活互助'], ['buddy', '寻找伙伴'], ['other', '生活经验']]
  },
  secondhand: {
    icon: 'box-open',
    title: '二手交易',
    subtitle: '让闲置找到新主人，交易前记得核对成色与价格。',
    type: 'information',
    category: 'market',
    action: '发布二手信息',
    kind: 'market',
    tabs: [['market', '二手交易']]
  },
  travel: {
    icon: 'route',
    title: '出行信息',
    subtitle: '拼车、同行与出行安排，让每一次出发都更顺利。',
    type: 'information',
    category: 'travel',
    action: '发布出行信息',
    kind: 'market',
    variant: 'travel',
    tabs: [['travel', '出行'], ['buddy', '找同行']]
  },
  resources: {
    icon: 'book-open',
    title: '共享资料库',
    subtitle: '有出处、有说明的资料，让知识更容易被找到和使用。',
    type: 'resource',
    category: 'notes',
    action: '分享合法资料',
    kind: 'document',
    secondary: ['/resources', '按学院与课程查找资料'],
    tabs: [['notes', '学习笔记'], ['lecture', '公开讲义'], ['revision', '复习指南'], ['guide', '校园指南'], ['faq', '常见问题']]
  },
  courses: {
    icon: 'graduation-cap',
    title: '课程目录与评价',
    subtitle: '真实体验与学习方法，帮助下一位同学做出选择。',
    type: 'review',
    category: 'experience',
    action: '写一条课程评价',
    kind: 'review',
    secondary: ['/campus/courses', '查看课程目录与统计'],
    archive: true
  },
  questions: {
    icon: 'comment-dots',
    title: '校园问答',
    subtitle: '把问题说清楚，让有用的回答留下来。',
    type: 'question',
    category: 'study',
    action: '提出问题',
    kind: 'question',
    tabs: [['study', '学习与课程'], ['life', '校园生活'], ['procedure', '校园办事'], ['activity', '活动与竞赛']]
  }
};
// Retired board slugs redirect to the closest current entry point so old
// links never die silently.
export const legacyBoards = {
  'campus-courses': '/campus/courses',
  'campus-general': '/t/campus-life',
  general: '/t/campus-life',
  'campus-market': '/t/campus-secondhand',
  'campus-academic': '/t/campus-careers',
  'campus-news': '/campus/aggregation',
  'campus-chat': '/t/campus-life',
  'campus-governance': '/campus/governance',
  'campus-archive': '/campus/courses'
};
export function routeBoard() {
  return boards[(m.route.param('tags') || '').replace(/^campus-/, '')];
}
export function boardKey(discussion, record = discussion?.attribute('campusRecord')) {
  const key = record?.section_key || (discussion?.tags?.() || []).filter(Boolean).find(t => boards[t.slug().replace(/^campus-/, '')])?.slug().replace(/^campus-/, '') || '';
  // Review records live under the internal archive section; display them as
  // course reviews everywhere outside the section catalog.
  return key === 'archive' ? 'courses' : key;
}
export const marketVariant = (record, key) => (key === 'travel' || ['travel', 'buddy'].includes(record?.category)) && key !== 'secondhand' ? 'travel' : 'trade';
export function boardParams(board) {
  const key = Object.keys(boards).find(k => boards[k] === board);
  const section = app.cache.campusCatalog?.data?.sections.find(s => s.key === key);
  return {
    type: board.type,
    section_id: section?.id,
    section_key: key,
    category: board.category
  };
}
const short = value => String(value || '').slice(0, 140);
const dateKeys = ['starts_at', 'ends_at', 'deadline', 'published_at', 'updated_at', 'last_verified'];
const external = (href, text, primary = false) => /^https?:\/\//i.test(href || '') ? m('a', {
  href,
  target: '_blank',
  rel: 'noopener noreferrer nofollow',
  className: 'Button ' + (primary ? 'Button--primary' : '')
}, [text, symbol('external-link-alt')]) : null;
function filterUrl(key, criteria = {}) {
  return '/t/campus-' + key + (Object.keys(criteria).length ? '?' + new URLSearchParams({
    'filter[campus]': JSON.stringify(criteria)
  }) : '');
}
export class BoardHero extends Hero {
  className() {
    return 'CampusBoardHero CampusBoardHero--' + this.attrs.board.kind;
  }
  bodyItems() {
    const {
        board,
        model,
        publish
      } = this.attrs,
      key = Object.keys(boards).find(k => boards[k] === board),
      items = new ItemList();
    let filter = {};
    try {
      filter = JSON.parse(m.route.param('filter')?.campus || '{}');
    } catch (_) {}
    items.add('intro', m('div.Campus-boardIntro', [m('span.Campus-boardIcon', symbol(board.icon)), m('div', [m('h1.Hero-title', model?.name() || board.title), m('p.Hero-subtitle', model?.description() || board.subtitle)]), m('div.Campus-boardActions', [board.action && (!board.adminOnly || app.session.user?.isAdmin()) ? m(Button, {
      className: 'Button Button--primary',
      icon: board.actionIcon || 'fas fa-plus',
      onclick: () => publish(board)
    }, board.action) : null, board.secondary ? m(Link, {
      href: board.secondary[0],
      className: 'Campus-boardSecondary'
    }, [board.secondary[1], symbol('arrow-right')]) : null])]), 100);
    const tabs = board.tabs?.filter(([category]) => app.cache.campusCatalog?.data?.categories[board.type]?.[category]);
    if (tabs?.length || key === 'questions') items.add('filters', m('nav.Campus-boardTabs', {
      'aria-label': '板块内容筛选'
    }, [m(Link, {
      href: filterUrl(key),
      className: !filter.category && !filter.status ? 'is-active' : ''
    }, '全部内容'), ...(tabs || []).map(([category, label]) => m(Link, {
      href: filterUrl(key, {
        type: board.type,
        category
      }),
      className: filter.category === category ? 'is-active' : ''
    }, label)), key === 'questions' ? ['waiting', 'resolved'].map(status => m(Link, {
      href: filterUrl(key, {
        type: 'question',
        status
      }),
      className: filter.status === status ? 'is-active' : ''
    }, status === 'waiting' ? '等待回答' : '已解决')) : null]), 80);
    return items;
  }
}
export function ratingMetrics(fields, compact = false) {
  return m('div' + (compact ? '.Campus-rowRatings' : '.Campus-reviewMetrics'), ['overall', 'difficulty', 'workload'].filter(k => fields[k] != null).map(k => m('div.Campus-rating', [m('span', {
    title: labels[k]
  }, {
    overall: '总体体验',
    difficulty: '难度',
    workload: '工作量'
  }[k]), m('strong', [fields[k], m('small', ' / 5')]), compact ? null : m('span.Campus-ratingTrack', {
    'aria-hidden': 'true'
  }, m('i', {
    style: {
      width: Math.max(0, Math.min(100, Number(fields[k]) * 20)) + '%'
    }
  })), compact ? null : m('small', {
    overall: '1 不满意 · 5 满意',
    difficulty: '1 易 · 5 难',
    workload: '1 少 · 5 多'
  }[k])])));
}
function facts(key, r) {
  const f = r.fields || {},
    entries = [];
  const add = (label, value, time = false) => {
    if (value) entries.push(m('span', {
      title: label + '：' + (time ? formatTime(value) + '（北京时间）' : value)
    }, [m('small', label), short(time ? formatTime(value) : value)]));
  };
  if (key === 'careers') {
    add('单位', f.organizer || f.source);
    add('截止', f.deadline, true);
    add('要求', f.conditions);
  } else if (key === 'resources') {
    add('课程', f.course);
    add('主题', f.topic);
    add('版本', f.edition);
    add('核验', f.last_verified, true);
  } else if (key === 'courses') {
    add('课程', r.course_name);
    add('学期', r.semester);
  } else if (['secondhand', 'travel'].includes(key)) {
    const travel = key === 'travel' || marketVariant(r, key) === 'travel';
    add(travel ? '同行需求' : '物品 / 供需', f.offer_type);
    add(travel ? '路线 / 集合' : '交接地点', f.location);
    if (travel) add('出发', f.starts_at, true);
  } else if (key === 'life') {
    add('地点', f.location);
    add('需求', f.offer_type);
  }
  if (!entries.length && f.source && key !== 'questions') add('来源', f.source);
  return entries.length ? m('span.Campus-rowFacts', entries) : null;
}
export function recordOverview(r, discussion) {
  const key = boardKey(discussion, r),
    board = boards[key],
    f = r.fields || {},
    used = new Set(['related']);
  if (!board || !r?.id) return null;
  const travel = ['secondhand', 'travel'].includes(key) && (key === 'travel' || ['travel', 'buddy'].includes(r.category));
  const fieldLabels = {
    contact: '公开联系方式',
    tags: '标签',
    location: ['secondhand', 'travel'].includes(key) ? travel ? '路线或集合地点' : '交接地点' : key === 'life' ? '互助地点' : labels.location,
    organizer: ['careers'].includes(key) ? '发布单位' : labels.organizer,
    conditions: key === 'careers' ? '申请要求' : ['secondhand', 'travel'].includes(key) ? travel ? '同行说明' : '交易说明' : key === 'life' ? '互助说明' : labels.conditions,
    starts_at: travel ? '出发时间' : labels.starts_at,
    ends_at: travel ? '返回或结束时间' : labels.ends_at,
    deadline: travel ? '联系截止' : labels.deadline
  };
  const group = (title, keys, style = '') => {
    const rows = keys.filter(k => f[k] !== '' && f[k] != null);
    rows.forEach(k => used.add(k));
    return rows.length ? m('section.Campus-detailGroup', {
      className: style
    }, [m('h3', title), m('dl.Campus-detailFacts', rows.map(k => [m('dt', fieldLabels[k] || labels[k] || k), m('dd', k.endsWith('_url') ? external(f[k], '查看原始出处') : dateKeys.includes(k) ? formatTime(f[k]) + '（北京时间）' : String(f[k]))]))]) : null;
  };
  const spotlight = (name, field, icon) => {
    if (f[field] == null || f[field] === '') return null;
    used.add(field);
    return m('div.Campus-detailSpotlight', [symbol(icon), m('div', [m('span', name), m('strong', dateKeys.includes(field) ? formatTime(f[field]) + '（北京时间）' : String(f[field]))])]);
  };
  const schedule = () => {
    const keys = ['starts_at', 'ends_at', 'deadline'].filter(k => f[k]);
    keys.forEach(k => used.add(k));
    return keys.length ? m('ol.Campus-detailSchedule', keys.map(k => m('li', [m('span', labels[k]), m('time', formatTime(f[k]) + '（北京时间）')]))) : null;
  };
  const heading = {
    careers: '申请指南',
    life: '互助信息',
    secondhand: '二手交易',
    travel: '出行与同行',
    resources: '资料索引',
    questions: '解答进展',
    courses: r.course_name || '课程体验'
  }[key] || labels[r.type];
  const intro = m('div.Campus-detailHeading', [m('span.Campus-detailHeadingIcon', symbol(key === 'travel' ? 'route' : ['secondhand', 'travel'].includes(key) ? 'box-open' : board?.icon || 'file-alt')), m('h2', heading), m('div.Campus-badges', [m('span.Campus-badge', {
    className: 'is-' + r.status
  }, labels[r.status] || r.status), r.featured ? m('span.Campus-badge.is-featured', '精选') : null, r.semester ? m('span.Campus-badge', r.semester) : null])]);
  let main;
  if (r.type === 'resource') {
    used.add('source_url');
    used.add('alternate_url');
    main = [m('div.Campus-resourceAccess', [external(f.source_url, '打开资料', true), external(f.alternate_url, '备用访问链接')]), group('适用范围与版本', ['course', 'topic', 'grade', 'edition', 'last_verified', 'updated_at'], 'Campus-documentFacts'), group('出处与使用说明', ['source', 'license'])];
  } else if (r.type === 'review') {
    ['overall', 'difficulty', 'workload'].forEach(k => used.add(k));
    main = [ratingMetrics(f), m('p.Campus-hint', '本条评分为作者个人体验，课程统计请结合样本数量阅读。'), m(Link, {
      href: '/campus/courses?search=' + encodeURIComponent(r.course_name || ''),
      className: 'Campus-boardSecondary'
    }, ['查看课程目录与统计', symbol('arrow-right')]), group('选课前了解', ['assessment', 'participation', 'prerequisites']), group('学习收获与建议', ['gains', 'study', 'experience', 'advice'])];
  } else if (r.type === 'question') main = [m('ol.Campus-questionProgress', ['waiting', 'discussing', 'resolved'].map((status, index) => m('li', {
    className: status === r.status ? 'is-current' : ''
  }, [m('span', index + 1), {
    waiting: '等待回答',
    discussing: '交流讨论',
    resolved: '问题解决'
  }[status]]))), f.background ? group('问题背景', ['background']) : null, r.accepted_post_id ? m(Button, {
    className: 'Button Button--primary',
    icon: 'fas fa-check',
    onclick: async () => {
      try {
        const post = await app.store.find('posts', String(r.accepted_post_id));
        m.route.set(app.route.post(post));
      } catch (e) {
        app.alerts.show({
          type: 'error'
        }, errorMessage(e));
      }
    }
  }, '查看已采纳答案') : m('p.Campus-hint', '在下方回复区分享你的经验。提问者可采纳有帮助的答案。')];else if (key === 'careers') {
    used.add('apply_url');
    main = [m('div.Campus-applicationLead', [spotlight('申请截止', 'deadline', 'hourglass-half'), r.status === 'active' ? external(f.apply_url, '前往申请', true) : null]), group('单位与申请要求', ['organizer', 'conditions', 'location']), group('机会来源', ['source', 'source_url', 'published_at'])];
  } else if (key === 'secondhand') {
    main = [spotlight('物品与供需', 'offer_type', 'box-open'), group('交接安排', ['location', 'conditions', 'contact'])];
  } else if (key === 'travel') {
    main = [group('行程时间', ['starts_at', 'ends_at', 'deadline'], 'Campus-tripTimes'), spotlight('路线或集合地点', 'location', 'map-marker-alt'), group('寻找怎样的同行', ['offer_type', 'conditions', 'contact'])];
  } else if (key === 'life') main = [spotlight('需要什么帮助', 'offer_type', 'hands-helping'), group('互助安排', ['location', 'conditions', 'contact'])];else {
    used.add('apply_url');
    main = [group('活动日程', ['starts_at', 'ends_at', 'deadline'], 'Campus-calendarFacts'), group('地点与参与方式', ['location', 'organizer', 'conditions', 'contact']), r.status === 'active' ? external(f.apply_url, '报名或参与', true) : null, group('信息来源', ['source', 'source_url', 'published_at'])];
  }
  // Only board-relevant fields enter secondary disclosure. Old activity
  // metadata on a trade/help/notice record stays stored, not shown as a form.
  const secondaryFields = {
    secondhand: ['source', 'source_url', 'published_at', 'tags'],
    travel: ['source', 'source_url', 'published_at', 'tags'],
    life: ['source', 'source_url', 'published_at', 'tags'],
    careers: ['contact', 'starts_at', 'tags'],
    resources: ['tags'],
    questions: ['tags'],
    courses: ['tags']
  }[key];
  const extra = Object.keys(f).filter(k => !used.has(k) && (!secondaryFields || secondaryFields.includes(k)) && f[k] !== '' && f[k] != null);
  const marketKeys = ['secondhand', 'travel'];
  return m('div.Campus-recordOverview', {
    className: 'Campus-recordOverview--' + (board?.kind || r.type) + (marketKeys.includes(key) ? ' Campus-market--' + (key === 'travel' ? 'travel' : 'trade') : '')
  }, [intro, main, extra.length ? m('details.Campus-detailMore', [m('summary', ['secondhand', 'travel', 'life'].includes(key) ? '信息出处与标签' : '补充说明与标签'), group(['secondhand', 'travel', 'life'].includes(key) ? '来源说明' : '补充说明', extra)]) : null, f.related?.length ? m('div.Campus-related', [m('h3', '关联内容'), f.related.map(item => m(Link, {
    href: '/campus/record/' + item.id
  }, [symbol('link'), item.title]))]) : null]);
}
export function registerBoards(openComposer) {
  extend(DiscussionListItem.prototype, 'mainView', function (vnode) {
    // Move the native tag item into a heading row, keeping native rendering,
    // links and unread state. No floating tag or fixed title reservation.
    const children = vnode?.children;
    if (!Array.isArray(children)) return;
    const title = children.find(child => child?.tag === 'h2');
    const info = children.find(child => child?.tag === 'ul');
    if (!title || !Array.isArray(info?.children)) return;
    const tags = info.children.find(child => child?.attrs?.className?.split(' ').includes('item-tags'));
    if (!tags) return;
    info.children = info.children.filter(child => child !== tags);
    children[children.indexOf(title)] = m('div.Campus-feedHeading', [title, m('span.Campus-feedTags', tags.children)]);
  });
  extend(DiscussionListItem.prototype, 'oninit', function () {
    this.subtree.check(() => app.cache.campusCatalog?.data);
  });
  const publish = board => {
    if (!board.type) {
      document.querySelector('.IndexPage-newDiscussion')?.click();
      return;
    }
    openComposer(boardParams(board)).catch(e => app.alerts.show({
      type: 'error'
    }, errorMessage(e)));
  };
  override(IndexPage.prototype, 'hero', function (original) {
    const board = routeBoard();
    if (!board) return original();
    const tag = app.store.all('tags').find(t => t.slug() === m.route.param('tags'));
    return m(BoardHero, {
      board,
      model: tag,
      publish
    });
  });
  extend(IndexPage.prototype, 'view', function (vnode) {
    const board = routeBoard();
    vnode.attrs.className += board ? ' Campus-board--' + Object.keys(boards).find(k => boards[k] === board) : ' Campus-feedPage';
  });
  extend(DiscussionListItem.prototype, 'elementAttrs', function (attrs) {
    const key = boardKey(this.attrs.discussion);
    if (boards[key]) attrs.className += ' Campus-row Campus-row--' + boards[key].kind + ' Campus-row--' + key;
  });
  extend(DiscussionListItem.prototype, 'contentItems', function (items) {
    const d = this.attrs.discussion,
      r = d.attribute('campusRecord'),
      key = boardKey(d, r),
      b = boards[key];
    if (!b || !r?.id || ['help', 'conversation', 'market'].includes(b.kind)) return;
    const f = r.fields || {},
      date = f.starts_at || f.deadline;
    const value = date ? new Date(date.endsWith('Z') ? date : date + 'Z') : null;
    let marker;
    if (['calendar', 'agenda'].includes(b.kind)) marker = value && !Number.isNaN(value.getTime()) ? m('span.Campus-dateMarker', [m('small', new Intl.DateTimeFormat('zh-CN', {
      month: 'numeric',
      timeZone: 'Asia/Shanghai'
    }).format(value)), m('strong', new Intl.DateTimeFormat('zh-CN', {
      day: 'numeric',
      timeZone: 'Asia/Shanghai'
    }).format(value).replace('日', ''))]) : symbol('calendar-alt');else if (b.kind === 'question') marker = m('span.Campus-questionMarker', {
      className: r.status === 'resolved' ? 'is-resolved' : ''
    }, [r.status === 'resolved' ? symbol('check') : m('strong', d.replyCount()), m('small', r.status === 'resolved' ? '已解决' : '回答')]);else marker = symbol(b.icon);
    items.add('campus-marker', m('div.Campus-rowMarker', {
      'aria-hidden': 'true'
    }, marker), 101);
  });
  extend(DiscussionListItem.prototype, 'infoItems', function (items) {
    const d = this.attrs.discussion,
      r = d.attribute('campusRecord');
    if (!r?.id) return;
    const key = boardKey(d, r),
      b = boards[key];
    if (!b) return;
    const category = app.cache.campusCatalog?.data?.categories[r.type]?.[r.category];
    items.setContent('campus-meta', m('span.Campus-listMetadata', [category || labels[r.type], m('span', {
      className: 'Campus-rowStatus is-' + r.status
    }, labels[r.status] || r.status)]));
    const details = facts(key, r);
    if (details) items.add('campus-facts', details, -30);
    if (r.type === 'review') items.add('campus-ratings', ratingMetrics(r.fields || {}, true), -40);
  });
}

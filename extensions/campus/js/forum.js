import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import HeaderPrimary from 'flarum/forum/components/HeaderPrimary';
import IndexPage from 'flarum/forum/components/IndexPage';
import { extend } from 'flarum/common/extend';
import IdentityPage from './identity';
import ModerationPage from './moderation';
import { RecordsPage, RecordPage, PublishPage } from './records';
import CoursesPage from './courses';
import registerNotifications from './notifications';
import { PersonalPage, registerBookmarks } from './personal';
import SearchPage from './search';
import { CasesPage, registerReports } from './cases';
import GovernancePage from './governance';
const link = (href, text, attrs = {}) => m(Link, {
  href,
  ...attrs
}, text);
export const request = (path, method = 'GET', body) => app.request({
  method,
  url: app.forum.attribute('apiUrl') + '/campus/' + path,
  body
});
export class CampusPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    this.loading = true;
    this.error = '';
    this.load();
  }
  async load() {
    this.loading = true;
    this.error = '';
    try {
      this.data = (await request(this.endpoint())).data;
    } catch (e) {
      this.error = e.status === 403 || e.status === 401 ? '请登录或申请所需访问权限。' : '加载失败，请稍后重试。';
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
  endpoint() {
    return 'home';
  }
  nav() {
    return m('nav.Campus-nav', {
      'aria-label': '校园平台'
    }, [link('/campus', '首页'), link('/all', '讨论'), link('/campus/records?type=information', '校园信息'), link('/campus/records?type=resource', '共享资料'), link('/campus/records?type=question', '问答'), link('/campus/courses', '课程经验'), link('/campus/me', '个人中心'), link('/campus/identity', '身份与账号'), link('/campus/governance', '公开治理'), link('/campus/cases', '举报与申诉'), link('/campus/rules', '社区公约')]);
  }
  view() {
    return m('div.Campus.container', [this.nav(), this.loading ? m('p', {
      role: 'status'
    }, '正在加载…') : this.error ? m('div', {
      role: 'alert'
    }, [m('p', this.error), m(Button, {
      onclick: () => this.load()
    }, '重新加载')]) : this.content()]);
  }
  list(title, items, empty = '暂无内容，可以在对应分区发布第一条信息。') {
    return m('section.Campus-panel', [m('h2', title), items.length ? m('ul.Campus-list', items.map(d => m('li', [link('/d/' + d.id, d.title), m('small', [d.author, ' · ', new Date(d.createdAt).toLocaleDateString('zh-CN'), ' · ', d.tags.join(' / '), ' · ', d.comments + ' 条评论'])]))) : m('p.Campus-empty', empty)]);
  }
  content() {
    const d = this.data;
    return [m('header.Campus-hero', [m('p.Campus-eyebrow', '校园公共信息与知识共享'), m('h1', d.title), m('p', d.description), m('form.Campus-search', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set('/campus/search', {
          q: this.query || ''
        });
      }
    }, [m('input.FormControl', {
      type: 'search',
      placeholder: '搜索讨论、信息与学习经验',
      'aria-label': '全局搜索',
      oninput: e => this.query = e.target.value
    }), m('button.Button.Button--primary', {
      type: 'submit'
    }, '搜索')])]), m('section', [m('h2', '探索分区'), m('div.Campus-grid', d.sections.map(s => link('/t/' + s.slug, [m('strong', s.name), m('p', s.description)], {
      className: 'Campus-section'
    })))]), m('div.Campus-columns', [this.list('最新发布', d.latest), this.list('最近 30 天热门讨论', d.popular), this.list('最新问答', d.questions)]), m('div.Campus-columns', [['最新校园信息', d.information || []], ['近期活动与截止日期', d.upcoming || []]].map(([title, items]) => m('section.Campus-panel', [m('h2', title), items.length ? m('ul', items.map(r => m('li', [link('/campus/record/' + r.id, r.title), m('small', r.fields.deadline || r.fields.starts_at || '')]))) : m('p.Campus-empty', '暂无信息，可在校园信息入口发布。')]))), m('footer.Campus-panel', [link('/campus/rules', '社区公约、治理说明与举报方式')])];
  }
}
class RulesPage extends CampusPage {
  endpoint() {
    return 'rules';
  }
  content() {
    return [m('h1', '社区公约与治理'), m('section.Campus-panel', [m('h2', '社区公约'), m('p.Campus-text', this.data.rules)]), m('section.Campus-panel', [m('h2', '治理说明'), m('p.Campus-text', this.data.governance), m('p', '登录后在帖子操作菜单中选择举报。反馈来源失效、内容错误或隐私问题时请填写具体原因。')])];
  }
}
app.initializers.add('ucasser-campus', () => {
  registerNotifications();
  registerBookmarks();
  registerReports();
  app.routes.campus = {
    path: '/campus',
    component: CampusPage
  };
  app.routes['campus.rules'] = {
    path: '/campus/rules',
    component: RulesPage
  };
  app.routes['campus.identity'] = {
    path: '/campus/identity',
    component: IdentityPage
  };
  app.routes['campus.moderation'] = {
    path: '/campus/moderation',
    component: ModerationPage
  };
  app.routes['campus.records'] = {
    path: '/campus/records',
    component: RecordsPage
  };
  app.routes['campus.record'] = {
    path: '/campus/record/:id',
    component: RecordPage
  };
  app.routes['campus.publish'] = {
    path: '/campus/publish',
    component: PublishPage
  };
  app.routes['campus.courses'] = {
    path: '/campus/courses',
    component: CoursesPage
  };
  app.routes['campus.me'] = {
    path: '/campus/me',
    component: PersonalPage
  };
  app.routes['campus.search'] = {
    path: '/campus/search',
    component: SearchPage
  };
  app.routes['campus.cases'] = {
    path: '/campus/cases',
    component: CasesPage
  };
  app.routes['campus.governance'] = {
    path: '/campus/governance',
    component: GovernancePage
  };
  extend(HeaderPrimary.prototype, 'items', function (items) {
    items.add('campus', link('/campus', '校园首页', {
      className: 'Button Button--link'
    }), 100);
  });
  extend(IndexPage.prototype, 'contentItems', function (items) {
    if (!app.search.state.params().q) items.add('campus-entry', m('div.Campus-entry', link('/campus', '进入校园首页 · 资讯、资料与问答')), 110);
  });
});

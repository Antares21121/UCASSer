import app from 'flarum/forum/app';
import DiscussionList from 'flarum/forum/components/DiscussionList';
import DiscussionListState from 'flarum/forum/states/DiscussionListState';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';
import Navigation from 'flarum/common/components/Navigation';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from './button';
import { routeBoard } from './boards';
import { extend, override } from 'flarum/common/extend';
export function pagination({
  page,
  total,
  size = 20,
  hasNext,
  href,
  busy = false,
  label = '信息流分页',
  unit = '条'
}) {
  const last = total == null ? null : Math.max(1, Math.ceil(total / size));
  const current = Math.max(1, Number(page) || 1);
  const numbers = last ? [...new Set([1, ...[current - 1, current, current + 1].filter(n => n > 1 && n < last), last])].sort((a, b) => a - b) : [current];
  const control = (number, text, label, disabled = false) => disabled || busy ? m(Button, {
    disabled: true,
    'aria-label': label
  }, text) : m(LinkButton, {
    href: href(number),
    className: 'Button' + (number === current ? ' is-current' : ''),
    'aria-label': label,
    'aria-current': number === current ? 'page' : undefined
  }, text);
  return m('nav.Campus-feedPagination', {
    'aria-label': label,
    'aria-busy': busy
  }, [m('span.Campus-paginationCount', {
    role: 'status'
  }, total == null ? `第 ${current} 页` : `共 ${total} ${unit} · 第 ${current} / ${last} 页`), m('div.Campus-paginationControls', [control(current - 1, '上一页', '上一页', current === 1), ...numbers.flatMap((n, i) => [i && n > numbers[i - 1] + 1 ? m('span.Campus-paginationEllipsis', {
    'aria-hidden': 'true'
  }, '…') : null, control(n, String(n), `第 ${n} 页`)]), control(current + 1, '下一页', '下一页', last ? current >= last : !hasNext)])]);
}
export function registerNavigation() {
  extend(HeaderSecondary.prototype, 'items', items => {
    items.remove('locale');
    for (const item of items.toArray()) {
      if (item.itemName === 'signUp') item.attrs.icon = 'fas fa-user-plus';
      if (item.itemName === 'logIn') item.attrs.icon = 'fas fa-sign-in-alt';
    }
    if (items.has('campus-publish')) items.setPriority('campus-publish', 11);
  });
  // Home is the navigation root, even when reached from a discussion or tag.
  // Leave the history stack and the mobile drawer intact for subsequent pages.
  extend(Navigation.prototype, 'items', items => {
    const path = (m.route.get() || '/').split('?')[0].replace(/\/$/, '') || '/';
    if (['/', '/campus', '/all'].includes(path)) {
      items.remove('back');
      items.remove('pane');
    }
  });
  // The native state already supports isolated pages; use its exact visible
  // total for both server-preloaded and client-loaded results.
  override(DiscussionListState.prototype, 'loadPage', function (original, page) {
    const epoch = this.campusEpoch || 0;
    // Campus aliases have no server document: page 2 must use an offset even
    // before the first response has supplied the native state's page size.
    this.pageSize ||= DiscussionListState.DEFAULT_PAGE_SIZE;
    return original(page).then(results => {
      this.campusRequests ||= new WeakMap();
      this.campusRequests.set(results, epoch);
      if (epoch === (this.campusEpoch || 0)) {
        this.campusTotal = results.payload?.meta?.page?.total ?? null;
        this.campusPageSize = this.pageSize;
      }
      this.totalItems = this.campusTotal ?? null;
      if (this.campusPageSize) this.pageSize = this.campusPageSize;
      return results;
    });
  });
  override(DiscussionListState.prototype, 'parseResults', function (original, page, results) {
    if (this.campusRequests?.get(results) !== (this.campusEpoch || 0)) return;
    return original(page, results);
  });
  override(DiscussionListState.prototype, 'goto', function (original, page) {
    const epoch = this.campusEpoch = (this.campusEpoch || 0) + 1;
    this.location = {
      page
    };
    this.initialLoading = true;
    this.clear();
    return this.loadPage(page).then(results => {
      if (epoch !== this.campusEpoch) return;
      this.pages = [];
      this.parseResults(page, results);
    }).finally(() => {
      if (epoch === this.campusEpoch) {
        this.initialLoading = false;
        this.loadingPage = false;
        m.redraw();
      }
    });
  });
  override(DiscussionListState.prototype, 'revalidate', function (original) {
    if (this.revalidating) return this.revalidating;
    const epoch = this.campusEpoch || 0;
    const page = this.getLocation().page;
    this.revalidating = this.loadPage(page).then(results => {
      if (epoch !== (this.campusEpoch || 0)) return;
      this.pages = [];
      this.parseResults(page, results);
    }).catch(() => {}).finally(() => this.revalidating = null);
    return this.revalidating;
  });
  override(DiscussionList.prototype, 'view', function (original) {
    if (!this.attrs.state.isLoading()) return original();
    const board = routeBoard();
    return m('div.DiscussionList.Campus-feedLoading', {
      role: 'status',
      'aria-live': 'polite',
      'aria-busy': 'true'
    }, [m('span.sr-only', `正在加载${board?.title || '校园内容'}…`), m('div.Campus-feedSkeleton', {
      className: 'Campus-feedSkeleton--' + (board?.kind || 'conversation'),
      'aria-hidden': 'true'
    }, Array.from({
      length: 6
    }, (_, i) => m('div.Campus-skeletonRow', {
      key: i
    }, [m('span.Campus-skeletonMarker'), m('div.Campus-skeletonContent', [m('span.Campus-skeletonTitle'), m('span.Campus-skeletonMeta'), m('span.Campus-skeletonExcerpt')])])))]);
  });
  extend(DiscussionList.prototype, 'view', function (vnode) {
    const state = this.attrs.state;
    if (state.isLoading()) return;
    const page = Math.max(1, Number(m.route.param('page')) || 1);
    const params = {
      ...m.route.param()
    };
    const href = number => app.route(app.current.get('routeName'), {
      ...params,
      page: number > 1 ? number : undefined
    });
    const footer = pagination({
      page,
      total: state.totalItems,
      size: state.pageSize || 20,
      hasNext: state.hasNext(),
      href,
      busy: state.isLoading()
    });
    const children = vnode.children ||= [];
    const old = children.findIndex(child => child?.attrs?.className === 'DiscussionList-loadMore');
    if (old >= 0) children[old] = footer;else if (page > 1 || state.hasItems()) children.push(footer);
  });
  // Keep original tag navigation and responsive SelectDropdown. Headings sit
  // inside its item list so they travel with the phone dropdown as well.
  extend(IndexSidebar.prototype, 'navItems', items => {
    const heading = (key, text, priority) => items.add(key, m('span.Campus-sidebarHeading', {
      role: 'presentation'
    }, text), priority);
    heading('campus-content-heading', '内容浏览', 110);
    heading('campus-tools-heading', '资料与课程', -18);
    heading('campus-community-heading', '个人与社区', -22);
    if (app.routes['ucasser.resources']) items.add('campus-directory', m(LinkButton, {
      href: '/resources',
      icon: 'fas fa-folder-open',
      active: m.route.get().split('?')[0] === '/resources'
    }, '资料分类目录'), -19);
    items.add('campus-toolbox', m(LinkButton, {
      href: '/campus/tools',
      icon: 'fas fa-th-large',
      active: m.route.get().split('?')[0].startsWith('/campus/tools')
    }, '校园工具箱'), -20);
    if (app.session.user) items.add('campus-my-space', m(LinkButton, {
      href: '/campus/me',
      icon: 'far fa-user',
      active: m.route.get().split('?')[0] === '/campus/me'
    }, '我的校园空间'), -22.5);
    if (app.session.user?.isAdmin()) items.add('campus-control', m(LinkButton, {
      href: '/campus/control',
      icon: 'fas fa-tachometer-alt',
      active: m.route.get().split('?')[0] === '/campus/control'
    }, '站点控制台'), -23);
  });
}

import Button from 'flarum/common/components/Button';
import { DataPage, api, field, link, labels } from './records';
const select = (label, key, value, items, set) => m('label.Campus-field', [label, m('select.FormControl', {
  value,
  onchange: e => set(key, e.target.value)
}, [m('option', {
  value: ''
}, '全部'), ...items.map(([v, text]) => m('option', {
  value: v
}, text))])]);
export default class SearchPage extends DataPage {
  async fetch() {
    this.filters = {
      q: '',
      type: '',
      category: '',
      tag: '',
      status: '',
      course_id: '',
      semester: '',
      from: '',
      to: '',
      deadline_from: '',
      deadline_to: '',
      starts_from: '',
      starts_to: '',
      sort: 'latest',
      ...m.route.param()
    };
    const r = await api('search?' + new URLSearchParams(this.filters));
    this.items = r.data;
    this.meta = r.meta;
    this.catalog = (await api('catalog')).data;
    this.courses = [];
    if (this.catalog.sections.some(s => s.key === 'courses')) {
      try {
        this.courses = (await api('courses')).data;
      } catch (e) {
        if (e.status !== 401 && e.status !== 403) throw e;
      }
    }
  }
  content() {
    const f = this.filters,
      set = (k, v) => f[k] = v,
      cats = f.type ? this.catalog.categories[f.type] || {} : Object.assign({}, ...Object.values(this.catalog.categories));
    const dates = {
      from: '发布起始日期',
      to: '发布结束日期',
      deadline_from: '截止起始日期',
      deadline_to: '截止结束日期',
      starts_from: '活动起始日期',
      starts_to: '活动结束日期'
    };
    return [m('h1', '全站搜索与发现'), m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set('/campus/search', {
          ...f,
          page: 1
        });
      }
    }, [field('q', '搜索标题、正文与结构化字段', f.q, v => f.q = v), select('内容类型', 'type', f.type, [['discussion', '普通讨论'], ...Object.keys(this.catalog.categories).map(k => [k, labels[k]])], set), select('排序', 'sort', f.sort, [['latest', '最新发布'], ['updated', '最近更新'], ['relevance', '标题相关优先'], ['deadline', '截止日期'], ['activity', '活动时间']], set), m('details', [m('summary', '更多筛选'), select('分类', 'category', f.category, Object.entries(cats), set), select('分区', 'tag', f.tag, this.catalog.sections.map(s => [s.slug, s.name]), set), select('状态', 'status', f.status, [...new Set(Object.values(this.catalog.states).flat().concat('expired'))].map(k => [k, labels[k]]), set), select('课程', 'course_id', f.course_id, this.courses.map(c => [c.id, c.name + ' · ' + c.semester]), set), field('semester', '评价学期', f.semester, v => f.semester = v), ...Object.entries(dates).map(([k, label]) => field(k, label, f[k], v => f[k] = v, {
      type: 'date'
    }))]), m(Button, {
      type: 'submit'
    }, '搜索内容')]), m('p', this.meta.relevance), this.items.length ? this.items.map(d => m('article.Campus-panel', [m('h2', link(d.record_id ? '/campus/record/' + d.record_id : '/d/' + d.id, d.title)), m('small', (labels[d.type] || '普通讨论') + ' · ' + d.author + ' · ' + d.updated_at), m('p.Campus-text', d.summary), m('p', d.tags.join(' / '))])) : m('p.Campus-empty', '没有匹配结果。可尝试缩短关键词、清空分类或日期限制。'), m('nav.Campus-nav', [this.meta.page > 1 ? link('/campus/search?' + new URLSearchParams({
      ...f,
      page: this.meta.page - 1
    }), '上一页') : null, this.meta.page * 20 < this.meta.total ? link('/campus/search?' + new URLSearchParams({
      ...f,
      page: this.meta.page + 1
    }), '下一页') : null, m('span', `共 ${this.meta.total} 条，每页最多 20 条`)])];
  }
}

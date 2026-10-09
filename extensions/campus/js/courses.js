import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import { DataPage, api, field, link, labels } from './records';
const fields = {
  name: '课程名称',
  type: '课程类型',
  grade: '适用年级',
  semester: '开课学期',
  nature: '课程性质',
  assessment: '考核方式',
  resources: '相关资源编号或来源说明'
};
export default class CoursesPage extends DataPage {
  async fetch() {
    this.filters = {
      search: m.route.param('search') || '',
      type: m.route.param('type') || '',
      semester: m.route.param('semester') || '',
      grade: m.route.param('grade') || '',
      review_semester: m.route.param('review_semester') || ''
    };
    const r = await api('courses?' + new URLSearchParams(m.route.param()));
    this.courses = r.data;
    this.meta = r.meta;
    this.definitions = r.definitions;
  }
  content() {
    return [m('h1', '课程评价与学习经验'), m('p', '使用正常账号评价。评分定义固定，样本数量只反映当前可见且未撤回的评价，不对教师进行个人排名。'), m('form.Campus-panel', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set('/campus/courses', this.filters);
      }
    }, Object.entries(this.filters).map(([k, v]) => field(k, k === 'search' ? '搜索课程名称' : k === 'review_semester' ? '统计评价学期（留空统计全部）' : fields[k], v, value => this.filters[k] = value)).concat(m(Button, {
      type: 'submit'
    }, '查询课程'))), ...this.courses.map(c => m('article.Campus-panel', [m('h2', c.name), m('p', `${c.type} · ${c.grade} · ${c.semester} · ${c.nature}`), m('p', '考核方式：' + c.assessment), m('p', '资源：' + c.resources), m('p', `有效样本 ${c.samples} 条`), ...Object.entries(c.scores).map(([k, v]) => m('p', `${this.definitions[k]}：${v === null ? '暂无样本' : v}`)), m('p', this.definitions.sample), m('p', [link('/campus/records?type=review&course_id=' + c.id, '查看评价'), ' · ', app.session.user ? link('/campus/publish?type=review&course_id=' + c.id + '&semester=' + encodeURIComponent(c.semester), '发布评价') : null]), this.meta.can_manage ? m(Button, {
      onclick: () => this.form = {
        ...c,
        reason: ''
      }
    }, '编辑课程') : null])), this.courses.length ? null : m('p.Campus-empty', '暂无匹配课程。课程维护人员可添加记录。'), this.meta.total > 20 ? m('nav.Campus-nav', [Number(this.meta.page) > 1 ? link('/campus/courses?' + new URLSearchParams({
      ...m.route.param(),
      page: Number(this.meta.page) - 1
    }), '上一页') : null, Number(this.meta.page) * 20 < this.meta.total ? link('/campus/courses?' + new URLSearchParams({
      ...m.route.param(),
      page: Number(this.meta.page) + 1
    }), '下一页') : null]) : null, this.meta.can_manage ? m(Button, {
      onclick: () => this.form = {
        name: '',
        type: '',
        grade: '',
        semester: '',
        nature: '',
        assessment: '',
        resources: '',
        reason: ''
      }
    }, '添加课程') : null, this.form ? m('form.Campus-panel', {
      onsubmit: async e => {
        e.preventDefault();
        try {
          await api(this.form.id ? 'courses/' + this.form.id : 'courses', this.form.id ? 'PATCH' : 'POST', this.form);
          this.form = null;
          this.notice = '课程已保存并记录操作理由';
          await this.fetch();
        } catch (_) {
          this.notice = '保存失败，请检查权限、字段或重新加载版本。';
        }
        m.redraw();
      }
    }, [m('h2', this.form.id ? '编辑课程' : '添加课程'), ...Object.entries(fields).map(([k, label]) => field(k, label, this.form[k], v => this.form[k] = v, {
      required: ['name', 'type', 'semester'].includes(k)
    })), field('reason', '维护理由', this.form.reason, v => this.form.reason = v, {
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit'
    }, '保存课程')]) : null];
  }
}

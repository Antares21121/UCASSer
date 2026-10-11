import app from 'flarum/forum/app';
import Button from './button';
import { DataPage, api, field, link, labels } from './records';
import { pageHeading, emptyState } from './ui';
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
      search: this.params['search'] || '',
      type: this.params['type'] || '',
      semester: this.params['semester'] || '',
      grade: this.params['grade'] || '',
      review_semester: this.params['review_semester'] || ''
    };
    const r = await api('courses?' + new URLSearchParams(this.params));
    this.courses = r.data;
    this.meta = r.meta;
    this.definitions = r.definitions;
  }
  content() {
    const addCourse = this.meta.can_manage ? m(Button, {
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
    }, '添加课程') : null;
    return [pageHeading('课程评价与学习经验', '使用正常账号分享体验。均值仅来自可见、未撤回的评价，少量样本不能代表课程全貌。', addCourse), m('form.Campus-panel.Campus-filterGrid', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set('/campus/courses', this.filters);
      }
    }, Object.entries(this.filters).map(([k, v]) => field(k, k === 'search' ? '搜索课程名称' : k === 'review_semester' ? '统计评价学期（留空统计全部）' : fields[k], v, value => this.filters[k] = value)).concat(m(Button, {
      type: 'submit'
    }, '查询课程'), link('/campus/courses', '清空筛选'))), ...this.courses.map(c => m('article.Campus-panel.Campus-courseCard', [m('h2', c.name), m('p.Campus-meta', [c.type, c.grade, c.semester, c.nature].filter(Boolean).join(' · ')), c.assessment ? m('p', '考核方式：' + c.assessment) : null, c.resources ? m('p', '资源：' + c.resources) : null, m('div.Campus-badges', [m('span.Campus-badge', `有效样本 ${c.samples} 条`)]), m('div.Campus-stats', Object.entries(c.scores).map(([k, v]) => m('div.Campus-stat', [m('strong', v === null ? '—' : Number(v).toFixed(2)), m('span', {
      title: this.definitions[k]
    }, {
      overall: '总体体验',
      difficulty: '难度',
      workload: '工作量'
    }[k]), m('small.Campus-hint', this.definitions[k])]))), m('p.Campus-hint', this.definitions.sample), m('div.Campus-actions', [link('/campus/records?type=review&course_id=' + c.id + (this.filters.review_semester ? '&semester=' + encodeURIComponent(this.filters.review_semester) : ''), '查看评价', {
      className: 'Button'
    }), app.session.user ? link('/campus/publish?type=review&course_id=' + c.id + '&semester=' + encodeURIComponent(this.filters.review_semester || c.semester), '发布评价', {
      className: 'Button Button--primary'
    }) : null]), this.meta.can_manage ? m(Button, {
      onclick: () => this.form = {
        ...c,
        reason: ''
      }
    }, '编辑课程') : null])), this.courses.length ? null : emptyState('暂无匹配课程', '请调整筛选条件，或联系课程维护人员添加记录。', link('/campus/courses', '清空筛选')), this.meta.total > 20 ? m('nav.Campus-pagination', {
      'aria-label': '课程分页'
    }, [Number(this.meta.page) > 1 ? link('/campus/courses?' + new URLSearchParams({
      ...m.route.param(),
      page: Number(this.meta.page) - 1
    }), '上一页') : null, Number(this.meta.page) * 20 < this.meta.total ? link('/campus/courses?' + new URLSearchParams({
      ...m.route.param(),
      page: Number(this.meta.page) + 1
    }), '下一页') : null]) : null, this.form ? m('form.Campus-panel', {
      oncreate: v => {
        v.dom.scrollIntoView({
          block: 'start'
        });
        v.dom.querySelector('input')?.focus({
          preventScroll: true
        });
      },
      onsubmit: async e => {
        e.preventDefault();
        const savedName = this.form.name;
        this.perform(() => api(this.form.id ? 'courses/' + this.form.id : 'courses', this.form.id ? 'PATCH' : 'POST', this.form), {
          message: '课程已保存并记录操作理由',
          after: () => {
            this.form = null;
            m.route.set('/campus/courses', {
              search: savedName
            });
          }
        });
      }
    }, [m('h2', this.form.id ? '编辑课程' : '添加课程'), ...Object.entries(fields).map(([k, label]) => field(k, label, this.form[k], v => this.form[k] = v, {
      required: ['name', 'type', 'semester'].includes(k),
      maxlength: ['assessment', 'resources'].includes(k) ? 2000 : 80
    })), field('reason', '维护理由', this.form.reason, v => this.form.reason = v, {
      required: true,
      minlength: 5,
      maxlength: 500
    }), m(Button, {
      type: 'submit'
    }, '保存课程'), m(Button, {
      type: 'button',
      onclick: () => this.form = null
    }, '取消编辑')]) : null];
  }
}

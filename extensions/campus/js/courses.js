import app from 'flarum/forum/app';
import Button from './button';
import { DataPage, api, field, link } from './records';
import { emptyState, symbol } from './ui';
import { BoardHero, boards } from './boards';
import { pagination } from './navigation';
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
      },
      className: 'Button Button--link',
      icon: 'fas fa-plus'
    }, '添加课程') : null;
    const advancedCount = ['grade', 'review_semester'].filter(key => this.filters[key]).length;
    return [m('section.Campus-nativeFilters.Campus-courseFilters', {
      'aria-label': '筛选课程'
    }, m('form.Campus-searchForm', {
      onsubmit: e => {
        e.preventDefault();
        m.route.set('/campus/courses', {
          ...this.filters,
          page: 1
        });
      }
    }, [m('div.Campus-searchBar', [m('label.Campus-searchInput', [m('span.sr-only', '搜索课程名称'), symbol('search'), m('input.FormControl', {
      name: 'search',
      type: 'search',
      placeholder: '搜索课程名称、代码或学院',
      value: this.filters.search,
      oninput: e => this.filters.search = e.target.value
    })]), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '查询课程')]), m('div.Campus-searchOptions', ['type', 'semester'].map(key => field(key, fields[key], this.filters[key], value => this.filters[key] = value, {
      placeholder: key === 'type' ? '全部类型' : '全部学期'
    }))), m('div.Campus-searchRefinements', [m('details.Campus-searchAdvanced', [m('summary', [symbol('sliders-h'), '更多筛选', advancedCount ? m('span.Campus-badge', advancedCount) : null]), m('div.Campus-searchAdvancedFields', [field('grade', '适用年级', this.filters.grade, value => this.filters.grade = value, {
      placeholder: '全部年级'
    }), field('review_semester', '统计评价学期', this.filters.review_semester, value => this.filters.review_semester = value, {
      placeholder: '留空统计全部学期'
    })]), m('small.Campus-hint', '统计评价学期只影响评分样本，不限制开课学期。'), m(Button, {
      type: 'submit',
      className: 'Button Button--primary'
    }, '应用更多筛选')]), link('/campus/courses', '清空筛选', {
      className: 'Button Button--link Campus-searchReset'
    })])])), m('div.Campus-courseResults', [m('p', {
      role: 'status'
    }, `共 ${this.meta.total} 门课程`), addCourse]), m('p.Campus-hint.Campus-courseScope', this.definitions.sample), ...this.courses.map(c => m('article.Campus-panel.Campus-courseCard', [m('header.Campus-courseHeading', [m('h2', c.name), m('span.Campus-badge', `${c.samples} 条有效评价`)]), m('p.Campus-meta', [c.offer?.college, c.offer?.code ? `课程代码 ${c.offer.code}` : null, c.type, c.grade, c.semester, c.nature].filter(Boolean).join(' · ')), m('div.Campus-stats', Object.entries(c.scores).map(([k, v]) => m('div.Campus-stat', [m('span', {
      title: this.definitions[k]
    }, {
      overall: '总体体验',
      difficulty: '难度',
      workload: '工作量'
    }[k]), m('strong', v === null ? '—' : Number(v).toFixed(2)), v === null ? null : m('small', ' / 5')]))), c.assessment || c.resources ? m('details.Campus-courseDetails', [m('summary', '考核与相关资源'), c.assessment ? m('p.Campus-text', '考核方式：' + c.assessment) : null, c.resources ? m('p.Campus-text', '资源：' + c.resources) : null]) : null, m('div.Campus-actions', [link('/campus/records?type=review&course_id=' + c.id + (this.filters.review_semester ? '&semester=' + encodeURIComponent(this.filters.review_semester) : ''), '查看评价', {
      className: 'Button'
    }), c.offer?.resource_category_id ? link('/resources?category_id=' + c.offer.resource_category_id, '查看资料索引', {
      className: 'Button'
    }) : null, app.session.user ? link('/campus/publish?type=review&course_id=' + c.id + '&semester=' + encodeURIComponent(this.filters.review_semester || c.semester), '发布评价', {
      className: 'Button Button--primary'
    }) : null]), this.meta.can_manage ? m(Button, {
      onclick: () => this.form = {
        ...c,
        reason: ''
      }
    }, '编辑课程') : null])), this.courses.length ? null : emptyState('暂无匹配课程', '请调整筛选条件，或联系课程维护人员添加记录。', link('/campus/courses', '重置课程筛选')), this.meta.total > this.meta.page_size ? pagination({
      page: this.meta.page,
      total: this.meta.total,
      size: this.meta.page_size,
      unit: '门课程',
      label: '课程分页',
      href: page => '/campus/courses?' + new URLSearchParams({
        ...this.params,
        page
      })
    }) : null, this.form ? m('form.Campus-panel', {
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
  hero() {
    return m(BoardHero, {
      board: {
        ...boards.courses,
        title: '课程目录',
        tabs: undefined,
        secondary: undefined,
        action: '浏览课程评价',
        actionIcon: 'fas fa-arrow-right',
        subtitle: '按课程查找学习经验，参考同学分享的评价与建议。'
      },
      publish: () => m.route.set('/t/campus-courses')
    });
  }
}

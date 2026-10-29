import app from 'flarum/forum/app';
import TextEditor from 'flarum/common/components/TextEditor';
import { extend } from 'flarum/common/extend';
import { api, errorMessage, symbol } from './ui';

const isMessageEditor = editor => typeof editor.attrs.composer?.body?.componentClass?.prototype?.recipientUnavailable === 'function';

export default function registerChatEditor() {
  extend(TextEditor.prototype, 'toolbarItems', function (items) {
    if (!isMessageEditor(this)) return;
    items.add('campus-chat-image', m('label.Campus-chatImageButton', {
      title: '发送图片',
      'aria-label': '发送图片',
      'aria-busy': Boolean(this.campusUploading)
    }, [symbol('image'), m('span', this.campusUploading ? '上传中…' : '图片'), m('input', {
      type: 'file', accept: 'image/png,image/jpeg,image/webp',
      disabled: this.campusUploading || this.attrs.disabled,
      onchange: async event => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
          app.alerts.show({ type: 'error' }, '图片不能超过 2 MB，请压缩后再发送。');
          return;
        }
        this.campusUploading = true;
        m.redraw();
        try {
          const data = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error('读取图片失败'));
            reader.readAsDataURL(file);
          });
          const response = await api('chat-images', 'POST', { data });
          const url = new URL(response.data.url, app.forum.attribute('baseUrl')).href;
          this.attrs.composer.editor?.insertAtCursor(`\n![图片](${url})\n`);
          this.attrs.composer.editor?.focus();
        } catch (error) {
          app.alerts.show({ type: 'error' }, errorMessage(error, '图片上传失败，请重试。'));
        } finally {
          this.campusUploading = false;
          m.redraw();
        }
      }
    })]), 1000);
  });
}

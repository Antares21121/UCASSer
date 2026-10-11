#!/usr/bin/env python3
"""Local-only mailbox for native registration, confirmation and reset development."""
import argparse
import html
import json
import threading
from email import policy
from email.parser import BytesParser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import sys
from forum import Forum, ROOT, write_private
sys.path.insert(0,str(ROOT/'tests'))
from mail_sink import Sink

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend',choices=['native','docker'],default='native')
    parser.add_argument('--env-file',default=str(ROOT/'.env'))
    parser.add_argument('--smtp-port',type=int,default=8025)
    parser.add_argument('--web-port',type=int,default=8026)
    parser.add_argument('--restore',action='store_true',help='Restore SMTP settings after an interrupted mailbox run')
    parser.add_argument('--check',action='store_true',help='Send a local probe, check the reader and restore settings immediately')
    args=parser.parse_args(); args.profile='development'; f=Forum(args); f.validate()
    if f.values.get('APP_ENV')!='development': raise ValueError('Mailbox is development-only')
    folder=ROOT/'.runtime/dev-mail'; folder.mkdir(parents=True,exist_ok=True)
    source,previous,saved=folder/'config.json',folder/'previous.json',folder/'saved.json'
    def configure(values):
        write_private(source,json.dumps(values)); f.php('scripts/campus.php','mail',root=args.backend=='docker')
        return json.loads(previous.read_text(encoding='utf-8'))
    if args.restore:
        configure(json.loads(saved.read_text(encoding='utf-8')))
        for path in (source,previous,saved): path.unlink(missing_ok=True)
        print('Original SMTP settings restored'); return
    if saved.exists(): raise ValueError('Previous run interrupted; first run this command with --restore')
    # Docker bridge clients connect through host.docker.internal; the web reader always binds loopback.
    with Sink('0.0.0.0' if args.backend=='docker' else '127.0.0.1',args.smtp_port,limit=30) as sink:
        class Mailbox(BaseHTTPRequestHandler):
            def log_message(self,*_): pass
            def do_GET(self):
                if self.path!='/': self.send_error(404); return
                parts=['<meta charset="utf-8"><title>本地开发邮箱</title><h1>本地开发邮箱</h1><p>刷新查看邮件；只保留最近 30 封，停止后清除。不会向外部投递。</p>']
                for raw in reversed(sink.messages[-30:]):
                    message=BytesParser(policy=policy.default).parsebytes(raw)
                    body=message.get_body(preferencelist=('plain',))
                    text=body.get_content() if body else '(没有纯文本正文)'
                    parts.append('<article><h2>'+html.escape(str(message['Subject'] or '邮件'))+'</h2><pre style="white-space:pre-wrap">'+html.escape(text)+'</pre></article>')
                content=''.join(parts).encode(); self.send_response(200)
                self.send_header('Content-Type','text/html; charset=utf-8'); self.send_header('Cache-Control','no-store')
                self.send_header('Content-Security-Policy',"default-src 'none'; style-src 'unsafe-inline'")
                self.end_headers(); self.wfile.write(content)
        web=ThreadingHTTPServer(('127.0.0.1',args.web_port),Mailbox)
        try:
            old=configure({'mail_driver':'smtp','mail_host':'host.docker.internal' if args.backend=='docker' else '127.0.0.1','mail_port':str(args.smtp_port),'mail_encryption':'','mail_username':'','mail_password':'','mail_from':'forum@example.invalid'})
            write_private(saved,json.dumps(old))
            print(f'Development mailbox: http://127.0.0.1:{args.web_port}; Ctrl+C restores previous SMTP settings',flush=True)
            if args.check:
                import smtplib, urllib.request
                thread=threading.Thread(target=web.serve_forever,daemon=True); thread.start()
                try:
                    with smtplib.SMTP('127.0.0.1',args.smtp_port,timeout=10) as smtp:
                        smtp.sendmail('forum@example.invalid',['developer@example.invalid'],'Subject: Development mailbox probe\r\n\r\nLocal-only verification, never delivered externally.')
                    response=urllib.request.urlopen(f'http://127.0.0.1:{args.web_port}/',timeout=10)
                    assert b'Local-only verification' in response.read()
                    print('PASS: SMTP capture, local reader and settings restoration')
                finally: web.shutdown(); thread.join(timeout=2)
            else: web.serve_forever()
        except KeyboardInterrupt: pass
        finally:
            web.server_close()
            if saved.exists():
                configure(json.loads(saved.read_text(encoding='utf-8')))
                for path in (source,previous,saved): path.unlink(missing_ok=True)

if __name__=='__main__': main()

"""Local SMTP acceptance sink; messages stay in process memory and are never delivered."""
import socketserver
import threading

class Sink(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True
    def __init__(self, host='127.0.0.1', port=0, limit=100):
        super().__init__((host, port), Handler)
        self.messages = []
        self.limit = limit
    def __enter__(self):
        self.thread = threading.Thread(target=self.serve_forever, daemon=True)
        self.thread.start()
        return self
    def __exit__(self, *_):
        self.shutdown(); self.server_close(); self.thread.join(timeout=2)

def local_mail(f):
    return Sink('0.0.0.0' if f.args.backend=='docker' else '127.0.0.1')

def mail_host(f):
    return 'host.docker.internal' if f.args.backend=='docker' else '127.0.0.1'

class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(20)
        self.wfile.write(b'220 local development sink\r\n')
        while True:
            line=self.rfile.readline()
            if not line: return
            verb=line.split(b' ',1)[0].strip().upper()
            if verb in (b'EHLO',b'HELO'): self.wfile.write(b'250 local\r\n')
            elif verb==b'DATA':
                self.wfile.write(b'354 send data\r\n'); chunks=[]
                while True:
                    data=self.rfile.readline()
                    if data in (b'.\r\n',b''): break
                    chunks.append(data)
                self.server.messages.append(b''.join(chunks))
                del self.server.messages[:-self.server.limit]
                self.wfile.write(b'250 captured locally\r\n')
            elif verb==b'QUIT': self.wfile.write(b'221 goodbye\r\n'); return
            else: self.wfile.write(b'250 ok\r\n')

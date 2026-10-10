#!/usr/bin/env python3
"""Cross-platform Phase 0 launcher; Python 3.11+, standard library only."""
import argparse
import contextlib
import datetime as dt
import getpass
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = "<?php\nreturn require __DIR__.'/config/environment.php';\n"


def read_env(path):
    result = {}
    for number, raw in enumerate(path.read_text(encoding='utf-8-sig').splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        if '=' not in line:
            raise ValueError(f'Invalid env line {number}')
        key, value = line.split('=', 1)
        if not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
            raise ValueError(f'Invalid env key on line {number}')
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        result[key] = value
    return result


def write_private(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8', newline='\n')
    path.chmod(0o600)


def write_env(path, values):
    for value in values.values():
        if '\n' in value or '\r' in value:
            raise ValueError('Multiline env values are unsupported')
    write_private(path, ''.join(f'{k}={v}\n' for k, v in values.items()))


def sha256(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def safe_members(archive):
    seen = set()
    for info in archive.infolist():
        name = info.filename
        if (name in seen or '\\' in name or name.startswith('/') or ':' in name
                or '..' in Path(name).parts or (info.external_attr >> 16) & 0o170000 == 0o120000):
            raise ValueError('Unsafe or duplicate archive member')
        seen.add(name)
    return seen


class Forum:
    def __init__(self, args):
        self.args = args
        self.root = ROOT
        self.env_path = Path(args.env_file).resolve()
        self.values = read_env(self.env_path) if self.env_path.exists() else {}
        self.runtime = self.root / '.runtime'
        self.tools = self.root / '.tools'
        self.runtime.mkdir(exist_ok=True)

    def environment(self):
        values = dict(self.values)
        if self.args.backend == 'native':
            values['DB_HOST'] = '127.0.0.1'
            values['DB_PORT'] = values.get('NATIVE_DB_PORT', '3307')
        return {**os.environ, **values, 'FORUM_ENV_FILE': str(self.env_path)}

    def validate(self):
        if self.args.backend == 'native' and self.args.profile != 'development':
            raise ValueError('Native backend cannot use production profile')
        required = ['APP_URL', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_ROOT_PASSWORD']
        missing = [key for key in required if not self.values.get(key)]
        if missing:
            raise ValueError('Missing variables: ' + ', '.join(missing) + '; run setup')
        for key in ['DB_NAME', 'DB_USER', 'DB_PREFIX', 'COMPOSE_PROJECT_NAME']:
            pattern = r'[A-Za-z0-9_-]+' if key == 'COMPOSE_PROJECT_NAME' else r'[A-Za-z0-9_]+' if key != 'DB_PREFIX' else r'[A-Za-z0-9_]*'
            if not re.fullmatch(pattern, self.values.get(key, '')):
                raise ValueError('Invalid identifier: ' + key)
        if self.values.get('APP_DEBUG') not in ('true', 'false'):
            raise ValueError('APP_DEBUG must be true or false')
        if self.values.get('APP_ENV') == 'production':
            if not self.values['APP_URL'].startswith('https://') or self.values['APP_DEBUG'] != 'false':
                raise ValueError('Production requires HTTPS and APP_DEBUG=false')
            if not re.fullmatch(r'v?2\.\d+\.\d+', self.core_version()):
                raise ValueError('Production blocked: wait for a stable Flarum 2.x release; RC/beta/dev is development-only')
        if self.args.backend == 'native' and (self.values.get('APP_ENV') != 'development' or self.values.get('HTTP_BIND') != '127.0.0.1'):
            raise ValueError('Native backend supports loopback development only')

    def core_version(self):
        locked = json.loads((self.root / 'composer.lock').read_text(encoding='utf-8'))
        for package in locked.get('packages', []):
            if package['name'] == 'flarum/core':
                return package['version']
        raise ValueError('Missing locked flarum/core version')

    def run(self, command, *, capture=False, stdin=None, stdout=None, cwd=None):
        try:
            proc = subprocess.run([str(item) for item in command], cwd=cwd or self.root,
                                  env=self.environment(), input=stdin, stdout=subprocess.PIPE if capture else stdout,
                                  stderr=subprocess.PIPE, check=False)
        except FileNotFoundError:
            raise RuntimeError('Required executable missing: ' + str(command[0])) from None
        if proc.returncode:
            # Error text can contain DSNs/passwords. Keep local log, redact known secrets.
            text = proc.stderr.decode('utf-8', errors='replace')
            for key in ('DB_PASSWORD', 'DB_ROOT_PASSWORD', 'MAIL_PASSWORD'):
                if self.values.get(key):
                    text = text.replace(self.values[key], '[REDACTED]')
            write_private(self.runtime / 'last-error.log', text)
            raise RuntimeError(f'Command failed ({proc.returncode}); see .runtime/last-error.log')
        return proc.stdout if capture else b''

    def compose(self, *command, **kwargs):
        file = 'compose.production.yaml' if self.args.profile == 'production' else 'compose.yaml'
        return self.run(['docker', 'compose', '--env-file', self.env_path, '-f', self.root / file, *command], **kwargs)

    def php_path(self):
        portable = self.tools / 'php' / 'php.exe'
        return portable if portable.exists() else shutil.which('php') or 'php'

    def php(self, *command, **kwargs):
        root = kwargs.pop('root', False)
        if self.args.backend == 'docker':
            return self.compose('exec', '-T', '-u', '0' if root else 'www-data', 'app', 'php', *command, **kwargs)
        return self.run([self.php_path(), *command], **kwargs)

    def composer(self, *command):
        if self.args.backend == 'docker':
            self.compose('run', '--rm', '--no-deps', '-u', '0', 'app', 'composer', *command)
        else:
            self.run([self.php_path(), self.tools / 'composer.phar', *command])

    def doctor(self):
        print(f'OS: {sys.platform}; Python: {sys.version.split()[0]}; workspace: {self.root}')
        for name in ('git', 'docker', 'php', 'composer'):
            print(f'{name}: {shutil.which(name) or "not on PATH"}')
        if self.args.backend == 'docker':
            self.run(['docker', 'version'], capture=True)
            self.run(['docker', 'compose', 'version'])
        else:
            if sys.platform != 'win32':
                raise ValueError('Native portable backend currently supports Windows x64 only; use Docker on Linux/macOS')
            self.run([self.php_path(), '-v'])
            self.run([self.php_path(), '-r', '$r=["curl","dom","fileinfo","gd","json","mbstring","openssl","pdo_mysql","tokenizer","zip"]; foreach($r as $e) { if(!extension_loaded($e)) {fwrite(STDERR,"Missing ".$e);exit(1);} } echo "PHP extensions OK\\n";'])
        print('Doctor OK')

    def bootstrap(self):
        if sys.platform != 'win32':
            raise ValueError('bootstrap-native requires Windows x64')
        lock = json.loads((self.root / 'scripts/runtime-lock.json').read_text())
        self.tools.mkdir(exist_ok=True)
        for name, entry in lock.items():
            target = self.tools / (name + '.zip' if name != 'composer' else 'composer.phar')
            if not target.exists() or sha256(target) != entry['sha256']:
                print(f'Downloading verified {name} {entry["version"]} ...', flush=True)
                temporary = target.with_suffix('.download')
                with urllib.request.urlopen(entry['url'], timeout=60) as response, temporary.open('wb') as output:
                    shutil.copyfileobj(response, output)
                if sha256(temporary) != entry['sha256']:
                    raise ValueError('SHA256 mismatch: ' + name)
                temporary.replace(target)
            if name != 'composer':
                destination = self.tools / name
                marker = destination / '.version'
                if marker.exists() and marker.read_text() != entry['version']:
                    raise ValueError('Runtime version changed; use a fresh tools directory, never overwrite a running runtime')
                if not marker.exists():
                    destination.mkdir(exist_ok=True)
                    with zipfile.ZipFile(target) as archive:
                        safe_members(archive)
                        archive.extractall(destination)
                    marker.write_text(entry['version'])
        # Export the OS trust store, avoiding unverified third-party CA bundles.
        import ssl
        context = ssl.create_default_context()
        certs = context.get_ca_certs(binary_form=True)
        (self.tools / 'cacert.pem').write_text(''.join(ssl.DER_cert_to_PEM_cert(cert) for cert in certs), encoding='ascii')
        php_dir = (self.tools / 'php').as_posix()
        ca = (self.tools / 'cacert.pem').as_posix()
        ini = (self.root / 'docker/php/php.ini').read_text().replace('error_log=/dev/stderr', 'error_log="' + (self.runtime / 'php-error.log').as_posix() + '"')
        ini += f'\nextension_dir="{php_dir}/ext"\nopenssl.cafile="{ca}"\ncurl.cainfo="{ca}"\n'
        ini += ''.join(f'extension={name}\n' for name in ('curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'zip', 'intl'))
        (self.tools / 'php/php.ini').write_text(ini, encoding='utf-8')
        self.doctor()

    def setup(self):
        if not self.env_path.exists():
            self.values = read_env(self.root / '.env.example')
        for key in ('DB_PASSWORD', 'DB_ROOT_PASSWORD'):
            if not self.values.get(key):
                self.values[key] = secrets.token_hex(24)
        write_env(self.env_path, self.values)
        self.validate()
        if self.args.backend == 'docker':
            self.compose('config', '--quiet')
            self.compose('build')
            if self.args.profile == 'production':
                print('Production images built using composer.lock; bootstrap the empty database using the development profile before switching')
                return
        elif not (self.tools / 'php/php.exe').exists():
            self.bootstrap()
        lock_exists = (self.root / 'composer.lock').exists()
        if not lock_exists and self.values.get('APP_ENV') == 'production':
            raise ValueError('Production requires a reviewed composer.lock')
        self.composer('install' if lock_exists else 'update', '--prefer-dist', '--no-dev', '--no-interaction', '--no-plugins', '--no-scripts')
        if self.args.backend == 'docker':
            # Named volumes hide the checkout's tracked placeholder directories.
            # mkdir is idempotent and retains all existing application data.
            self.compose('run', '--rm', '--no-deps', '-u', '0', 'app', 'sh', '-c',
                         'mkdir -p storage/cache storage/formatter storage/less storage/locale storage/logs storage/sessions storage/tmp storage/views public/assets/avatars && chown -R www-data:www-data storage public/assets')
        print('Setup OK; passwords stored privately in env file; next: start, install')

    def db_bin(self, name):
        candidates = list((self.tools / 'mariadb').glob('*/bin/' + name + '.exe'))
        if len(candidates) != 1:
            raise RuntimeError('Portable MariaDB missing; run bootstrap-native')
        return candidates[0]

    @contextlib.contextmanager
    def db_credentials(self, root=False, database=True):
        values = self.environment()
        content = '[client]\nprotocol=tcp\nhost=127.0.0.1\n'
        content += f'port={values["DB_PORT"]}\nuser={"root" if root else values["DB_USER"]}\n'
        password = values['DB_ROOT_PASSWORD' if root else 'DB_PASSWORD']
        escaped = password.replace('\\', '\\\\').replace('"', '\\"')
        content += f'password="{escaped}"\n'
        fd, path = tempfile.mkstemp(prefix='db-', suffix='.cnf', dir=self.runtime)
        os.close(fd)
        path = Path(path)
        write_private(path, content)
        try:
            yield ['--defaults-extra-file=' + str(path)] + ([values['DB_NAME']] if database else [])
        finally:
            path.unlink(missing_ok=True)

    def process_running(self, pid):
        if sys.platform == 'win32':
            import ctypes
            from ctypes import wintypes
            kernel = ctypes.WinDLL('kernel32', use_last_error=True)
            kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
            kernel.OpenProcess.restype = wintypes.HANDLE
            kernel.GetExitCodeProcess.argtypes = [wintypes.HANDLE, ctypes.POINTER(wintypes.DWORD)]
            kernel.CloseHandle.argtypes = [wintypes.HANDLE]
            handle = kernel.OpenProcess(0x1000, False, pid)
            if not handle:
                return False
            try:
                code = wintypes.DWORD()
                return bool(kernel.GetExitCodeProcess(handle, ctypes.byref(code))) and code.value == 259
            finally:
                kernel.CloseHandle(handle)
        try:
            os.kill(pid, 0)
            return True
        except OSError:
            return False

    def owned_process(self, pid, executable):
        if not self.process_running(pid):
            return False
        if sys.platform == 'win32':
            command = f'(Get-CimInstance Win32_Process -Filter "ProcessId = {int(pid)}").CommandLine'
            result = self.run(['powershell.exe', '-NoProfile', '-Command', command], capture=True).decode(errors='replace')
            if str(self.root).lower() not in result.lower() or executable.lower() not in result.lower():
                raise ValueError('PID belongs to an unrelated process; refusing to signal it')
        return True

    def start_process(self, name, command):
        pid_file = self.runtime / (name + '.pid')
        if pid_file.exists():
            pid = int(pid_file.read_text())
            if self.owned_process(pid, 'php' if name == 'web' else 'mariadbd'):
                print(name + ' already running')
                return
            pid_file.unlink()
        with (self.runtime / (name + '.log')).open('ab') as log:
            options = {'creationflags': subprocess.CREATE_NO_WINDOW} if sys.platform == 'win32' else {'start_new_session': True}
            process_env = self.environment()
            if name == 'web':
                process_env['DB_ROOT_PASSWORD'] = ''
            proc = subprocess.Popen([str(c) for c in command], cwd=self.root, env=process_env,
                                    stdout=log, stderr=log, stdin=subprocess.DEVNULL, **options)
        time.sleep(0.5)
        if proc.poll() is not None:
            raise RuntimeError(name + ' exited during startup; see .runtime/' + name + '.log')
        pid_file.write_text(str(proc.pid))

    def start(self):
        self.validate()
        if self.args.backend == 'docker':
            self.compose('up', '-d', '--wait')
            return
        data = self.runtime / 'db'
        new_database = not data.exists()
        if new_database:
            # Generated local password is never printed; only used by official initializer.
            self.run([self.db_bin('mysql_install_db'), '--datadir=' + str(data),
                      '--password=' + self.values['DB_ROOT_PASSWORD'], '--port=' + self.environment()['DB_PORT']], capture=True)
        self.start_process('db', [self.db_bin('mariadbd'), '--no-defaults', '--datadir=' + str(data),
                                 '--port=' + self.environment()['DB_PORT'], '--bind-address=127.0.0.1',
                                 '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci',
                                 '--console'])
        for attempt in range(30):
            try:
                with self.db_credentials(root=True, database=False) as options:
                    self.run([self.db_bin('mariadb'), *options, '-e', 'SELECT 1'], capture=True)
                break
            except RuntimeError:
                time.sleep(1)
        else:
            raise RuntimeError('MariaDB startup timed out; see .runtime/db.log')
        if new_database:
            self.php('scripts/db.php', 'create')
        self.start_process('web', [self.php_path(), '-S', '127.0.0.1:' + self.values['HTTP_PORT'],
                                  '-t', self.root / 'public', self.root / 'scripts/router.php'])
        print('Started: ' + self.values['APP_URL'])

    def stop_web(self):
        pid_file = self.runtime / 'web.pid'
        if pid_file.exists():
            if not self.owned_process(int(pid_file.read_text()), 'php'):
                pid_file.unlink()
                return
            if sys.platform == 'win32':
                self.run(['taskkill', '/PID', pid_file.read_text().strip(), '/T', '/F'], capture=True)
            else:
                import signal
                os.kill(int(pid_file.read_text()), signal.SIGTERM)
            pid_file.unlink()

    def stop(self):
        if self.args.backend == 'docker':
            self.compose('stop')  # Never down -v, never deletes volumes.
        else:
            self.stop_web()
            pid_file = self.runtime / 'db.pid'
            if pid_file.exists():
                if self.owned_process(int(pid_file.read_text()), 'mariadbd'):
                    db_pid = int(pid_file.read_text())
                    with self.db_credentials(root=True, database=False) as options:
                        self.run([self.db_bin('mariadb-admin'), *options, 'shutdown'], capture=True)
                    for attempt in range(60):
                        if not self.process_running(db_pid):
                            break
                        time.sleep(0.5)
                    else:
                        raise RuntimeError('MariaDB has not exited after shutdown; do not restart until it exits')
                pid_file.unlink()
        print('Stopped; all persistent data retained')

    def install(self):
        if self.args.profile == 'production':
            raise ValueError('Initialize a new production DB using the loopback development profile first; see docs/deployment.md')
        self.validate()
        if (self.root / 'config.php').exists():
            raise ValueError('Already configured; refusing reinstallation')
        self.php('scripts/db.php', 'empty')
        username = input('Administrator username: ').strip()
        email = input('Administrator email: ').strip()
        password = getpass.getpass('Administrator password (minimum 16 characters): ')
        if len(password) < 16 or not username or '@' not in email:
            raise ValueError('Invalid administrator details')
        self.install_with_admin(username, email, password)

    def install_with_admin(self, username, email, password):
        values = self.environment()
        config = {
            'debug': False, 'baseUrl': values['APP_URL'],
            'databaseConfiguration': {'driver': 'mariadb', 'host': values['DB_HOST'], 'port': int(values['DB_PORT']),
                                      'database': values['DB_NAME'], 'username': values['DB_USER'],
                                      'password': values['DB_PASSWORD'], 'prefix': values.get('DB_PREFIX', '')},
            'adminUser': {'username': username, 'email': email, 'password': password},
            'settings': {'forum_title': values['FORUM_TITLE']},
            'queue': {'driver': 'sync'},
        }
        path = self.runtime / 'install.json'
        generated_config = self.runtime / ('install-result-' + secrets.token_hex(8) + '.php')
        write_private(path, json.dumps(config, ensure_ascii=False))
        try:
            self.php('flarum', 'install', '--file', '.runtime/install.json', '--config', generated_config.relative_to(self.root).as_posix(), root=True)
            write_private(self.root / 'config.php', BRIDGE)
            # No secrets in this bridge; PHP-FPM must be able to read it on Linux.
            (self.root / 'config.php').chmod(0o644)
        finally:
            path.unlink(missing_ok=True)
            generated_config.unlink(missing_ok=True)
        if self.args.backend == 'docker':
            self.compose('exec', '-T', '-u', '0', 'app', 'chown', '-R', 'www-data:www-data', '/var/www/forum/storage', '/var/www/forum/public/assets')
        print('Flarum installed; configure SMTP and forum permissions in admin before inviting users')

    def health(self):
        self.validate()
        self.php('scripts/db.php', 'installed')
        url = self.values['APP_URL'].rstrip('/')
        with urllib.request.urlopen(url + '/api', timeout=15) as response:
            data = json.load(response)
            if response.status != 200 or data.get('data', {}).get('type') != 'forums':
                raise ValueError('Invalid Flarum API health response')
        with urllib.request.urlopen(url + '/', timeout=15) as response:
            if response.status != 200 or b'<html' not in response.read().lower():
                raise ValueError('Forum homepage health check failed')
        print('Health OK: initialized database, forum API and homepage')

    def backups_dir(self):
        path = Path(self.values.get('BACKUP_DIR', 'backups'))
        return path if path.is_absolute() else self.root / path

    def backup(self):
        self.validate()
        if (self.root / 'config.php').read_text() != BRIDGE:
            raise ValueError('Backup requires the environment bridge config.php; refusing to archive installer credentials')
        self.php('scripts/db.php', 'installed')
        directory = self.backups_dir()
        directory.mkdir(parents=True, exist_ok=True)
        stamp = dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        final = directory / f'forum-{self.values["DB_NAME"]}-{stamp}-{secrets.token_hex(3)}.zip'
        partial = final.with_suffix('.partial')
        if self.args.backend == 'docker':
            self.compose('stop', 'web', 'app')
        else:
            self.stop_web()
        try:
            with tempfile.TemporaryDirectory(dir=self.runtime) as temporary:
                stage = Path(temporary)
                dump = stage / 'database.sql'
                with dump.open('wb') as output:
                    if self.args.backend == 'docker':
                        self.compose('exec', '-T', 'db', 'sh', '-c',
                                     'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb-dump -u "$MARIADB_USER" --single-transaction --quick --skip-comments --hex-blob "$MARIADB_DATABASE"', stdout=output)
                    else:
                        with self.db_credentials() as options:
                            # database must follow options for mariadb-dump.
                            self.run([self.db_bin('mariadb-dump'), options[0], '--single-transaction', '--quick', '--skip-comments', '--hex-blob', *options[1:]], stdout=output)
                if dump.stat().st_size < 100:
                    raise ValueError('Empty database backup')
                metadata = {'format': 1, 'flarum': self.core_version(), 'created_utc': stamp, 'database': self.values['DB_NAME'],
                            'app_url': self.values['APP_URL'], 'backend': self.args.backend,
                            'git_commit': self.run(['git', 'rev-parse', 'HEAD'], capture=True).decode().strip(),
                            'config': {key: self.values[key] for key in ('APP_ENV', 'APP_URL', 'FORUM_TITLE', 'APP_DEBUG', 'TZ', 'LOG_LEVEL', 'DB_NAME', 'DB_USER', 'DB_PREFIX', 'HTTP_PORT', 'COMPOSE_PROJECT_NAME') if key in self.values},
                            'note': 'Secrets excluded; configure new env on restore. All current persistent files included.'}
                files = {'database.sql': dump, 'composer.json': self.root / 'composer.json',
                         'composer.lock': self.root / 'composer.lock', 'config.php': self.root / 'config.php',
                         'config/environment.php': self.root / 'config/environment.php',
                         'config/release.php': self.root / 'config/release.php',
                         'scripts/runtime-lock.json': self.root / 'scripts/runtime-lock.json', 'extend.php': self.root / 'extend.php'}
                if self.args.backend == 'docker':
                    # Named volumes are not on the host; read through a temporary container.
                    for folder, path in [('assets', 'public/assets'), ('storage', 'storage')]:
                        tar = stage / (folder + '.tar')
                        with tar.open('wb') as output:
                            self.compose('run', '--rm', '--no-deps', '-T', '-u', '0', 'app', 'tar', '-cf', '-', '-C', '/var/www/forum/' + path, '.', stdout=output)
                        files[folder + '.tar'] = tar
                else:
                    for folder in ('public/assets', 'storage'):
                        for path in (self.root / folder).rglob('*'):
                            if path.is_file() and not path.is_symlink():
                                files[path.relative_to(self.root).as_posix()] = path
                metadata['checksums'] = {name: sha256(path) for name, path in files.items()}
                with zipfile.ZipFile(partial, 'w', zipfile.ZIP_DEFLATED) as archive:
                    archive.writestr('manifest.json', json.dumps(metadata, ensure_ascii=False, indent=2))
                    for name, path in files.items():
                        archive.write(path, name)
                partial.replace(final)
                final.chmod(0o600)
                write_private(final.with_suffix('.sha256'), sha256(final) + '  ' + final.name + '\n')
        finally:
            if self.args.backend == 'docker':
                self.compose('start', 'app', 'web')
            else:
                self.start_process('web', [self.php_path(), '-S', '127.0.0.1:' + self.values['HTTP_PORT'], '-t', self.root / 'public', self.root / 'scripts/router.php'])
        print('Backup: ' + str(final))
        return final

    def restore(self):
        self.validate()
        target = self.args.target_db
        if not target or not re.fullmatch(r'[A-Za-z0-9_]+', target):
            raise ValueError('--target-db is required and must be an identifier')
        if target != self.values['DB_NAME']:
            raise ValueError('--target-db must equal DB_NAME in the explicitly selected --env-file')
        if (self.root / 'config.php').exists():
            raise ValueError('Restore requires an unconfigured target workspace; use a fresh checkout')
        if not self.args.archive:
            raise ValueError('--archive is required')
        archive_path = Path(self.args.archive).resolve()
        checksum_path = archive_path.with_suffix('.sha256')
        expected = checksum_path.read_text().split()[0] if checksum_path.exists() and checksum_path.read_text().strip() else ''
        if not re.fullmatch(r'[a-fA-F0-9]{64}', expected) or sha256(archive_path) != expected.lower():
            raise ValueError('Missing or mismatched backup .sha256 sidecar')
        if self.args.confirm != target:
            print('Restore imports database and files into target: ' + target)
            if input('Type the exact target database name to confirm: ') != target:
                raise ValueError('Restore cancelled')
        self.php('scripts/db.php', 'empty')
        with zipfile.ZipFile(archive_path) as archive:
            members = safe_members(archive)
            metadata = json.loads(archive.read('manifest.json'))
            if metadata.get('format') != 1 or metadata.get('backend') != self.args.backend:
                raise ValueError('Unsupported archive format or different backend; see migration instructions')
            if metadata['database'] == target:
                raise ValueError('Use a NEW database name; never restore into the original database')
            if members != set(metadata['checksums']) | {'manifest.json'}:
                raise ValueError('Archive manifest mismatch')
            for name, checksum in metadata['checksums'].items():
                if hashlib.sha256(archive.read(name)).hexdigest() != checksum:
                    raise ValueError('Backup checksum mismatch: ' + name)
            if archive.read('composer.lock') != (self.root / 'composer.lock').read_bytes():
                raise ValueError('Dependency lock mismatch; use the backed-up version in the fresh checkout first')
            if self.args.backend == 'docker':
                import io
                import tarfile
                for folder in ('assets', 'storage'):
                    with tarfile.open(fileobj=io.BytesIO(archive.read(folder + '.tar'))) as nested:
                        for member in nested.getmembers():
                            if member.name.startswith('/') or '..' in Path(member.name).parts or member.issym() or member.islnk() or member.isdev():
                                raise ValueError('Unsafe nested tar member')
            if self.args.backend == 'docker':
                self.compose('stop', 'web', 'app')
            else:
                self.stop_web()
            # Target database was confirmed empty. On failure leave it for inspection;
            # no automatic rollback/drop/retry against partial data.
            dump = archive.read('database.sql')
            if self.args.backend == 'docker':
                self.compose('exec', '-T', 'db', 'sh', '-c', 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u "$MARIADB_USER" "$MARIADB_DATABASE"', stdin=dump)
                for folder in ('assets', 'storage'):
                    destination = 'public/assets' if folder == 'assets' else 'storage'
                    content = archive.read(folder + '.tar')
                    self.compose('run', '--rm', '--no-deps', '-T', '-u', '0', 'app', 'tar', '-xf', '-', '-C', '/var/www/forum/' + destination, stdin=content)
                self.compose('run', '--rm', '--no-deps', '-T', '-u', '0', 'app', 'chown', '-R', 'www-data:www-data', '/var/www/forum/storage', '/var/www/forum/public/assets')
            else:
                with self.db_credentials() as options:
                    self.run([self.db_bin('mariadb'), *options], stdin=dump)
                for name in metadata['checksums']:
                    if name.startswith(('storage/', 'public/assets/')):
                        path = self.root / name
                        path.parent.mkdir(parents=True, exist_ok=True)
                        path.write_bytes(archive.read(name))
            (self.root / 'extend.php').write_bytes(archive.read('extend.php'))
            write_private(self.root / 'config.php', BRIDGE)
            (self.root / 'config.php').chmod(0o644)
        if self.args.backend == 'docker':
            self.compose('start', 'app', 'web')
        else:
            self.start()
        self.php('flarum', 'cache:clear')
        self.health()
        print('Restore verified on new database: ' + target)

    def status(self):
        if self.args.backend == 'docker':
            self.compose('ps')
        else:
            for name in ('db', 'web'):
                path = self.runtime / (name + '.pid')
                running = False
                if path.exists():
                    running = self.process_running(int(path.read_text()))
                print(name + ': ' + ('running (PID ' + path.read_text().strip() + ')' if running else 'stopped'))

    def logs(self):
        if self.args.backend == 'docker':
            self.compose('logs', '--tail', '100')
        else:
            for name in ('web', 'db'):
                path = self.runtime / (name + '.log')
                if path.exists():
                    print(name + ':')
                    content = '\n'.join(path.read_text(encoding='utf-8', errors='replace').splitlines()[-60:])
                    content = re.sub(r'([A-Z]+ /[^\s?]*)\?\S+', r'\1?[REDACTED]', content)
                    for key in ('DB_PASSWORD', 'DB_ROOT_PASSWORD', 'MAIL_PASSWORD'):
                        if self.values.get(key):
                            content = content.replace(self.values[key], '[REDACTED]')
                    print(content)
        print('Flarum application logs: storage/logs (Docker: app volume)')

    def versions(self):
        self.composer('show', '--locked', '--direct')
        self.php('-v')
        self.php('flarum', 'info')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', choices=['docker', 'native'], default='docker')
    parser.add_argument('--profile', choices=['development', 'production'], default='development')
    parser.add_argument('--env-file', default=str(ROOT / '.env'))
    parser.add_argument('command', choices=['doctor', 'bootstrap-native', 'setup', 'start', 'stop', 'status', 'logs', 'install', 'healthcheck', 'backup', 'restore', 'backups', 'versions'])
    parser.add_argument('--archive')
    parser.add_argument('--target-db')
    parser.add_argument('--confirm', help='Explicit confirmation equal to the target database; for automation')
    args = parser.parse_args()
    try:
        forum = Forum(args)
        command = {'bootstrap-native': 'bootstrap', 'healthcheck': 'health'}.get(args.command, args.command)
        if command == 'backups':
            for path in sorted(forum.backups_dir().glob('forum-*.zip')):
                print(f'{path.name} ({path.stat().st_size} bytes)')
        else:
            getattr(forum, command)()
    except (ValueError, RuntimeError, OSError, zipfile.BadZipFile) as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())

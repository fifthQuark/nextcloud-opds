"""A throwaway Nextcloud in a container (docker or podman)."""
import base64
import http.cookiejar
import json
import os
import re
import shutil
import socket
import subprocess
import time
import urllib.error
import urllib.request
import uuid

APP = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ENGINE = os.environ.get('CONTAINER_ENGINE') or shutil.which('docker') or shutil.which('podman')
WEB = '/var/www/html'
FILES = WEB + '/data/admin/files'
# Only for the container, which listens on this machine and is removed afterwards
USER, PASSWORD = 'admin', 'test-' + uuid.uuid4().hex


def run(*args, check=True, **kw):
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, universal_newlines=True, **kw)
    if check and result.returncode != 0:
        raise RuntimeError(' '.join(args[:6]) + ' failed:\n' + result.stdout[-3000:])
    return result.stdout


class Server:
    def __init__(self, nextcloud, database='sqlite'):
        self.name = 'opds-test-' + uuid.uuid4().hex[:8]
        self.database = database
        self.started = []
        self.token = None
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            self.port = s.getsockname()[1]
        try:
            self._start(nextcloud)
        except BaseException:
            self.stop()
            raise

    def _start(self, nextcloud):
        env = ['NEXTCLOUD_ADMIN_USER=' + USER, 'NEXTCLOUD_ADMIN_PASSWORD=' + PASSWORD]
        network = []
        if self.database == 'sqlite':
            env.append('SQLITE_DATABASE=nc')
        else:
            run(ENGINE, 'network', 'create', self.name)
            network = ['--network', self.name]
            db = self.name + '-db'
            if self.database == 'pgsql':
                image, prefix = 'docker.io/library/postgres:17', 'POSTGRES'
                ready = ['pg_isready', '-h', '127.0.0.1', '-U', 'nc']
            else:
                image, prefix = 'docker.io/library/mariadb:lts', 'MARIADB'
                ready = ['mariadb-admin', 'ping', '-h', '127.0.0.1', '-unc', '-pnc']
            own = ['MARIADB_ROOT_PASSWORD=nc'] if prefix == 'MARIADB' else []
            self._container(db, image, [prefix + '_USER=nc', prefix + '_PASSWORD=nc', ('POSTGRES_DB' if own == [] else 'MARIADB_DATABASE') + '=nc'] + own, network)
            self._until(lambda: subprocess.run([ENGINE, 'exec', db] + ready, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0, 120)
            client = 'POSTGRES' if prefix == 'POSTGRES' else 'MYSQL'
            env += [client + '_HOST=' + db, client + '_USER=nc', client + '_PASSWORD=nc', ('POSTGRES_DB' if client == 'POSTGRES' else 'MYSQL_DATABASE') + '=nc']
        self._container(self.name, 'docker.io/library/nextcloud:%s-apache' % nextcloud, env, network + ['-p', '127.0.0.1:%d:80' % self.port])
        # Apache starts after the installation
        self._until(lambda: 'installed: true' in self.occ('status', check=False) and self._answers(), 240)
        self.sh('mkdir', '-p', FILES, WEB + '/custom_apps')

    def _container(self, name, image, env, options):
        run(ENGINE, 'run', '-d', '--rm', '--name', name, *options, *sum((['-e', e] for e in env), []), image)
        self.started.append(name)

    def _answers(self):
        try:
            with urllib.request.urlopen('http://127.0.0.1:%d/status.php' % self.port, timeout=10) as response:
                return b'"installed":true' in response.read()
        except OSError:
            return False

    @staticmethod
    def _until(test, seconds):
        end = time.time() + seconds
        while not test():
            if time.time() > end:
                raise RuntimeError('The server did not start')
            time.sleep(2)

    def stop(self):
        for name in reversed(self.started):
            subprocess.run([ENGINE, 'stop', '-t', '1', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if self.database != 'sqlite':
            subprocess.run([ENGINE, 'network', 'rm', self.name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    # ------------------------------------------------------------ container

    def sh(self, *args, check=True, user='root'):
        return run(ENGINE, 'exec', '-u', user, self.name, *args, check=check)

    def occ(self, *args, check=True):
        return self.sh('php', WEB + '/occ', '--no-ansi', *args, check=check, user='www-data')

    def install(self, directory):
        """Replaces the files of the app, as an update does."""
        target = WEB + '/custom_apps/opds'
        self.sh('mkdir', '-p', target)
        self.sh('find', target, '-type', 'f', '-delete')
        for part in ('appinfo', 'lib', 'js', 'css', 'templates', 'img', 'resources'):
            if os.path.isdir(os.path.join(directory, part)):
                self.sh('mkdir', '-p', target + '/' + part)
                run(ENGINE, 'cp', os.path.join(directory, part) + '/.', self.name + ':' + target + '/' + part)
        self.sh('chown', '-R', 'www-data:www-data', WEB + '/custom_apps')
        # Forget compiled versions of the old files
        self.sh('apache2ctl', 'graceful', check=False)
        time.sleep(1)

    def put(self, local, folder):
        """Copies the contents of a directory (or a file) into the user's files."""
        if os.path.isdir(local):
            self.sh('mkdir', '-p', FILES + folder)
            local += '/.'
        run(ENGINE, 'cp', local, self.name + ':' + FILES + folder)
        self.sh('chown', '-R', 'www-data:www-data', WEB + '/data/admin')
        self.occ('files:scan', '-q', 'admin')

    def get(self, folder, local):
        run(ENGINE, 'cp', self.name + ':' + FILES + folder, local)

    def md5(self, path):
        return self.sh('md5sum', FILES + path).split()[0]

    def files(self, folder):
        prefix = FILES + folder + '/'
        return sorted(line[len(prefix):] for line in self.sh('find', FILES + folder, '-type', 'f').splitlines() if line.startswith(prefix))

    def errors(self):
        """What the app wrote into the log."""
        found = []
        for line in self.sh('cat', WEB + '/data/nextcloud.log', check=False).splitlines():
            try:
                entry = json.loads(line)
            except ValueError:
                continue
            if entry.get('level', 0) >= 3 and ('opds' in entry.get('app', '').lower() or 'OCA\\\\Opds' in line):
                found.append(entry.get('message', '')[:300])
        return found

    # ------------------------------------------------------------------ app

    def request(self, path, body=None, method=None, headers=None, raw=None):
        auth = {'OCS-APIRequest': 'true', 'Accept': '*/*', 'Authorization': 'Basic ' + base64.b64encode((USER + ':' + PASSWORD).encode()).decode()}
        if self.token is None:
            # Nextcloud 28 asks for the token of the session like a browser, later versions take the header above
            with self.opener.open(urllib.request.Request('http://127.0.0.1:%d/index.php/csrftoken' % self.port, headers=auth), timeout=60) as response:
                self.token = json.loads(response.read().decode())['token']
        headers = dict(auth, requesttoken=self.token, **(headers or {}))
        data = raw
        if body is not None:
            data = json.dumps(body).encode()
            headers['Content-Type'] = 'application/json'
        request = urllib.request.Request('http://127.0.0.1:%d/index.php/apps/opds%s' % (self.port, path), data=data, headers=headers, method=method)
        try:
            with self.opener.open(request, timeout=300) as response:
                return response.status, response.read(), response.headers
        except urllib.error.HTTPError as e:
            with e:
                return e.code, e.read(), e.headers

    def api(self, path, body=None, method=None, expect=200):
        status, data, _ = self.request(path, body, method)
        if status != expect:
            raise AssertionError('%s answered %d instead of %d: %s' % (path, status, expect, data[:500]))
        return json.loads(data.decode())

    def feed(self, path):
        status, data, _ = self.request(path)
        if status != 200:
            raise AssertionError('%s answered %d: %s' % (path, status, data[:500]))
        return re.findall(r'<entry>.*?<title[^>]*>(.*?)</title>', data.decode(), re.S)

    def settings(self, **values):
        return self.api('/settings', dict({'root': '/Books', 'page_size': '50', 'default_sort': 'title', 'default_order': 'asc', 'extensions': 'epub,pdf,txt',
                                           'filename_pattern': 'author-title', 'group_threshold': '150', 'scan_interval': '0'}, **values))

    def scan(self):
        return self.api('/settings/scan', {})['result']

    def books(self):
        """Title => details of every book."""
        out = {}
        for card in self.api('/api/books')['books']:
            out[card['title']] = self.api('/api/books/%d' % card['id'])
        return out

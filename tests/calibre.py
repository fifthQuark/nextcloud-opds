"""The command line tools of Calibre, with their own configuration."""
import csv
import json
import os
import subprocess


class Calibre:
    def __init__(self, directory, config):
        self.directory = directory
        self.env = dict(os.environ, CALIBRE_CONFIG_DIRECTORY=config, QT_QPA_PLATFORM='offscreen', LANG='C.UTF-8', LC_ALL='C.UTF-8',
                        PYTHONIOENCODING='utf-8', TZ='UTC')
        os.makedirs(config, exist_ok=True)

    def tool(self, name, *args, check=True):
        path = os.path.join(self.directory, name) if self.directory else name
        result = subprocess.run([path] + list(args), env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, encoding='utf-8')
        if check and result.returncode != 0:
            raise RuntimeError('%s %s failed:\n%s\n%s' % (name, ' '.join(args), result.stdout[-2000:], result.stderr[-2000:]))
        return result.stdout

    def version(self):
        return self.tool('calibredb', '--version').strip()

    def db(self, library, *args, **kw):
        return self.tool('calibredb', *args, '--with-library', library, **kw)

    def list(self, library, *columns):
        fields = ['title', 'authors', 'author_sort', 'series', 'series_index', 'tags', 'publisher', 'pubdate', 'languages', 'comments', 'rating', 'identifiers', 'formats', 'cover']
        rows = json.loads(self.db(library, 'list', '--for-machine', '--fields', ','.join(fields + ['*' + c for c in columns])))
        return {row['title']: row for row in rows}

    def set(self, library, book, **fields):
        args = []
        for key, value in fields.items():
            args += ['--field', '%s:%s' % (key.replace('_hash_', '#'), value)]
        self.db(library, 'set_metadata', *args, str(book))

    def problems(self, library):
        """What "Check library" finds."""
        out = self.db(library, 'check_library', '--csv', check=False)
        return [row for row in csv.reader(out.splitlines()) if len(row) > 1 and row[0] not in ('Check', 'check')]

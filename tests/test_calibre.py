"""
Lets the app and a real Calibre work on the same library, in turns.

    python3 tests/test_calibre.py            Calibre from the PATH, NEXTCLOUD=34
    CALIBRE=/opt/calibre NEXTCLOUD=28 python3 tests/test_calibre.py
"""
import hashlib
import os
import re
import sqlite3
import tempfile
import unittest
import uuid

from calibre import Calibre
from epubs import COVERS, epub
from server import Server

EDIT = {
    'title': 'Der Süße <Titel> & "mehr"', 'authors': ['Zoë Änder', 'Pat O\'Neil'], 'series': 'Neue Reihe', 'series_index': 4.5,
    'tags': ['Alpha', 'Beta & Co'], 'publisher': 'Verlag', 'pubdate': '2001-02-03', 'languages': ['German', 'English'],
    'comments': 'First line\nSecond & last', 'rating': 7, 'identifiers': {'isbn': '9783161484100', 'goodreads': '99'},
}


class CalibreTest(unittest.TestCase):
    maxDiff = None

    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.addClassCleanup(cls.temp.cleanup)
        cls.calibre = Calibre(os.environ.get('CALIBRE', ''), cls.dir('config'))
        print('Testing with ' + cls.calibre.version())
        cls.server = Server(os.environ.get('NEXTCLOUD', '34'))
        cls.addClassCleanup(cls.server.stop)
        cls.server.install(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
        cls.server.occ('app:enable', 'opds')
        cls.make_library()

    @classmethod
    def dir(cls, name):
        path = os.path.join(cls.temp.name, name)
        os.makedirs(path, exist_ok=True)
        return path

    @classmethod
    def make_library(cls):
        c, cls.library = cls.calibre, cls.dir('library')
        books = cls.dir('books')
        for i, (title, author, version) in enumerate([('First Book', 'Ann Author', 2), ('Second Book', 'Ben Writer', 3), ('Third Book', 'Ann Author', 2)]):
            epub(os.path.join(books, '%d.epub' % i), title, author, version, COVERS[0])
            c.db(cls.library, 'add', os.path.join(books, '%d.epub' % i))
        with open(os.path.join(books, 'text.txt'), 'w') as f:
            f.write('text')
        c.db(cls.library, 'add_format', '1', os.path.join(books, 'text.txt'))
        for label, name, kind, extra in [('genre', 'Genre', 'text', ['--is-multiple']), ('read', 'Read', 'bool', []), ('pages', 'Pages', 'int', []),
                                         ('shelf', 'Shelf', 'enumeration', ['--display', '{"enum_values": ["B", "A"]}']), ('notes', 'Notes', 'comments', []),
                                         ('stars', 'Stars', 'rating', []), ('when', 'When', 'datetime', [])]:
            c.db(cls.library, 'add_custom_column', *extra, label, name, kind)
        c.set(cls.library, 1, tags='Fiction.Crime,Humour', rating='3', languages='eng,deu', identifiers='isbn:9780552166591,goodreads:34497', series='Saga [2]',
              publisher='Pub & Co', pubdate='2015-03-04', comments='<p>One <b>two</b></p>')
        c.set(cls.library, 1, _hash_genre='SF,Kids', _hash_read='yes', _hash_pages='412', _hash_shelf='B', _hash_notes='<p>A note</p>', _hash_stars='4', _hash_when='2026-03-04')
        # What Calibre does in the background while it is open
        c.db(cls.library, 'backup_metadata', '--all')

    def setUp(self):
        self.addCleanup(lambda: self.assertEqual([], self.server.errors()))

    # -------------------------------------------------------------- helpers

    def upload(self, library=None):
        """Puts a library into a new folder, makes it the catalog and scans it."""
        root = '/' + uuid.uuid4().hex[:8]
        self.server.put(library or self.library, root)
        self.open(root)
        return root

    def open(self, root):
        self.server.settings(root=root)
        self.server.api('/settings/calibre', {'enabled': True})
        result = self.server.scan()
        self.assertEqual(0, result['errors'])
        return result

    def download(self, root):
        target = os.path.join(self.temp.name, uuid.uuid4().hex[:8])
        self.server.get(root, target)
        return target

    def edit(self, book, fields, expect=200):
        return self.server.api('/api/books/%d' % book['id'], {'fields': fields, 'rev': book['calibre']['rev']}, 'PUT', expect)

    def check(self, library):
        self.assertEqual([], self.calibre.problems(library))

    def same(self, a, b):
        """Two rows of calibredb list, without what depends on where the library is."""
        drop = ('formats', 'cover', 'id')
        normal = [{k: (sorted(v) if isinstance(v, list) and k != 'languages' else v) for k, v in row.items() if k not in drop} for row in (a, b)]
        self.assertEqual(*normal)

    # ---------------------------------------------------------------- tests

    def test_reads_what_calibre_wrote(self):
        self.upload()
        book = self.server.books()['First Book']
        self.assertEqual(['epub', 'txt'], [f['format'] for f in book['formats']])
        self.assertEqual(['en', 'de'], [x['code'] for x in book['languages']])
        self.assertEqual((6, 'Saga', 2, 'Pub & Co', '2015-03-04', '9780552166591', ['goodreads:34497']),
                         (book['rating'], book['series'], book['seriesIndex'], book['publisher'], book['pubdate'], book['isbn'], book['identifiers']))
        self.assertEqual({'Fiction.Crime', 'Humour'}, set(book['subjects']))
        columns = {c['key']: c['text'] for c in book['columns']}
        self.assertEqual({'SF', 'Kids'}, set(columns.pop('genre').split(', ')))
        self.assertEqual({'read': 'Yes', 'pages': '412', 'shelf': 'B', 'notes': 'A note', 'stars': '★★★★', 'when': '2026-03-04'}, columns)
        self.assertEqual('', book['calibre']['readOnly'])
        self.assertIn('Ratings', self.server.feed('/'))
        self.assertEqual(['First Book'], self.server.feed('/books?column=read&value=Yes'))
        self.assertEqual(3, len(self.server.feed('/books')))

    def test_calibre_accepts_edits(self):
        root = self.upload()
        before = self.calibre.list(self.library, 'genre', 'read', 'pages')['First Book']
        self.edit(self.server.books()['First Book'], EDIT)
        self.assertEqual(0, self.server.scan()['updated'])
        library = self.download(root)
        self.check(library)
        rows = self.calibre.list(library, 'genre', 'read', 'pages')
        self.assertEqual(3, len(rows))
        row = rows[EDIT['title']]
        self.assertEqual("Zoë Änder & Pat O'Neil", row['authors'])
        self.assertEqual("Änder, Zoë & O'Neil, Pat", row['author_sort'])
        self.assertEqual(('Neue Reihe', 4.5, {'Alpha', 'Beta & Co'}, 'Verlag', ['deu', 'eng'], 7), (row['series'], row['series_index'], set(row['tags']), row['publisher'],
                         row['languages'], row['rating']))
        self.assertTrue(row['pubdate'].startswith('2001-02-03'), row['pubdate'])
        self.assertEqual({'isbn': '9783161484100', 'goodreads': '99'}, row['identifiers'])
        self.assertIn('Second &amp; last', row['comments'])
        for column in ('*genre', '*read', '*pages'):
            self.assertEqual(before[column], row[column])
        self.assertEqual(2, len(row['formats']))
        for path in row['formats']:
            self.assertTrue(os.path.isfile(path), path)

        # Calibre can rebuild the database from the metadata.opf files
        self.calibre.db(library, 'restore_database', '--really-do-it')
        restored = self.calibre.list(library, 'genre', 'read', 'pages')
        self.assertEqual(set(rows), set(restored))
        self.same(row, restored[EDIT['title']])

    def test_takes_turns(self):
        root = self.upload()
        self.edit(self.server.books()['Second Book'], {'title': 'Changed here', 'tags': ['x']})
        library = self.download(root)
        book = self.calibre.list(library)['Changed here']['id']
        self.calibre.set(library, book, title='Changed there', tags='y,z', authors='Some One')
        self.upload(library)
        there = self.server.books()['Changed there']
        self.assertEqual((['Some One'], {'y', 'z'}), (there['authors'], set(there['subjects'])))
        self.edit(there, {'title': 'And back', 'authors': ['Any One']})
        self.check(self.download('/' + there['folder'].split('/')[1]))

    def test_cover(self):
        root = self.upload()
        book = self.server.books()['Second Book']
        boundary = uuid.uuid4().hex
        body = ('--%s\r\nContent-Disposition: form-data; name="cover"; filename="c.jpg"\r\nContent-Type: image/jpeg\r\n\r\n' % boundary).encode() + COVERS[1] + ('\r\n--%s--\r\n' % boundary).encode()
        status, answer, _ = self.server.request('/api/books/%d/cover' % book['id'], raw=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
        self.assertEqual(200, status, answer)
        library = self.download(root)
        self.check(library)
        with open(self.calibre.list(library)['Second Book']['cover'], 'rb') as f:
            self.assertEqual(COVERS[1], f.read())

    def test_downloads(self):
        self.upload()
        book = self.server.books()['Third Book']
        _, plain, _ = self.server.request('/download/%d/x.epub' % book['id'])
        stored = book['folder'] + '/' + book['formats'][0]['name']
        self.assertEqual(self.server.md5(stored), hashlib.md5(plain).hexdigest(), 'a book nobody has edited is sent as it is')
        self.edit(book, EDIT)
        book = self.server.books()[EDIT['title']]
        stored = book['folder'] + '/' + book['formats'][0]['name']
        self.assertEqual(self.server.md5(stored), hashlib.md5(plain).hexdigest(), 'the stored file is not changed')
        _, copy, _ = self.server.request('/download/%d/x.epub' % book['id'])
        self.assertEqual(copy, self.server.request('/download/%d/x.epub' % book['id'])[1])
        path = os.path.join(self.temp.name, 'copy.epub')
        with open(path, 'wb') as f:
            f.write(copy)
        meta = dict(re.findall(r'^([\w() ]+?)\s*: (.*)$', self.calibre.tool('ebook-meta', path), re.M))
        self.assertEqual(EDIT['title'], meta['Title'])
        self.assertTrue(meta['Author(s)'].startswith("Zoë Änder & Pat O'Neil"), meta['Author(s)'])
        series, number = meta['Series'].rsplit(' #', 1)
        self.assertEqual(('Neue Reihe', 4.5, 'Verlag', 'Alpha, Beta & Co', 'deu, eng', {'goodreads:99', 'isbn:9783161484100'}),
                         (series, float(number), meta['Publisher'], meta['Tags'], meta['Languages'], set(meta['Identifiers'].split(', '))))

    def test_convert_and_copy(self):
        loose = self.dir('loose-' + uuid.uuid4().hex[:8])
        os.makedirs(os.path.join(loose, '.stfolder'))
        os.makedirs(os.path.join(loose, 'sub'))
        with open(os.path.join(loose, '.stfolder', 'marker.txt'), 'w') as f:
            f.write('Syncthing')
        for i in range(4):
            epub(os.path.join(loose, 'sub' if i % 2 else '', 'book%d.epub' % i), 'Loose %d: ?*/' % i, 'Loose Author', 2 + i % 2, COVERS[0])
        root = self.upload(loose)
        self.server.settings(root=root, show_hidden=False)
        self.server.scan()
        copy = '/' + uuid.uuid4().hex[:8]
        for path, in_place in ((copy, False), (root, True)):
            self.server.api('/api/calibre', {'path': path, 'inPlace': in_place})
            result = self.server.api('/api/calibre/import', {'path': path, 'after': '', 'inPlace': in_place})
            self.assertEqual((4, [], True), (result['added'], result['failed'], result['done']))
            library = self.download(path)
            self.check(library if not in_place else self.visible(library))
            rows = self.calibre.list(library)
            self.assertEqual({'Loose %d: ?*/' % i for i in range(4)}, set(rows))
            self.assertEqual({'Loose Author'}, {r['authors'] for r in rows.values()})
            # A library made by the app can be used and changed by Calibre
            first = rows['Loose 0: ?*/']['id']
            self.calibre.set(library, first, title='Renamed by Calibre')
            self.assertIn('Renamed by Calibre', self.calibre.list(library))
        self.assertIn('.stfolder/marker.txt', self.server.files(root))
        self.assertEqual(4, self.server.scan()['total'])

    @staticmethod
    def visible(library):
        # Calibre calls everything it does not know a problem
        os.rename(os.path.join(library, '.stfolder'), library + '-stfolder')
        return library

    def test_refuses_unsafe_changes(self):
        for name, text in [('metadata (conflicted copy 2026-11-25 101500).db', 'conflict copy'), ('metadata.sync-conflict-20261125-101500-ABCDEFG.db', 'conflict copy'),
                           ('metadata_conflict-20261125-101500.db', 'conflict copy'), ('metadata.db-journal', 'Calibre seems to be using'), ('metadata.db-wal', 'Calibre seems to be using')]:
            with self.subTest(name):
                root = self.upload()
                extra = os.path.join(self.temp.name, 'extra')
                with open(extra, 'w') as f:
                    f.write('x')
                self.server.put(extra, root + '/' + name)
                before, files = self.server.md5(root + '/metadata.db'), self.server.files(root)
                book = self.server.books()['First Book']
                self.assertIn(text, self.edit(book, EDIT, 400)['error'])
                if 'conflict' in name:
                    self.assertIn(text, book['calibre']['readOnly'])
                    self.assertIn(text, self.server.api('/api/calibre')['libraries'][0]['problem'])
                self.assertEqual((before, files), (self.server.md5(root + '/metadata.db'), self.server.files(root)))

    def test_conflict_copy_without_database(self):
        loose = self.dir('left-' + uuid.uuid4().hex[:8])
        epub(os.path.join(loose, 'a.epub'), 'A', 'B')
        with open(os.path.join(loose, 'metadata.sync-conflict-20261125-101500-ABCDEFG.db'), 'w') as f:
            f.write('x')
        root = self.upload(loose)
        self.assertIn('conflict copy', self.server.api('/api/calibre', {'path': root, 'inPlace': True}, expect=400)['error'])
        self.assertNotIn('metadata.db', self.server.files(root))

    def test_other_names_are_no_conflict(self):
        root = self.upload()
        for name in ('metadata_db_prefs_backup.json', 'metadata (copy).db', 'Conflict of Interest.db', 'metadata conflict notes.txt'):
            extra = os.path.join(self.temp.name, 'extra')
            with open(extra, 'w') as f:
                f.write('x')
            self.server.put(extra, root + '/' + name)
        self.edit(self.server.books()['First Book'], {'title': 'Still fine'})

    def test_newer_calibre(self):
        library = self.download(self.upload())
        db = sqlite3.connect(os.path.join(library, 'metadata.db'))
        db.execute('PRAGMA user_version = 99')
        db.commit()
        db.close()
        root = self.upload(library)
        book = self.server.books()['First Book']
        self.assertIn('newer version of Calibre', book['calibre']['readOnly'])
        before = self.server.md5(root + '/metadata.db')
        self.edit(book, EDIT, 400)
        self.assertEqual(before, self.server.md5(root + '/metadata.db'))

    def test_outdated_page(self):
        self.upload()
        old = self.server.books()['First Book']
        self.edit(old, {'title': 'One'})
        self.assertGreaterEqual(self.server.request('/api/books/%d' % old['id'], {'fields': {'title': 'Two'}, 'rev': old['calibre']['rev']}, 'PUT')[0], 400)
        self.assertIn('One', self.server.books())


if __name__ == '__main__':
    unittest.main(verbosity=2)

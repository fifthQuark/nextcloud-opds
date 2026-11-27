"""
Lets the app and a real Calibre work on the same library, in turns.

    python3 tests/test_calibre.py            Calibre from the PATH, NEXTCLOUD=34
    CALIBRE=/opt/calibre NEXTCLOUD=28 python3 tests/test_calibre.py
"""
import hashlib
import json
import os
import re
import shutil
import sqlite3
import tempfile
import unittest
import urllib.parse
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
                                         ('stars', 'Stars', 'rating', []), ('when', 'When', 'datetime', []), ('price', 'Price', 'float', []),
                                         ('cycle', 'Cycle', 'series', []), ('mood', 'Mood', 'text', []),
                                         ('people', 'People', 'text', ['--is-multiple', '--display', '{"is_names": true}'])]:
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

    def rename(self, kind, old, new, expect=200):
        """(number of books, new name), or the answer of an error"""
        answer = self.server.api('/api/rename', {'kind': kind, 'from': old, 'to': new}, expect=expect)
        return (answer['books'], answer['name']) if expect == 200 else answer

    def series_index(self, library, label, book):
        db = sqlite3.connect(os.path.join(library, 'metadata.db'))
        try:
            column = db.execute('SELECT id FROM custom_columns WHERE label = ?', (label,)).fetchone()[0]
            row = db.execute('SELECT extra FROM books_custom_column_%d_link WHERE book = ?' % column, (book,)).fetchone()
            return row[0] if row else None
        finally:
            db.close()

    def multipart(self, path, fields, name, data, expect=200):
        """Sends a book file as the browser does."""
        boundary = uuid.uuid4().hex
        body = b''.join(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode() for k, v in fields.items())
        body += ('--%s\r\nContent-Disposition: form-data; name="book"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, name)).encode()
        body += data + ('\r\n--%s--\r\n' % boundary).encode()
        status, answer, _ = self.server.request(path, raw=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
        self.assertEqual(expect, status, answer[:500])
        return json.loads(answer.decode())

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

    def test_custom_columns(self):
        source = os.path.join(self.temp.name, 'without-opf-' + uuid.uuid4().hex[:8])
        shutil.copytree(self.library, source)
        # Written from scratch, not only changed
        os.remove(os.path.join(os.path.dirname(self.calibre.list(source)['First Book']['formats'][0]), 'metadata.opf'))
        root = self.upload(source)
        book = self.server.books()['First Book']
        fields = {f['label']: f for f in book['calibre']['fields']}
        self.assertEqual(({'SF', 'Kids'}, True, 412, 'B', ['B', 'A'], 'A note', 8, '2026-03-04', '', []),
                         (set(fields['genre']['value']), fields['read']['value'], fields['pages']['value'], fields['shelf']['value'], fields['shelf']['values'],
                          fields['notes']['value'], fields['stars']['value'], fields['when']['value'], fields['mood']['value'], fields['people']['value']))
        for bad, text in [({'shelf': 'Z'}, 'can only be one of'), ({'pages': 'many'}, 'whole number'), ({'price': 'x'}, 'must be a number'), ({'nothing': 1}, 'not in this library')]:
            self.assertIn(text, self.edit(book, {'custom': bad}, 400)['error'])
        custom = {'genre': ['Space', 'Kids'], 'read': False, 'pages': 500, 'shelf': 'a', 'notes': 'New\nnote & more', 'stars': 6, 'when': '2020-05-06',
                  'price': 9.75, 'cycle': {'value': 'Ring', 'index': 2.5}, 'mood': 'Calm', 'people': ['Le Guin, Ursula', 'Ann B']}
        self.edit(book, {'custom': custom})
        self.assertEqual(0, self.server.scan()['updated'])
        shown = {c['key']: c['text'] for c in self.server.books()['First Book']['columns']}
        self.assertEqual(('A', '9.75', 'Ring [2.5]', 'No', '★★★'), (shown['shelf'], shown['price'], shown['cycle'], shown['read'], shown['stars']))

        library = self.download(root)
        self.check(library)
        expected = {'*genre': {'Space', 'Kids'}, '*read': False, '*pages': 500, '*shelf': 'A', '*stars': 6, '*price': 9.75, '*cycle': 'Ring', '*mood': 'Calm',
                    '*people': {'Le Guin, Ursula', 'Ann B'}}
        labels = [k[1:] for k in expected] + ['when', 'notes']
        for restore in (False, True):
            with self.subTest(restored=restore):
                if restore:
                    # Calibre can rebuild the database, custom columns included, from the metadata.opf files
                    self.calibre.db(library, 'restore_database', '--really-do-it')
                rows = self.calibre.list(library, *labels)
                row = rows['First Book']
                self.assertEqual(expected, {k: set(v) if isinstance(v, list) else v for k, v in row.items() if k in expected})
                self.assertTrue(row['*when'].startswith('2020-05-06'), row['*when'])
                self.assertIn('note &amp; more', row['*notes'])
                self.assertEqual(2.5, self.series_index(library, 'cycle', row['id']))
                self.assertEqual([], rows['Second Book']['*genre'])

        book = self.server.books()['First Book']
        self.edit(book, {'custom': {'genre': [], 'read': None, 'pages': None, 'shelf': '', 'notes': '', 'stars': 0, 'when': '', 'price': None,
                                    'cycle': {'value': '', 'index': 1}, 'mood': '', 'people': []}})
        self.assertEqual([], self.server.books()['First Book']['columns'])
        library = self.download(root)
        self.check(library)
        row = self.calibre.list(library, *labels)['First Book']
        self.assertEqual({}, {k: v for k, v in row.items() if k.startswith('*') and v not in (None, [], 'None', '')})
        db = sqlite3.connect(os.path.join(library, 'metadata.db'))
        self.assertEqual([], db.execute("SELECT value FROM custom_column_1").fetchall(), 'values nobody uses are removed')
        db.close()

    def test_rename(self):
        root = self.upload()
        self.assertEqual((2, 'Anne Autorin'), self.rename('author', 'Ann Author', 'Anne Autorin'))
        # The new name is there already: the two become one, with the spelling that was there
        self.assertEqual((1, 'Anne Autorin'), self.rename('author', 'Ben Writer', 'anne autorin'))
        self.assertEqual((1, 'Fiction.Crime'), self.rename('subject', 'Humour', 'fiction.crime'))
        self.rename('series', 'Saga', 'Sage')
        self.rename('publisher', 'Pub & Co', 'Publisher')
        self.rename('column-genre', 'SF', 'Science Fiction')
        self.assertIn('cannot contain &', self.rename('author', 'Anne Autorin', 'A & B', 400)['error'])
        self.assertIn('No book', self.rename('author', 'Nobody', 'Somebody', 400)['error'])
        self.assertIn('No book', self.rename('column-shelf', 'B', 'C', 400)['error'])
        self.assertEqual(0, self.server.scan()['updated'])

        books = self.server.books()
        first = books['First Book']
        self.assertEqual((['Anne Autorin'], ['Fiction.Crime'], 'Sage', 'Publisher'), (first['authors'], first['subjects'], first['series'], first['publisher']))
        self.assertEqual({'Anne Autorin'}, {a for b in books.values() for a in b['authors']})
        self.assertTrue(all('/Anne Autorin/' in b['folder'] + '/' for b in books.values()), [b['folder'] for b in books.values()])

        library = self.download(root)
        self.check(library)
        self.assertFalse(os.path.exists(os.path.join(library, 'Ann Author')))
        self.assertFalse(os.path.exists(os.path.join(library, 'Ben Writer')))
        for restore in (False, True):
            with self.subTest(restored=restore):
                if restore:
                    self.calibre.db(library, 'restore_database', '--really-do-it')
                rows = self.calibre.list(library, 'genre')
                self.assertEqual({'Anne Autorin'}, {r['authors'] for r in rows.values()})
                self.assertEqual({'Autorin, Anne'}, {r['author_sort'] for r in rows.values()})
                row = rows['First Book']
                self.assertEqual((['Fiction.Crime'], 'Sage', 2.0, 'Publisher', {'Science Fiction', 'Kids'}),
                                 (row['tags'], row['series'], row['series_index'], row['publisher'], set(row['*genre'])))
                for r in rows.values():
                    for path in r['formats']:
                        self.assertTrue(os.path.isfile(path), path)

    def test_bulk(self):
        root = self.upload()
        cards = {c['title']: c for c in self.server.api('/api/books')['books']}
        self.assertTrue(all(c['inCalibre'] for c in cards.values()))
        # In this order they are numbered in the series
        ids = [cards[t]['id'] for t in ('Second Book', 'Third Book', 'First Book')]
        form = self.server.api('/api/bulk/form', {'ids': ids + [999999]})
        self.assertEqual((3, 1, []), (form['books'], form['outside'], form['problems']))
        self.assertIn('Fiction.Crime', form['names']['tags'])
        self.assertIn('SF', form['names']['columns']['genre'])
        self.assertEqual(['B', 'A'], {c['label']: c for c in form['columns']}['shelf']['values'])

        bulk = lambda changes, expect=200: self.server.api('/api/bulk', {'ids': ids, 'changes': changes}, expect=expect)
        for bad, text in [({}, 'Nothing to change'), ({'custom_add': {'shelf': ['A']}}, 'several values'), ({'series_start': 1}, 'series that is set'),
                          ({'custom': {'shelf': 'Z'}}, 'can only be one of'), ({'tags_add': [' ']}, 'Nothing to change')]:
            self.assertIn(text, bulk(bad, 400)['error'])
        self.assertIn('Choose at least one', self.server.api('/api/bulk', {'ids': [], 'changes': {'rating': 2}}, expect=400)['error'])

        answer = bulk({'authors': ['Carla Neu'], 'series': 'Trilogy', 'series_start': 1, 'tags_add': ['Bulk', 'humour'], 'tags_remove': ['fiction.crime'],
                       'publisher': 'Same Pub', 'rating': 8, 'custom': {'shelf': 'a', 'cycle': 'Ring', 'mood': 'Calm', 'nothing': 'x'},
                       'custom_add': {'genre': ['New'], 'people': ['X & Y']}, 'custom_remove': {'genre': ['kids']}})
        self.assertEqual((3, 0, 0, []), (answer['books'], answer['unknown'], answer['outside'], answer['skipped']))
        # Nothing to do: the books stay as they are
        self.assertEqual(0, bulk({'tags_add': ['bulk'], 'publisher': 'Same Pub'})['books'])
        self.assertEqual(0, self.server.scan()['updated'])
        books = self.server.books()
        self.assertEqual({('Carla Neu',)}, {tuple(b['authors']) for b in books.values()})
        self.assertEqual({'First Book': 3, 'Second Book': 1, 'Third Book': 2}, {t: b['seriesIndex'] for t, b in books.items()})
        self.assertEqual({8}, {b['rating'] for b in books.values()})

        library = self.download(root)
        self.check(library)
        for name in ('Ann Author', 'Ben Writer'):
            self.assertFalse(os.path.exists(os.path.join(library, name)), name)
        for restore in (False, True):
            with self.subTest(restored=restore):
                if restore:
                    self.calibre.db(library, 'restore_database', '--really-do-it')
                rows = self.calibre.list(library, 'genre', 'shelf', 'cycle', 'mood', 'people')
                self.assertEqual({('Carla Neu', 'Neu, Carla', 'Trilogy', 'Same Pub', 'A', 'Ring', 'Calm')},
                                 {(r['authors'], r['author_sort'], r['series'], r['publisher'], r['*shelf'], r['*cycle'], r['*mood']) for r in rows.values()})
                self.assertEqual({'First Book': 3.0, 'Second Book': 1.0, 'Third Book': 2.0}, {t: r['series_index'] for t, r in rows.items()})
                self.assertEqual({'First Book': {'Humour', 'Bulk'}, 'Second Book': {'Humour', 'Bulk'}, 'Third Book': {'Humour', 'Bulk'}},
                                 {t: set(r['tags']) for t, r in rows.items()})
                self.assertEqual({'First Book': {'SF', 'New'}, 'Second Book': {'New'}, 'Third Book': {'New'}}, {t: set(r['*genre']) for t, r in rows.items()})
                self.assertEqual({('X + Y',)}, {tuple(r['*people']) for r in rows.values()})
                for r in rows.values():
                    self.assertEqual(8, r['rating'])
                    for path in r['formats']:
                        self.assertTrue(os.path.isfile(path), path)

    def test_add_books(self):
        root = self.upload()
        add = lambda name, data, expect=200, **fields: self.multipart('/api/calibre/books', dict({'library': root}, **fields), name, data, expect)
        path = os.path.join(self.temp.name, 'added.epub')
        epub(path, 'Added Book', 'New Author', 3, COVERS[1])
        with open(path, 'rb') as f:
            book = f.read()
        pdf = b'%PDF-1.4\n1 0 obj\n<< /Title (Added Book) /Author (New Author) >>\nendobj\ntrailer\n<< /Info 1 0 R >>\n%%EOF\n'
        info = self.server.api('/api/calibre')
        self.assertIn('epub', info['extensions'])
        self.assertGreater(info['uploadLimit'], 0)

        for name, data, fields, text in [('x.exe', b'x', {}, 'not part of this catalog'), ('empty.epub', b'', {}, 'empty'),
                                         ('x.epub', book, {'duplicates': 'maybe'}, 'Unknown choice'),
                                         ('x.epub', book, {'library': root + '/Ann Author'}, 'no Calibre library')]:
            self.assertIn(text, add(name, data, 400, **fields)['error'])
        first = add('added.epub', book)
        self.assertEqual(('added', 'Added Book'), (first['result'], first['title']))
        self.assertGreater(first['id'], 0)
        self.assertEqual('exists', add('again.epub', book)['result'])
        self.assertEqual('skipped', add('again.epub', book, duplicates='skip')['result'])
        self.assertEqual('format', add('added.pdf', pdf)['result'])
        self.assertEqual('added', add('again.epub', book, duplicates='new')['result'])
        # Without metadata inside, the name of the file is read as in the catalog
        self.assertEqual('Plain Title', add('Some Writer - Plain Title.pdf', b'%PDF-1.4\n%%EOF\n')['title'])

        second = self.server.books()['Second Book']
        self.assertEqual(['EPUB'], second['calibre']['formats'])
        formats = '/api/books/%d/formats' % second['id']
        answer = self.multipart(formats, {}, 'whatever.pdf', pdf)
        self.assertFalse(answer['replaced'])
        self.assertIn('already', self.multipart(formats, {}, 'other.pdf', pdf, 400)['error'])
        changed = pdf + b'% changed\n'
        self.assertTrue(self.multipart(formats, {'replace': '1'}, 'other.pdf', changed)['replaced'])

        cards = [c['title'] for c in self.server.api('/api/books')['books']]
        self.assertEqual(['Added Book', 'Added Book', 'First Book', 'Plain Title', 'Second Book', 'Third Book'], sorted(cards))
        books = self.server.books()
        self.assertEqual(['Some Writer'], books['Plain Title']['authors'])
        self.assertEqual({'epub', 'pdf'}, {f['format'] for f in books['Second Book']['formats']})

        library = self.download(root)
        self.check(library)
        for restore in (False, True):
            with self.subTest(restored=restore):
                if restore:
                    self.calibre.db(library, 'restore_database', '--really-do-it')
                rows = json.loads(self.calibre.db(library, 'list', '--for-machine', '--fields', 'title,authors,formats,cover'))
                self.assertEqual(['Added Book', 'Added Book', 'First Book', 'Plain Title', 'Second Book', 'Third Book'], sorted(r['title'] for r in rows))
                added = sorted((r for r in rows if r['title'] == 'Added Book'), key=lambda r: r['id'])
                self.assertEqual([['epub', 'pdf'], ['epub']], [sorted(f.rsplit('.', 1)[1] for f in r['formats']) for r in added])
                self.assertEqual({'New Author'}, {r['authors'] for r in added})
                self.assertTrue(all(os.path.isfile(r['cover']) for r in added))
                for r in rows:
                    for f in r['formats']:
                        self.assertTrue(os.path.isfile(f), f)
                self.assertEqual('Added Book - New Author.epub', os.path.basename(added[0]['formats'][0]) if added[0]['formats'][0].endswith('.epub')
                                 else os.path.basename(added[0]['formats'][1]))
                pdfs = [f for r in rows if r['title'] == 'Second Book' for f in r['formats'] if f.endswith('.pdf')]
                with open(pdfs[0], 'rb') as f:
                    self.assertEqual(changed, f.read())

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
        stored = book['folder'] + '/' + book['formats'][0]['file']
        self.assertEqual(self.server.md5(stored), hashlib.md5(plain).hexdigest(), 'a book nobody has edited is sent as it is')
        self.edit(book, EDIT)
        book = self.server.books()[EDIT['title']]
        stored = book['folder'] + '/' + book['formats'][0]['file']
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

    def test_download_names(self):
        root = self.upload()
        self.edit(self.server.books()['Second Book'], {'title': 'סיפור על אהבה: וחושך', 'authors': ['עמוס עוז']})
        book = self.server.books()['סיפור על אהבה: וחושך']
        wanted = 'סיפור על אהבה_ וחושך - עמוס עוז.epub'
        self.assertEqual(wanted, book['formats'][0]['name'])
        # Calibre names the stored file in Latin letters
        self.assertTrue(book['formats'][0]['file'].isascii(), book['formats'][0]['file'])
        self.assertIn(urllib.parse.quote(wanted, safe=''), book['formats'][0]['url'])
        status, _, headers = self.server.request('/download/%d/x' % book['id'])
        self.assertEqual(200, status)
        self.assertIn("filename*=UTF-8''" + urllib.parse.quote(wanted, safe=''), headers['Content-Disposition'])
        _, feed, _ = self.server.request('/books?q=' + urllib.parse.quote('עוז'))
        self.assertIn(urllib.parse.quote(wanted, safe=''), feed.decode())
        self.check(self.download(root))

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

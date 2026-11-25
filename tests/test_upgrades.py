"""
Updates from every release to the working copy, because an update may skip any number of releases.

    NEXTCLOUD=34 DATABASE=mysql python3 tests/test_upgrades.py      (sqlite, mysql or pgsql)
"""
import os
import subprocess
import tempfile
import unittest

from epubs import epub
from server import APP, ENGINE, Server, run


def git(*args):
    return subprocess.run(['git', '-C', APP] + list(args), check=True, stdout=subprocess.PIPE, universal_newlines=True).stdout


class UpgradeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.addClassCleanup(cls.temp.cleanup)
        number = lambda v: [int(n) for n in v.lstrip('v').split('.')]
        cls.tags = [t for t in git('tag', '--list', 'v*', '--sort=version:refname').split() if number(t) < number(cls.version())]
        if not cls.tags:
            raise RuntimeError('There are no tags to update from. Fetch them first')
        cls.server = Server(os.environ.get('NEXTCLOUD', '34'), os.environ.get('DATABASE', 'sqlite'))
        cls.addClassCleanup(cls.server.stop)
        books = os.path.join(cls.temp.name, 'books')
        os.makedirs(os.path.join(books, 'sub'))
        for i, (title, author) in enumerate([('Good Omens', 'Terry Pratchett & Neil Gaiman'), ('Mort', 'Terry Pratchett'), ('Slash/Amp & Co', 'AC/DC Writer')]):
            epub(os.path.join(books, 'sub' if i == 1 else '', 'b%d.epub' % i), title, author, 2 + i % 2)
        cls.server.put(books, '/Books')
        run(ENGINE, 'cp', os.path.join(APP, 'tests', 'reset.php'), cls.server.name + ':/tmp/reset.php')
        cls.server.sh('chmod', '644', '/tmp/reset.php')

    @staticmethod
    def version():
        with open(os.path.join(APP, 'appinfo', 'info.xml')) as f:
            return f.read().split('<version>')[1].split('<')[0]

    def checkout(self, tag):
        target = os.path.join(self.temp.name, tag)
        os.makedirs(target)
        archive = subprocess.run(['git', '-C', APP, 'archive', tag], check=True, stdout=subprocess.PIPE).stdout
        subprocess.run(['tar', '-x', '-C', target], input=archive, check=True)
        return target

    def forget(self):
        self.server.occ('app:disable', 'opds', check=False)
        self.server.sh('php', '/tmp/reset.php', user='www-data')

    def verify(self):
        self.assertEqual({'Good Omens', 'Mort', 'Slash/Amp &amp; Co'}, set(self.server.feed('/books')))
        self.assertEqual({'Terry Pratchett', 'Neil Gaiman', 'AC/DC Writer'}, set(self.server.feed('/group/author')))
        self.assertEqual({'Good Omens', 'Mort'}, set(self.server.feed('/books?author=Terry%20Pratchett')))
        self.assertEqual(3, self.server.api('/api/books')['total'])
        # Reading everything again writes every column
        result = self.server.api('/settings/scan', {'full': True})['result']
        self.assertEqual((3, 0, 0, 3), (result['total'], result['errors'], result['added'], result['updated']))
        self.server.api('/settings/calibre', {'enabled': True})
        self.assertEqual([], self.server.api('/api/calibre')['libraries'])
        self.assertEqual([], self.server.errors())

    def test_updates(self):
        self.server.install(APP)
        for tag in self.tags:
            with self.subTest(tag):
                self.forget()
                self.server.install(self.checkout(tag))
                self.assertIn('enabled', self.server.occ('app:enable', 'opds'))
                self.server.occ('user:setting', 'admin', 'opds', 'root', '/Books')
                self.assertIn('3 added', self.server.occ('opds:scan', 'admin'))
                self.server.install(APP)
                out = self.server.occ('upgrade')
                self.assertIn('to ' + self.version(), out)
                status = self.server.occ('status')
                self.assertIn('needsDbUpgrade: false', status)
                self.assertIn('maintenance: false', status)
                self.verify()

    def test_fresh(self):
        self.server.install(APP)
        self.forget()
        self.assertIn('enabled', self.server.occ('app:enable', 'opds'))
        self.server.occ('user:setting', 'admin', 'opds', 'root', '/Books')
        self.verify()


if __name__ == '__main__':
    unittest.main(verbosity=2)

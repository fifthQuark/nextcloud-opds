# Changelog

## 1.11.0 – unreleased

* Calibre beta: several books can be changed at once. Choose them with *Select books to change*
  above any list of books, then set the authors, series (numbered in the order shown, if you like),
  publisher, date, languages, rating or custom columns, or add and remove tags and values of list
  columns. Fields left empty stay as they are in each book.

## 1.10.0 – 2026-11-26

* Calibre beta: books are downloaded under their title and first author as Calibre has them, in
  Hebrew, Cyrillic, Greek and every other script. Calibre names the stored files in Latin letters
  only, so a Hebrew book used to arrive as something like `` sypvr `l hbh vkhvshk - `mvs `vz.epub ``.

## 1.9.0 – 2026-11-26

* Calibre beta: custom columns can be edited together with the other fields of a book: text, lists,
  names, fixed values, series with their number, long text, dates, numbers, yes/no and ratings.
  They are written to `metadata.opf` as well, so *Restore database* in Calibre keeps them.
* Calibre beta: an author, series, genre, publisher or value of a custom column can be renamed in all
  books at once from the list of its books. If the new name is used already, the two become one, as
  in Calibre. Renaming an author moves the folders of the books.
* All books of Calibre libraries are read again once after the update.

## 1.8.0 – 2026-11-26

* Calibre beta: a library with a conflict copy of `metadata.db` (Nextcloud, Syncthing and others name
  them "…conflict…") can only be read until the copy is deleted. The book page and the settings say so.
* Every release is now tested automatically before it is published: together with Calibre 4 to 9, and
  updating from every earlier release on SQLite, MariaDB and PostgreSQL.

## 1.7.0 – 2026-11-25

* Calibre beta: EPUBs (also kepub) that are downloaded or opened in a reader get the title, authors,
  series, tags, cover and so on from Calibre, as with Calibre's own server. The stored file is not
  changed, and a book whose file says the same already is sent as it is. Can be turned off in the
  settings, for example if an app recognises books by their file to synchronise the reading position.

## 1.6.0 – 2026-11-25

* OPDS catalog: a book with several formats is now one entry with a download link for each format,
  as in the book browser. The numbers of books in lists count books instead of files.
* Calibre beta: the formats of a book are tied together by Calibre's record instead of the file name.
* Calibre beta: ratings, all languages of a book, identifiers, the sort names of authors and custom
  columns are read. There are lists by rating and by custom columns (text, fixed values, series,
  yes/no, rating), and books can be sorted by rating. Columns built from a template are not shown.
* Calibre beta: tags and text columns that Calibre is set to show as a hierarchy ("Fiction.Fantasy")
  are nested in lists as well.
* Calibre beta: files in the folder of a book that are not among its formats in Calibre are no longer
  listed as books of their own.
* All books of Calibre libraries are read again once after the update.

## 1.5.0 – 2026-11-25

* New setting *Show hidden files*: files and folders whose name starts with a dot are now left out
  of the catalog and the book browser unless it is turned on. Until now they were always listed, so
  turn it on if your books are in such a folder. A library folder that is itself hidden keeps
  working, only what is hidden inside it counts.
* Calibre beta: *Convert the current folder* never moves hidden files. In 1.4.0 it also moved the
  contents of folders such as Syncthing's `.stfolder` and `.stversions` and then removed these
  folders. If you have converted a folder that Syncthing synchronises, create `.stfolder` again.

## 1.4.0 – 2026-11-25

* Calibre beta: the current library folder itself can be made a Calibre library with *Convert the
  current folder*. Book files are moved instead of copied, so that every book is listed once. In a
  folder that is a Calibre library already, books unknown to Calibre are added.

## 1.3.1 – 2026-11-25

* Fixed the update from 1.1.1 or older, which failed with "Unknown column 'work'" when the catalog
  had books whose authors were stored in a single field ("A & B"). Servers on which the update to
  1.3.0 failed can update to this version directly.

## 1.3.0 – 2026-11-25

* Book browser: an *OPDS+* entry in the app menu shows the library with covers, search, sorting and
  lists of authors (with their series), series, genres, publishers, languages, years and formats.
* Beta, off by default: use and edit Calibre libraries (`metadata.db`). Titles, authors, series,
  tags, covers and more can be changed in the book browser; folders and files are renamed the way
  Calibre does. A new Calibre library can be made from the catalog by copying its books.
* Books that were moved or renamed show their new folder after the next scan.
* Books in Calibre's trash folder (`.caltrash`) are no longer listed.

## 1.2.0 – 2026-11-25

* Books with several authors are listed under each of them, also when the file names them in a
  single field ("A & B", "A; B", "A, B and C"). Books already indexed are separated when the app
  is updated; *Re-read all metadata* also catches ambiguous fields such as "First Last, First Last".
* Authors who have written series open to a list of their series, next to *All books* and
  *Not in a series*.
* Fixed the *Ascending* / *Descending* options of book lists, which kept the current order.

## 1.1.1 – 2026-11-20

* Fixed the screen going blank in Firefox when ticking a checkbox in the lower part of the
  settings page (for example *Include images* in the News section).

## 1.1.0 – 2026-11-20

* News digest: when the Nextcloud News app is installed, the catalog gets a *News* entry that
  offers the latest articles as an EPUB, with one part per feed and one chapter per article.
  The personal settings choose all feeds or specific folders and feeds, the time span (last
  24 hours, since midnight, 48 hours or 7 days), the number of articles per feed, unread only,
  and whether images are embedded. Editions are cached and prepared in the background for
  people who use them.
* Renamed the app from "OPDS Catalog" to "OPDS+". The app id (`opds`), feed URLs and saved
  settings are unchanged, so existing installs and reader logins keep working.

## 1.0.0 – 2026-11-14

* Initial release: OPDS 1.2 navigation and acquisition feeds with Basic (app password)
  authentication, metadata extraction for EPUB, PDF, CBZ/CBR, MOBI/AZW, FB2 and DOCX/ODT,
  cover extraction, incremental indexing (request, background job, occ), personal settings page.

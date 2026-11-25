# Changelog

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

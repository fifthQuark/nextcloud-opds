# Changelog

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

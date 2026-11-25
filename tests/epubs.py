"""Small EPUB files for the tests."""
import base64
import zipfile
from xml.sax.saxutils import escape

COVERS = [base64.b64decode(data) for data in (
    '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/2wBDARESEhgVGC8aGi9jQjhCY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2P/wAARCABaADwDASIAAhEBAxEB/8QAGgABAAMBAQEAAAAAAAAAAAAAAAQCAwYFAf/EADEQAAEDAgEHDAMBAAAAAAAAAAABAgMEEYEFEhQxUVKRExYhMzQ1QkNTkrHRVILBsv/EABkBAAMBAQEAAAAAAAAAAAAAAAADBAIBBf/EACERAAICAgICAwEAAAAAAAAAAAABAgMEERJREzEUITNx/9oADAMBAAIRAxEAPwD1gAQlAAAAAAAAAAAAAADRUzOhzc1EW99ZvJK/y8f4CFXNxg2jDTJN1vAaZJut4E4NaPP81nZZDUvkla1UbZdhUefS9obj8HoHGW48nKO2AAcKASV/l4/wrNFTC6bNzVRLX1ghVycoNIgBRocm83iNDk3m8TWzz/DZ0Y0vaG4/B6BLDTPjla5VbZNhUcZbjxcY6YJ5XOSRURypiUE03WKUYqTm99DZ+j5nu3l4jPdvLxMQehxj0K2zfAqrnXVVNppp/FgbjzMhasY6HoAASaBNN1ilJg6JrnKqqo+ixQltmZLaJgUcg3ao5Bu1Sv5NYvgzGn8WBuMWMRl7X6TIhukpzbQyK0gCXKb3R0ErmOVrktZUWy60Of0up/Il96im9CLshVS4tHVAA6UnMc46z04Pav2OcdZ6cHtX7PHA3SJOcuz2OcdZ6cHtX7PdybUvq6GOeRGo517o3V0KqHFHX5C7og/b/SmZJJDK5Nv7NmVu7ZcPlDmzpMrd2y4fKHNimRZv6L+H/9k=',
    '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/2wBDARESEhgVGC8aGi9jQjhCY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2P/wAARCABaADwDASIAAhEBAxEB/8QAGgABAAMBAQEAAAAAAAAAAAAAAAIEBQEDBv/EACsQAAIBAQQIBwEAAAAAAAAAAAABAgMREzJRBBIUITNBYXExUlNykqHBgf/EABkBAAIDAQAAAAAAAAAAAAAAAAADAQIFBP/EACYRAAIBAQcDBQAAAAAAAAAAAAABAhEDBBITMTJRFDOBFSFCYnH/2gAMAwEAAhEDEQA/APkwAbQ4AAAAAAAAAAAAHpRpOtPVi0t1u8CG1FVZ5gtbDPzxGwz88Q8Cs+z5KoLL0KcYtuUd28rtWNoivvQvGcZ7WcABJcFnQOM/b+orFnQOM/b+otHUTb9tl8ADTGI1OHLszKlifc1anDl2ZlSxPuIn3PBo3LazgAA7gWNClGNVuUklq82VzsMSBywqpV2eYsHJqX1P1IfJC+p+pD5IoaqyQ1VkhXVrgj0n7F2rWpKlK2pDwfMypVYOTdvPI93GL8Yp/wzxU7duWJIHdulVK1qWLyGf0LyGf0VwRnyIxstkoYkRJQxI6Z7WPs96/T0ABnmqDONEzgM++/HyAABwFs6nY7TgNJqo9OjqiV4+gvH0Igplw4GZ0+SV4+hEAtGKjoVlOUtWAASVP/9k=',
)]
PAGE = '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>t</title></head><body><p>A page of text.</p></body></html>'
NCX = ('<?xml version="1.0"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head><meta name="dtb:uid" content="u"/></head>'
       '<docTitle><text>t</text></docTitle><navMap><navPoint id="a" playOrder="1"><navLabel><text>x</text></navLabel><content src="x.xhtml"/></navPoint></navMap></ncx>')
NAV = ('<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>t</title></head><body>'
       '<nav epub:type="toc"><ol><li><a href="x.xhtml">x</a></li></ol></nav></body></html>')


def epub(path, title, author, version=2, cover=None, extra=''):
    three = version == 3
    metadata = '<dc:identifier id="uid">urn:uuid:%s</dc:identifier><dc:title>%s</dc:title>' % (escape(title).replace(' ', '-'), escape(title))
    metadata += ('<dc:creator id="a">%s</dc:creator><meta refines="#a" property="role" scheme="marc:relators">aut</meta>' if three
                 else '<dc:creator opf:role="aut">%s</dc:creator>') % escape(author)
    metadata += '<dc:language>en</dc:language>' + extra
    metadata += '<meta property="dcterms:modified">2020-01-01T00:00:00Z</meta>' if three else ''
    manifest = '<item id="x" href="x.xhtml" media-type="application/xhtml+xml"/>'
    manifest += ('<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>' if three
                 else '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>')
    if cover is not None:
        manifest += '<item id="ci" href="cover.jpg" media-type="image/jpeg"%s/>' % (' properties="cover-image"' if three else '')
        metadata += '' if three else '<meta name="cover" content="ci"/>'
    opf = ('<?xml version="1.0" encoding="utf-8"?>\n<package xmlns="http://www.idpf.org/2007/opf" version="%d.0" unique-identifier="uid">'
           '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">%s</metadata>'
           '<manifest>%s</manifest><spine%s><itemref idref="x"/></spine></package>') % (version, metadata, manifest, '' if three else ' toc="ncx"')
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr(zipfile.ZipInfo('mimetype'), 'application/epub+zip', zipfile.ZIP_STORED)
        z.writestr('META-INF/container.xml', '<?xml version="1.0"?><container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">'
                   '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>')
        z.writestr('OEBPS/content.opf', opf)
        z.writestr('OEBPS/x.xhtml', PAGE)
        z.writestr('OEBPS/nav.xhtml' if three else 'OEBPS/toc.ncx', NAV if three else NCX)
        if cover is not None:
            z.writestr('OEBPS/cover.jpg', cover)

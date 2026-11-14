# Build and sign a release tarball for the Nextcloud App Store.
#
#   make tarball                      unsigned tarball (for local testing)
#   make release KEY=opds.key CERT=opds.crt
#                                     signed tarball + the signature string
#                                     that the App Store upload form asks for
#
# Files listed as export-ignore in .gitattributes are left out of the tarball.

APP_ID   := opds
VERSION  := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)
BUILD    := build
STAGE    := $(BUILD)/$(APP_ID)
TARBALL  := $(BUILD)/$(APP_ID)-$(VERSION).tar.gz
IMAGE    ?= docker.io/library/nextcloud:31-apache
KEY      ?=
CERT     ?=

.PHONY: all tarball release stage clean check

all: tarball

check:
	@if grep -q 'OWNER' appinfo/info.xml; then \
		echo "appinfo/info.xml still contains the OWNER placeholder - run: make set-owner OWNER=<github-user>"; exit 1; fi
	@xmllint --noout appinfo/info.xml

stage: check
	rm -rf $(STAGE) && mkdir -p $(STAGE)
	git archive --format=tar HEAD | tar -x -C $(STAGE)

tarball: stage
	tar -czf $(TARBALL) -C $(BUILD) $(APP_ID)
	@echo "Built $(TARBALL)"

release: stage
	@test -n "$(KEY)" -a -n "$(CERT)" || { echo "usage: make release KEY=opds.key CERT=opds.crt"; exit 1; }
	scripts/sign-app.sh $(STAGE) $(KEY) $(CERT) $(IMAGE)
	tar -czf $(TARBALL) -C $(BUILD) $(APP_ID)
	openssl dgst -sha512 -sign $(KEY) $(TARBALL) | openssl base64 -A > $(TARBALL).sig
	@echo
	@echo "Release tarball : $(TARBALL)"
	@echo "Store signature : $(TARBALL).sig  (paste its content into the App Store upload form)"

# Replace the OWNER placeholder in info.xml and the docs with your GitHub user name.
set-owner:
	@test -n "$(OWNER)" || { echo "usage: make set-owner OWNER=<github-user>"; exit 1; }
	sed -i "s#OWNER#$(OWNER)#g" appinfo/info.xml
	@echo "Set GitHub owner to $(OWNER)"

clean:
	rm -rf $(BUILD)

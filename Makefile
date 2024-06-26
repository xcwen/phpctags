source := README.md \
          ChangeLog.md \
          composer.json \
          composer.lock \
          bootstrap.php \
          PHPCtags.php

.PHONY: all
all: clean build/phpctags.phar 
	cp build/phpctags.phar phpctags 

p: clean build/phpctags.phar 
	cp build/phpctags.phar phpctags 
	cp build/phpctags.phar ~/ac-php/phpctags
	~/ac-php/cp_to_elpa.sh

.PHONY: clean
clean:
	@echo "Cleaning executables ..."
	@rm -f ./build/phpctags.phar
	@rm -f ./phpctags
	@echo "Done!"

.PHONY: dist-clean
dist-clean:
	@echo "Cleaning old build files and vendor libraries ..."
	@rm -rf ./build
	@rm -rf ./vendor
	@echo "Done!"

.PHONY: install
install: phpctags
	@echo "Sorry, you need to move phpctags to /usr/bin/phpctags or /usr/local/bin/phpctags or any place you want manually."

build:
	@if [ ! -x build ]; \
	then \
		mkdir build; \
	fi

build/composer.phar: | build
	@echo "Installing composer ..."
	@curl -s http://getcomposer.org/installer | php -- --install-dir=build

vendor: composer.lock build/composer.phar
	@echo "Installing vendor libraries ..."
	@php build/composer.phar install
	@touch vendor/

build/phpctags.phar: vendor $(source) | build
	@php -dphar.readonly=0 buildPHAR.php
	@chmod +x build/phpctags.phar

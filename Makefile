.PHONY: setup test lint phpcs

# TODO: Add graphs module to modules
# ln -s path-to/icingaweb2-module-perfdatagraphs _icingaweb2/modules/perfdatagraphs
setup:
	mkdir -p _libraries &&\
	git clone --depth 1 -b snapshot/nightly https://github.com/Icinga/icinga-php-library.git _libraries/ipl &&\
	git clone --depth 1 -b snapshot/nightly https://github.com/Icinga/icinga-php-thirdparty.git _libraries/vendor &&\
	git clone --depth 1 https://github.com/Icinga/icingaweb2.git _icingaweb2 &&\
	git clone --depth 1 https://github.com/Icinga/icingadb-web.git _icingaweb2/modules/icingadb
	ln -s `pwd` _icingaweb2/modules/perfdatagraphsinfluxdbv2
test:
	ICINGAWEB_LIBDIR=_libraries phpunit
lint:
	phplint application/ library/
phpcs:
	phpcs

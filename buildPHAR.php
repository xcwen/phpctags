<?php

$phar = new Phar('build/phpctags.phar', 0, 'phpctags.phar');

if (version_compare(PHP_VERSION, '5.4.0') < 0) {
    class RecursiveCallbackFilterIterator extends RecursiveFilterIterator {
        public function __construct ( RecursiveIterator $iterator, $callback ) {
            $this->callback = $callback;
            parent::__construct($iterator);
        }

        public function accept () {
            $callback = $this->callback;
            return $callback(parent::current(), parent::key(), parent::getInnerIterator());
        }

        public function getChildren () {
            return new self($this->getInnerIterator()->getChildren(), $this->callback);
        }
    }
}

$phar->buildFromIterator(
    new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(
                getcwd(),
                FilesystemIterator::SKIP_DOTS
            ),
            function ($current) {
                $excludes = array(
                    '.*',
                    'tags',
                    'ac-php/*',
                    'build/*',
                    'tests/*',
                    'Makefile',
                    'bin/phpctags',
                    'phpctags',
                    'buildPHAR.php',
                    'composer.lock',
                    "vendor/nikic/php-parser/test/*",
                    "vendor/nikic/php-parser/test_old/*",
                    "vendor/nikic/php-parser/grammar",
                    "vendor/nikic/php-parser/doc",
                    "vendor/nikic/php-parser/bin",
                    "vendor/nikic/php-parser/UPGRADE-1.0.md",
                    "vendor/nikic/php-parser/README.md",
                    "vendor/nikic/php-parser/phpunit.xml.dist",
                    "vendor/nikic/php-parser/LICENSE",
                    "vendor/nikic/php-parser/composer.json",
                    "vendor/nikic/php-parser/CHANGELOG.md",
                    "vendor/bin",
                    "vendor/doctrine",
                    "vendor/phpdocumentor",
                    "vendor/phpspec",
                    "vendor/phpunit",
                    "vendor/sebastian",
                    "vendor/symfony",
                    "vendor/google/protobuf/[^p]*",
                    "vendor/google/protobuf/proto*",
                    "vendor/google/protobuf/post*",
                    "vendor/google/protobuf/python",
                    "vendor/google/protobuf/php/[^s]*",
                    'README.md',
                    '.tags',
                    "ChangeLog.md", 
                );

                foreach($excludes as $exclude) {
                    if (fnmatch(getcwd().'/'.$exclude, $current->getPathName())) {
                        return false;
                    }
                }

                return true;
            }
        )
    ),
    getcwd()
);

$phar->setStub(
    "#!/usr/bin/env php\n".$phar->createDefaultStub('bootstrap.php')
);

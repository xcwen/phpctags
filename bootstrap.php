<?php
error_reporting(E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_PARSE);
if (file_exists($autoload = __DIR__ . '/vendor/autoload.php')) {
    require($autoload);
} elseif (file_exists($autoload = __DIR__ . '/../../autoload.php')) {
    require($autoload);
} else {
    die(
        'You must set up the project dependencies, run the following commands:'.PHP_EOL.
        'curl -s http://getcomposer.org/installer | php'.PHP_EOL.
        'php composer.phar install'.PHP_EOL
    );
}


require_once(__DIR__ . "/" ."./deal_config.php");


$version = \app\PHPCtags::VERSION;

$copyright = <<<'EOF'
Exuberant Ctags compatiable PHP enhancement, Copyright (C) 2012 Techlive Zheng
Addresses: <techlivezheng@gmail.com>, https://github.com/techlivezheng/phpctags
EOF;

$options = getopt('aC:f:Nno:RuV', array(
    'append::',
    'debug',
    'exclude:',
    'excmd::',
    'fields::',
    'tags_dir::',
    'save-common-el::',
    'kinds::',
    'format::',
    'help',
    'recurse::',
    'sort::',
    'rebuild::',
    'realpath_flag::',
    'verbose::',
    'version',
    'memory::',
    'files::',
    'config-file::',
    "test::",
));

$options_info = <<<'EOF'
phpctags currently only supports a subset of the original ctags' options.

Usage: phpctags [options] [file(s)]

  -a   Append the tags to an existing tag file.
  -f <name>
       Write tags to specified file. Value of "-" writes tags to stdout
       ["tags"].
  -C <name>
       Use a cache file to store tags for faster updates.
  -n   Equivalent to --excmd=number.
  -N   Equivalent to --excmd=pattern.
  -o   Alternative for -f.
  -R   Equivalent to --recurse.
  -u   Equivalent to --sort=no.
  -V   Equivalent to --verbose.
  --append=[yes|no]
       Should tags should be appended to existing tag file [no]?
  --debug
       phpctags only
       Repect PHP's error level configuration.
  --exclude=pattern
      Exclude files and directories matching 'pattern'.
  --excmd=number|pattern|mix
       Uses the specified type of EX command to locate tags [mix].
  --fields=[+|-]flags
       Include selected extension fields (flags: "afmikKlnsStz") [fks].
  --kinds=[+|-]flags
       Enable/disable tag kinds [cmfpvditn]
  --format=level
       Force output of specified tag file format [2].
  --help
       Print this option summary.
  --memory=[-1|bytes|KMG]
       phpctags only
       Set how many memories phpctags could use.
  --recurse=[yes|no]
       Recurse into directories supplied on command line [no].
  --sort=[yes|no|foldcase]
       Should tags be sorted (optionally ignoring case) [yes]?.
  --verbose=[yes|no]
       Enable verbose messages describing actions on each source file.
  --version
       Print version identifier to standard output.
EOF;

// prune options and its value from the $argv array
$argv_ = array();

foreach ($options as $option => $value) {
    foreach ($argv as $key => $chunk) {
        $regex = '/^'. (isset($option[1]) ? '--' : '-') . $option . '/';
        if ($chunk == $value && $argv[$key-1][0] == '-' || preg_match($regex, $chunk)) {
            array_push($argv_, $key);
        }
    }
}

while ($key = array_pop($argv_)) {
    unset($argv[$key]);
}

// option -v is an alternative to --verbose
if (isset($options['V'])) {
    $options['verbose'] = 'yes';
}

if (isset($options['save-common-el'])) {
    $common_json_file=__DIR__. "/common.json";

    $json_data= json_decode(file_get_contents($common_json_file), true);
    save_as_el($options['save-common-el'], $json_data[0], $json_data[1], $json_data[2], $json_data[3]);

    exit;
}




if (isset($options['verbose'])) {
    if ($options['verbose'] === false || yes_or_no($options['verbose']) == 'yes') {
        $options['V'] = true;
    } elseif (yes_or_no($options['verbose']) != 'no') {
        die('phpctags: Invalid value for "verbose" option'.PHP_EOL);
    } else {
        $options['V'] = false;
    }
} else {
    $options['V'] = false;
}

if (isset($options['debug'])) {
    $options['debug'] = true;
} else {
    #error_reporting(0);
}

if (isset($options['help'])) {
    echo "Version: ".$version."\n\n".$copyright;
    echo PHP_EOL;
    echo PHP_EOL;
    echo $options_info;
    echo PHP_EOL;
    exit;
}

if (isset($options['version'])) {
    echo "Version: ".$version."\n\n".$copyright;
    echo PHP_EOL;
    exit;
}

array_shift($argv);

// option -o is an alternative to -f
if (isset($options['o']) && !isset($options['f'])) {
    $options['f'] = $options['o'];
}

// if both -n and -N options are given, use the last specified one
if (isset($options['n']) && isset($options['N'])) {
    if (array_search('n', array_keys($options)) < array_search('N', array_keys($options))) {
        unset($options['n']);
    } else {
        unset($options['N']);
    }
}

// option -n is equivalent to --excmd=number
if (isset($options['n']) && !isset($options['N'])) {
    $options['excmd'] = 'number';
}

// option -N is equivalent to --excmd=pattern
if (isset($options['N']) && !isset($options['n'])) {
    $options['excmd'] = 'pattern';
}

if (!isset($options['excmd'])) {
    $options['excmd'] = 'pattern';
}
if (!isset($options['format'])) {
    $options['format'] = 2;
}
if (!isset($options['memory'])) {
    $options['memory'] = '1024M';
}
if (!isset($options['fields'])) {
    $options['fields'] = array('n', 'k', 's', 'a','i');
} else {
    $options['fields'] = str_split($options['fields']);
}

if (!isset($options['kinds'])) {
    $options['kinds'] = array('c', 'm', 'f', 'p', 'd', 'v', 'i', 't', 'n','T');
} else {
    $options['kinds'] = str_split($options['kinds']);
}


// handle -u or --sort options
if (isset($options['sort'])) {
    // --sort or --sort=[Y,y,YES,Yes,yes]
    if ($options['sort'] === false || yes_or_no($options['sort']) == 'yes') {
        $options['sort'] = 'yes';
        // --sort=[N,n,NO,No,no]
    } elseif (yes_or_no($options['sort']) == 'no') {
        $options['sort'] = 'no';
        // --sort=foldcase, case insensitive sorting
    } elseif ($options['sort'] == 'foldcase') {
        $options['sort'] = 'foldcase';
    } else {
        die('phpctags: Invalid value for "sort" option'.PHP_EOL);
    }
    // option -n is equivalent to --sort=no
} elseif (isset($options['u'])) {
    $options['sort'] = 'no';
    // sort the result by default
} else {
    $options['sort'] = 'yes';
}

// if the memory limit option is set and is valid, adjust memory
if (isset($options['memory'])) {
    $memory_limit = trim($options['memory']);
    if (isMemoryLimitValid($memory_limit)) {
        ini_set('memory_limit', $memory_limit);
    }
}

if (isset($options['append'])) {
    if ($options['append'] === false || yes_or_no($options['append']) == 'yes') {
        $options['a'] = false;
    } elseif (yes_or_no($options['append']) != 'no') {
        die('phpctags: Invalid value for "append" option'.PHP_EOL);
    }
}

if (isset($options['recurse'])) {
    if ($options['recurse'] === false || yes_or_no($options['recurse']) == 'yes') {
        $options['R'] = false;
    } elseif (yes_or_no($options['recurse']) != 'no') {
        die('phpctags: Invalid value for "recurse" option'.PHP_EOL);
    }
}


// if option -R is given and no file is specified, use current working directory
if (isset($options['R']) && empty($argv)) {
    $argv[] = getcwd();
}

//

try {
    //print_r($options);
    if ($options["config-file"]) {
        deal_config(
            $options["config-file"],
            yes_or_no($options['rebuild']) == 'yes',
            yes_or_no($options['realpath_flag']) == 'yes',
            $options["tags_dir"],
            yes_or_no(@$options['test']) == 'yes'
        );

        exit;
    } else {
        $ctags = new \app\PHPCtags($options);
        $ctags->addFiles($argv);
        $result = $ctags->export();
    }
} catch (Exception $e) {
    echo $e->getMessage();

    exit;
}
// write to a specified file
if (isset($options['f']) && $options['f'] !== '-') {
    $tagfile = fopen($options['f'], isset($options['a']) ? 'a' : 'w');
    // write to stdout only when instructed
} elseif (isset($options['f']) && $options['f'] === '-') {
    $tagfile = fopen('php://stdout', 'w');
    // write to file 'tags' by default
} else {
    $tagfile = fopen('tags', isset($options['a']) ? 'a' : 'w');
}

$mode = ($options['sort'] == 'yes' ? 1 : ($options['sort'] == 'foldcase' ? 2 : 0));

if (!isset($options['a'])) {
    $tagline = <<<EOF
!_TAG_FILE_FORMAT\t2\t/extended format; --format=1 will not append ;" to lines/
!_TAG_FILE_SORTED\t{$mode}\t/0=unsorted, 1=sorted, 2=foldcase/
!_TAG_PROGRAM_AUTHOR\ttechlivezheng\t/techlivezheng@gmail.com/
!_TAG_PROGRAM_NAME\tphpctags\t//
!_TAG_PROGRAM_URL\thttps://github.com/techlivezheng/phpctags\t/official site/
!_TAG_PROGRAM_VERSION\t{$version}\t//\n
EOF;
}

$ret=fwrite($tagfile, $result);
fclose($tagfile);

function yes_or_no($arg)
{
    if (preg_match('/\b[Y|y]([E|e][S|s])?\b/', $arg)) {
        return 'yes';
    } elseif (preg_match('/\b[N|n]([O|o])?\b/', $arg)) {
        return 'no';
    } else {
        return false;
    }
}

function isMemoryLimitValid($memory_limit)
{
    if ($memory_limit == "-1") {
        // no memory limit
        return true;
    } elseif (is_numeric($memory_limit) && $memory_limit > 0) {
        // memory limit provided in bytes
        return true;
    } elseif (preg_match("/\d+\s*[KMG]/", $memory_limit)) {
        // memory limit provided in human readable sizes
        // as specified here: http://www.php.net/manual/en/faq.using.php#faq.using.shorthandbytes
        return true;
    }

    return false;
}

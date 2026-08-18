<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/deal_config.php';

function test_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_remove_tree($path)
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            test_remove_tree($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
}

function test_make_workspace()
{
    $path = sys_get_temp_dir() . '/phpctags-incremental-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0777, true)) {
        throw new RuntimeException('Unable to create test workspace');
    }
    return $path;
}

function test_atomic_publication()
{
    test_assert(function_exists('atomic_write_file_if_changed'), 'atomic writer is not available');

    $workspace = test_make_workspace();
    try {
        $target = $workspace . '/tags.el';
        file_put_contents($target, 'old');
        clearstatcache(true, $target);
        $old_inode = fileinode($target);

        test_assert(atomic_write_file_if_changed($target, 'new') === true, 'changed content was not published');
        clearstatcache(true, $target);
        test_assert(file_get_contents($target) === 'new', 'published content is incomplete');
        test_assert(fileinode($target) !== $old_inode, 'changed content did not replace the target inode');
        test_assert(glob($workspace . '/.tags.el.tmp.*') === array(), 'temporary publication file was left behind');

        try {
            atomic_write_stream_if_changed($target, function ($stream) {
                write_all_to_stream($stream, 'partial');
                throw new RuntimeException('simulated generation failure');
            });
            throw new RuntimeException('simulated generation failure did not propagate');
        } catch (RuntimeException $error) {
            test_assert($error->getMessage() === 'simulated generation failure', 'unexpected atomic writer failure');
        }
        test_assert(file_get_contents($target) === 'new', 'failed generation damaged the published target');
        test_assert(glob($workspace . '/.tags.el.tmp.*') === array(), 'failed generation left a temporary file');

        $mtime = filemtime($target);
        $inode = fileinode($target);
        usleep(1100000);
        test_assert(atomic_write_file_if_changed($target, 'new') === false, 'identical content was reported as changed');
        clearstatcache(true, $target);
        test_assert(filemtime($target) === $mtime, 'identical content changed the target mtime');
        test_assert(fileinode($target) === $inode, 'identical content replaced the target inode');

        $real_target = $workspace . '/real-tags.el';
        $link_target = $workspace . '/linked-tags.el';
        file_put_contents($real_target, 'linked-old');
        symlink($real_target, $link_target);
        test_assert(atomic_write_file_if_changed($link_target, 'linked-new') === true, 'symlink target was not published');
        test_assert(is_link($link_target), 'atomic publication replaced the symlink itself');
        test_assert(file_get_contents($real_target) === 'linked-new', 'atomic publication did not update the symlink target');
    } finally {
        test_remove_tree($workspace);
    }
}

function test_source_snapshot_is_reused()
{
    $workspace = test_make_workspace();
    try {
        $source_file = $workspace . '/Source.php';
        file_put_contents($source_file, '<?php function broken(');

        $source = <<<'PHP'
<?php
namespace Demo;
class Source
{
    public function result(): FirstResult|SecondResult {}
}
PHP;

        $ctags = new \app\PHPCtags(array());
        $result = $ctags->process_single_file($source_file, $source);
        $methods = array_values(array_filter($result, function ($item) {
            return $item['kind'] === 'm' && $item['name'] === 'result';
        }));

        test_assert(count($methods) === 1, 'supplied source snapshot was not parsed');
        test_assert($methods[0]['type'] === '\\Demo\\FirstResult', 'union return type output changed');
    } finally {
        test_remove_tree($workspace);
    }
}

function test_legacy_constructor_output($class_map, $construct_map, $inherit_map, $class_define_map)
{
    $function_list = array();
    foreach (array_keys($class_map) as $class_name) {
        $seen = array();
        $find_item = null;
        $parent_class = $class_name;
        $previous_class = '';
        do {
            $find_item = isset($construct_map[$parent_class]) ? $construct_map[$parent_class] : null;
            if ($find_item) {
                break;
            }
            $seen[$parent_class] = true;
            $previous_class = $parent_class;
            $parent_class = isset($inherit_map[$parent_class][0]) ? $inherit_map[$parent_class][0] : null;
            if ($parent_class && $parent_class[0] !== '\\') {
                $parent_class = preg_replace('/[A-Za-z0-8_]*$/', '', $previous_class) . $parent_class;
            }
        } while ($parent_class && !isset($seen[$parent_class]));

        if (!$find_item) {
            $find_item = isset($class_define_map[$class_name]) ? $class_define_map[$class_name] : null;
        }
        if ($find_item) {
            $function_list[] = array('f', $class_name . '(', $find_item[0], $find_item[1], $class_name);
        }
    }
    return $function_list;
}

function test_constructor_memoization_preserves_output()
{
    $class_map = array(
        '\\Demo\\Base' => array(),
        '\\Demo\\Child' => array(),
        '\\Demo\\GrandChild' => array(),
        '\\Demo\\Standalone' => array(),
    );
    $construct_map = array('\\Demo\\Base' => array('$value', '0:10'));
    $inherit_map = array(
        '\\Demo\\Child' => array('Base'),
        '\\Demo\\GrandChild' => array('Child'),
    );
    $class_define_map = array(
        '\\Demo\\Base' => array('', '0:1'),
        '\\Demo\\Child' => array('', '0:2'),
        '\\Demo\\GrandChild' => array('', '0:3'),
        '\\Demo\\Standalone' => array('', '0:4'),
    );
    $expected = test_legacy_constructor_output($class_map, $construct_map, $inherit_map, $class_define_map);
    $actual = array();

    construct_map_to_function_list(
        $class_map,
        $construct_map,
        $inherit_map,
        $actual,
        $class_define_map
    );

    test_assert($actual === $expected, 'constructor inheritance output changed');
}

function test_constructor_resolution_handles_digit_nine()
{
    $class_map = array(
        '\\Demo\\Base' => array(),
        '\\Demo\\Child9' => array(),
    );
    $construct_map = array('\\Demo\\Base' => array('$baseArg', 'base.php:1'));
    $inherit_map = array('\\Demo\\Child9' => array('Base'));
    $class_define_map = array('\\Demo\\Child9' => array('', 'child.php:1'));
    $actual = array();

    construct_map_to_function_list(
        $class_map,
        $construct_map,
        $inherit_map,
        $actual,
        $class_define_map
    );

    test_assert(count($actual) === 2, 'constructor entries were not generated');
    test_assert($actual[1][2] === '$baseArg', 'class name ending in 9 did not inherit its constructor');
}

function test_legacy_el_output($class_map, $function_list, $inherit_map, $file_list)
{
    $contents="(setq  g-ac-php-tmp-tags  [\n(\n";
    foreach ($class_map as $class_name => $members) {
        $class_name_str=addslashes($class_name);
        $contents .= "  (\"" . $class_name_str . "\".[\n";
        foreach ($members as $member) {
            $doc=addslashes(isset($member[2]) ? $member[2] : '');
            $return_type=addslashes(isset($member[4]) ? $member[4] : '');
            $name=is_string($member[1]) ? addslashes($member[1]) : '';
            $contents .= "    [\"{$member[0]}\" \"$name\" \"{$doc}\"  \"{$member[3]}\"  \"$return_type\" \"$class_name_str\" \"{$member[6]}\" \"{$member[7]}\" ]\n";
        }
        $contents .= "  ])\n";
    }
    $contents .= ")\n[\n";

    foreach ($function_list as $function) {
        $doc=addslashes(isset($function[2]) ? $function[2] : '');
        $name=addslashes(isset($function[1]) ? $function[1] : '');
        $return_type=addslashes(isset($function[4]) ? $function[4] : '');
        $contents .= "  [\"{$function[0]}\" \"$name\" \"{$doc}\"  \"{$function[3]}\"  \"$return_type\"  ]\n";
    }
    $contents .= "]\n(\n";

    foreach ($inherit_map as $class_name => $parents) {
        $contents .= "  (\"" . addslashes($class_name) . "\". [ ";
        foreach ($parents as $parent) {
            $contents .= "\"" . addslashes($parent) . "\" ";
        }
        $contents .= "])\n";
    }
    $contents .= ")\n[\n";

    foreach ($file_list as $file) {
        $contents .= "  \"" . addslashes($file) . "\"\n";
    }
    return $contents . "]\n])\n";
}

function test_el_serialization_compatibility()
{
    $workspace=test_make_workspace();
    try {
        $class_map=array(
            '\\Demo\\Thing' => array(
                array('m', 'run(', '$arg="value"', '0:12', '\\Demo\\Result', '\\Demo\\Thing', 'public', '1'),
                array('p', 'value', '', '0:8', 'string', '\\Demo\\Thing', 'protected', ''),
            ),
        );
        $functions=array(
            array('f', '\\Demo\\helper(', '$arg', '0:20', 'void'),
        );
        $inherits=array('\\Demo\\Thing' => array('\\Demo\\Base'));
        $files=array('/project/Thing.php');
        $target=$workspace . '/tags.el';

        save_as_el($target, $class_map, $functions, $inherits, $files);
        test_assert(
            file_get_contents($target) === test_legacy_el_output($class_map, $functions, $inherits, $files),
            'Elisp serialization bytes changed'
        );
    } finally {
        test_remove_tree($workspace);
    }
}

function test_incremental_noop_and_progress()
{
    $workspace = test_make_workspace();
    try {
        $project = $workspace . '/project';
        $output = $workspace . '/output';
        mkdir($project);
        mkdir($output);
        $project = realpath($project);
        $output = realpath($output);

        $fixture = <<<'PHP'
<?php
namespace Demo;
class ParentType
{
    public function __construct(int $value = 1) {}
}
class ChildType extends ParentType
{
    public function value(): FirstResult|SecondResult {}
}
PHP;
        file_put_contents($project . '/Fixture.php', $fixture);
        for ($i = 0; $i < 120; ++$i) {
            file_put_contents($project . sprintf('/File%03d.php', $i), '<?php $value = ' . $i . ';');
        }

        $cache_file = $output . '/tags-cache-v3.json';
        file_put_contents($cache_file, json_encode(array('vendor_file_count' => 0)));
        $ruleset = Ruleset::loadFromStrings(array());
        $ruleset->check_start_pos = strlen($project) + 1;

        ob_start();
        deal_file_tags(
            false,
            $cache_file,
            false,
            false,
            $project,
            $output,
            true,
            array($project),
            array(),
            array('php'),
            0,
            100,
            $ruleset
        );
        $progress = ob_get_clean();

        preg_match_all('/^(\d+)% /m', $progress, $matches);
        $percentages = array_map('intval', $matches[1]);
        test_assert(count($percentages) > 1, 'focused run did not emit progress');
        test_assert($percentages === array_values(array_unique($percentages)), 'progress repeated an integer percentage');
        test_assert(!preg_match('/\d+\.\d+%/', $progress), 'progress emitted a fractional percentage');

        $tag_map_file = $output . '/tag-file-map.json';
        $tags_file = $output . '/tags.el';
        test_assert(is_file($tag_map_file), 'tag-file-map.json was not generated');
        test_assert(is_file($tags_file), 'tags.el was not generated');

        $tag_map = json_decode(file_get_contents($tag_map_file), true);
        $fixture_result = $tag_map[$project . '/Fixture.php']['result'];
        $methods = array_values(array_filter($fixture_result, function ($item) {
            return $item['kind'] === 'm' && $item['name'] === 'value';
        }));
        test_assert(count($methods) === 1, 'fixture method was not indexed');
        test_assert($methods[0]['type'] === '\\Demo\\FirstResult', 'generated return type changed');

        clearstatcache();
        $before = array(
            'map_mtime' => filemtime($tag_map_file),
            'map_inode' => fileinode($tag_map_file),
            'tags_mtime' => filemtime($tags_file),
            'tags_inode' => fileinode($tags_file),
        );
        usleep(1100000);

        ob_start();
        deal_file_tags(
            false,
            $cache_file,
            false,
            false,
            $project,
            $output,
            true,
            array($project),
            array(),
            array('php'),
            0,
            100,
            $ruleset
        );
        ob_end_clean();

        clearstatcache();
        test_assert(filemtime($tag_map_file) === $before['map_mtime'], 'no-op changed tag-file-map.json mtime');
        test_assert(fileinode($tag_map_file) === $before['map_inode'], 'no-op replaced tag-file-map.json');
        test_assert(filemtime($tags_file) === $before['tags_mtime'], 'no-op changed tags.el mtime');
        test_assert(fileinode($tags_file) === $before['tags_inode'], 'no-op replaced tags.el');

        $source_file = $project . '/Fixture.php';
        $source_mtime = filemtime($source_file);
        $source_inode = fileinode($source_file);
        $updated_fixture = str_replace('function value()', 'function other()', $fixture);
        file_put_contents($source_file, $updated_fixture);
        touch($source_file, $source_mtime);
        clearstatcache(true, $source_file);
        test_assert(filemtime($source_file) === $source_mtime, 'fixture replacement did not preserve mtime');
        test_assert(fileinode($source_file) === $source_inode, 'fixture replacement changed inode');

        // Make the cached metadata exactly match the modified file while
        // retaining the old hash/result.  Only content validation can detect it.
        $tag_map = json_decode(file_get_contents($tag_map_file), true);
        $tag_map[$source_file]['source_signature'] = source_file_signature($source_file);
        file_put_contents($tag_map_file, json_encode($tag_map, JSON_PRETTY_PRINT));

        ob_start();
        deal_file_tags(
            false,
            $cache_file,
            false,
            false,
            $project,
            $output,
            true,
            array($project),
            array(),
            array('php'),
            0,
            100,
            $ruleset
        );
        ob_end_clean();

        $tag_map = json_decode(file_get_contents($tag_map_file), true);
        $fixture_result = $tag_map[$source_file]['result'];
        $other_methods = array_values(array_filter($fixture_result, function ($item) {
            return $item['kind'] === 'm' && $item['name'] === 'other';
        }));
        test_assert(count($other_methods) === 1, 'preserved-mtime source replacement reused stale tags');

        clearstatcache();
        $before = array(
            'map_mtime' => filemtime($tag_map_file),
            'map_inode' => fileinode($tag_map_file),
            'tags_mtime' => filemtime($tags_file),
            'tags_inode' => fileinode($tags_file),
        );

        file_put_contents(
            $source_file,
            $updated_fixture . "\nnamespace Demo; function addedFunction(): int { return 1; }\n"
        );
        clearstatcache(true, $source_file);
        ob_start();
        deal_file_tags(
            false,
            $cache_file,
            false,
            false,
            $project,
            $output,
            true,
            array($project),
            array(),
            array('php'),
            0,
            100,
            $ruleset
        );
        ob_end_clean();

        clearstatcache();
        test_assert(fileinode($tag_map_file) !== $before['map_inode'], 'changed tag map was not atomically replaced');
        test_assert(fileinode($tags_file) !== $before['tags_inode'], 'changed tags.el was not atomically replaced');
        test_assert(glob($output . '/.tag-file-map.json.tmp.*') === array(), 'tag map temporary file was left behind');
        test_assert(glob($output . '/.tags.el.tmp.*') === array(), 'tags.el temporary file was left behind');
    } finally {
        test_remove_tree($workspace);
    }
}

$tests = array(
    'atomic publication' => 'test_atomic_publication',
    'source snapshot reuse' => 'test_source_snapshot_is_reused',
    'constructor compatibility' => 'test_constructor_memoization_preserves_output',
    'constructor digit-nine resolution' => 'test_constructor_resolution_handles_digit_nine',
    'Elisp serialization compatibility' => 'test_el_serialization_compatibility',
    'incremental no-op and progress' => 'test_incremental_noop_and_progress',
);
$failures = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $error) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n");
    }
}

if ($failures > 0) {
    exit(1);
}

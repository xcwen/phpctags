<?php
require_once(__DIR__. "/GitIgnore/Ruleset.php");

function write_all_to_stream($stream, $contents)
{
    $length = strlen($contents);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($stream, substr($contents, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException("Unable to write generated tags data");
        }
        $offset += $written;
    }
}

function files_have_same_content($first_file, $second_file)
{
    if (!is_file($first_file) || filesize($first_file) !== filesize($second_file)) {
        return false;
    }

    $first_hash = hash_file('sha256', $first_file);
    $second_hash = hash_file('sha256', $second_file);
    return is_string($first_hash)
        && is_string($second_hash)
        && hash_equals($first_hash, $second_hash);
}

function atomic_write_stream_if_changed($file_name, callable $writer)
{
    $publication_file = $file_name;
    if (is_link($file_name)) {
        $publication_file = realpath($file_name);
        if ($publication_file === false) {
            throw new RuntimeException("Unable to resolve symbolic link {$file_name}");
        }
    }

    $directory = dirname($publication_file);
    $temporary_file = tempnam($directory, '.' . basename($publication_file) . '.tmp.');
    if ($temporary_file === false) {
        throw new RuntimeException("Unable to create a temporary file for {$file_name}");
    }

    $stream = null;
    try {
        $stream = fopen($temporary_file, 'wb');
        if ($stream === false) {
            throw new RuntimeException("Unable to open temporary file for {$file_name}");
        }

        $writer($stream);
        if (!fflush($stream)) {
            throw new RuntimeException("Unable to flush temporary file for {$file_name}");
        }
        if (function_exists('fsync') && !fsync($stream)) {
            throw new RuntimeException("Unable to sync temporary file for {$file_name}");
        }
        fclose($stream);
        $stream = null;

        clearstatcache(true, $publication_file);
        clearstatcache(true, $temporary_file);
        if (files_have_same_content($publication_file, $temporary_file)) {
            unlink($temporary_file);
            return false;
        }

        $existing_mode = @fileperms($publication_file);
        if ($existing_mode !== false) {
            chmod($temporary_file, $existing_mode & 0777);
        } else {
            $mask = umask();
            umask($mask);
            chmod($temporary_file, 0666 & ~$mask);
        }

        if (!rename($temporary_file, $publication_file)) {
            throw new RuntimeException("Unable to publish generated file {$file_name}");
        }
        return true;
    } catch (Throwable $error) {
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (file_exists($temporary_file)) {
            unlink($temporary_file);
        }
        throw $error;
    }
}

function atomic_write_file_if_changed($file_name, $contents)
{
    return atomic_write_stream_if_changed($file_name, function ($stream) use ($contents) {
        write_all_to_stream($stream, $contents);
    });
}

function encode_json($data, $flags = 0)
{
    $contents = json_encode($data, $flags);
    if ($contents === false) {
        throw new RuntimeException('Unable to encode generated tags data as JSON: ' . json_last_error_msg());
    }
    return $contents;
}

function read_source_snapshot($file_name)
{
    $source = file_get_contents($file_name);
    if ($source === false) {
        throw new RuntimeException("Unable to read source file {$file_name}");
    }
    return array($source, md5($source));
}

function source_file_signature($file_name)
{
    clearstatcache(true, $file_name);
    $stat = @stat($file_name);
    if ($stat === false) {
        throw new RuntimeException("Unable to stat source file {$file_name}");
    }
    return array(
        'mtime' => (int) $stat['mtime'],
        'ctime' => (int) $stat['ctime'],
        'size' => (int) $stat['size'],
        'ino' => isset($stat['ino']) ? (int) $stat['ino'] : 0,
        'dev' => isset($stat['dev']) ? (int) $stat['dev'] : 0,
    );
}

function get_config($config_file)
{

    $json_data=trim(file_get_contents($config_file));
    if ($json_data=="") {
        $json_data=json_encode([
            "use-cscope"=> null,
            "tag-dir" => null,
            "filter"=> [
                "can-use-external-dir"=> true,
                "php-file-ext-list"=> [
                    "php"
                ],
                "php-path-list"=> [
                    "."
                ],
                "php-path-list-without-subdir"=> [],
            ]
        ], JSON_PRETTY_PRINT);
        file_put_contents($config_file, $json_data);
    }
    return json_decode($json_data, true);
}

function deal_tags($file_index, &$result, &$class_inherit_map, &$class_map, &$function_list, &$construct_map, &$class_define_map)
{
    foreach ($result as &$item) {
        $kind=$item["kind"] ;
        $scope=$item["scope"];
        $name=$item["name"];
        //$file=$item["file"];
        $line=$item["line"];
        $file_pos=$file_index.":". $line;
        switch ($kind) {
            case "c":
            case "i":
            case "t":
                $class_name=$scope."\\".$name;
                if (isset($item["inherits"])) {
                    if (isset($class_inherit_map[$class_name])) {
                        $arr=$item["inherits"];
                        foreach ($class_inherit_map[$class_name] as $inherit) {
                            $arr[]=  $inherit;
                        }
                        $class_inherit_map[$class_name] = $arr;
                    } else {
                        $class_inherit_map[$class_name] = $item["inherits"];
                    }
                }
                $return_type=$class_name;
                $function_list[ ]= [ $kind , $class_name , "" , $file_pos , $return_type ] ;
                if (!isset($class_map[$class_name])) {
                    $class_map[$class_name] =[];
                }
                $class_define_map[ $class_name ] =[ "", $file_pos];

                break;

            case "T":
                $class_name = $item["scope"];
                $class_inherit_map[$class_name][]=  $item["type"]  ;
                break;
            case "m":
            case "p":
                $class_name= $scope;
                $doc=@$item["args"];
                $return_type=@$item["type"];
                $access=$item["access"];
                $static =@$item["static"];

                $preg_str= "/\\\\$name\$/";
                if ($name=="__construct" ||  preg_match($preg_str, $class_name)) {
                    $construct_map[ $class_name ] =[ $doc , $file_pos];
                }

                if ($kind=="m") {
                    $name.="(";
                }
                $class_map[$class_name][] =[
                    $kind , $name, $doc , $file_pos , $return_type, $class_name,$access , $static  ];

                break;

            case "f":
                $function_name=$scope."\\".$name;
                $doc=@$item["args"];
                $return_type=@$item["type"];
                $function_list[ ]= [ $kind , $function_name."(" , $doc , $file_pos , $return_type ] ;
                break;
            case "d":
                $define_scope=$item["args"];
                $return_type=$item["type"];
                $access=$item["access"];
                $doc="";
                if ($define_scope =="class") {
                    $class_name= $scope;
                    $class_map[$class_name][] =[
                        $kind , $name, $doc , $file_pos , $return_type,$class_name  , $access, "" ];
                } else {
                    if (is_array($name) && isset($name['name'])) {
                        $name = $name['name'];
                    }
                    $define_name=$scope."\\".$name;
                    $function_list[ ]= [ $kind , $define_name, $doc , $file_pos , $return_type ] ;
                }

                break;


            default:
                break;
        }
    }
}
function get_path($cur_dir, $path)
{
    $path=trim($path);
    if ($path[0] =="/") {
        return $path;
    }
    //相对路径
    return  normalizePath($cur_dir."/".$path);
}
function normalizePath($path)
{
    $parts = array();// Array to build a new path from the good parts
    $path = str_replace('\\', '/', $path);// Replace backslashes with forwardslashes
    $path = preg_replace('/\/+/', '/', $path);// Combine multiple slashes into a single slash
    $segments = explode('/', $path);// Collect path segments
    $test = '';// Initialize testing variable
    foreach ($segments as $segment) {
        if ($segment != '.') {
            $test = array_pop($parts);
            if (is_null($test)) {
                $parts[] = $segment;
            } elseif ($segment == '..') {
                if ($test == '..') {
                    $parts[] = $test;
                }

                if ($test == '..' || $test == '') {
                    $parts[] = $segment;
                }
            } else {
                $parts[] = $test;
                $parts[] = $segment;
            }
        }
    }
    return implode('/', $parts);
}

function deal_file_tags($cache_flag, $cache_file_name, $test_flag, $rebuild_all_flag, $cur_work_dir, $obj_dir, $realpath_flag, $php_path_list, $php_path_list_without_subdir, $php_file_ext_list, $start_pecent, $max_percent, $ignore_ruleset)
{
    //得到要处理的文件
    $file_list=[];


    $ctags = new \app\PHPCtags([]);
    $i=0;

    $class_map= [];
    $function_list= [];
    $class_inherit_map= [];


    $cache_file_count=0;

    if (!$test_flag) {
        if ($cache_flag) {
            $common_json_file=__DIR__. "/common.json";
            // } else {
            //     $common_json_file=  $cache_file_name;
            // }
            $json_data= json_decode(file_get_contents($common_json_file), true);
            $class_map= $json_data[0];//类信息
            $function_list= $json_data[1];//函数,常量
            $class_inherit_map= $json_data[2];//继承
            $file_list= $json_data[3];//原先的文件列表
        } else {
            $config_data= json_decode(file_get_contents($cache_file_name), true);
            if (isset($config_data["vendor_file_count"])) {
                $cache_file_count=$config_data["vendor_file_count"];
            }
        }
    }
    /**  @var Ruleset  $ignore_ruleset */

    foreach ($php_path_list as $dir) {
        if ($realpath_flag) {
            $dir=   realpath($dir);
        } else {
            $dir=  get_path($cur_work_dir, $dir);
        }
        if (strpos($dir, $cur_work_dir) !== 0) {
            $dir_len=strlen($dir);
            $ignore_ruleset->check_start_pos=$dir_len+1;
        }
        get_filter_file_list($cache_flag, $file_list, $dir, $php_file_ext_list, true, $ignore_ruleset);
        if (strpos($dir, $cur_work_dir) !== 0) {
            $cur_work_dir_len=strlen($cur_work_dir);
            $ignore_ruleset->check_start_pos=$cur_work_dir_len+1;
        }
    }

    foreach ($php_path_list_without_subdir as $dir) {
        if ($realpath_flag) {
            $dir=   realpath($dir);
        } else {
            $dir=  get_path($cur_work_dir, $dir);
        }
        if (strpos($dir, $cur_work_dir) !== 0) {
            $dir_len=strlen($dir);
            $ignore_ruleset->check_start_pos=$dir_len+1;
        }

        get_filter_file_list($cache_flag, $file_list, $dir, $php_file_ext_list, false, $ignore_ruleset);
        if (strpos($dir, $cur_work_dir) !== 0) {
            $cur_work_dir_len=strlen($cur_work_dir);
            $ignore_ruleset->check_start_pos=$cur_work_dir_len+1;
        }
    }

    $ret_file_list =[];
    foreach ($file_list as $file) {
        if (strpos($file, $cur_work_dir) !== 0 || !$ignore_ruleset->match($file)) {
            $ret_file_list[]=$file;
        } else {
            echo "filter-ignore:$file\n";
        }
    }
    $file_list=$ret_file_list;


    $deal_all_count=count($file_list);


    if ($cache_flag) {
        $tags_data='{}';
    } else {
        $tags_file="$obj_dir/tag-file-map.json";
        $tags_data=@file_get_contents($tags_file);
        if (!$tags_data) {
            $tags_data='{}';
        }
    }


    $tags_map = json_decode($tags_data, true);
    if (!is_array($tags_map)) {
        $tags_map = array();
    }
    $find_time=time();
    $last_percent=-1;
    $seen_tag_keys=array();
    $construct_map=[];
    $class_define_map=[];

    $result=null;
    for ($i= 0; $i< $deal_all_count; $i++) {
        $file_index=$cache_file_count+$i ;
        $src_file= $file_list[$i];
        $tag_key= $src_file;
        $seen_tag_keys[$tag_key]=true;

        $md5="";
        $source=null;
        $source_signature=null;
        $source_mtime=null;
        $cached_item=isset($tags_map[$tag_key]) && is_array($tags_map[$tag_key]);
        $need_deal_flag=$rebuild_all_flag || !$cached_item;

        unset($result);
        try {
            $source_signature=source_file_signature($src_file);
            $source_mtime=$source_signature['mtime'];
            $cached_signature=$cached_item && isset($tags_map[$tag_key]['source_signature'])
                ? $tags_map[$tag_key]['source_signature'] : null;
            list($source, $md5)=read_source_snapshot($src_file);
            if (!$need_deal_flag) {
                $cached_md5=isset($tags_map[$tag_key]["md5"])
                    ? $tags_map[$tag_key]["md5"] : "";
                if ($cached_md5 !== $md5) {
                    $need_deal_flag=true;
                } elseif ($cached_signature !== $source_signature) {
                    $tags_map[$tag_key]["gen_time"]=$source_mtime;
                    $tags_map[$tag_key]['source_signature']=$source_signature;
                }
            }

            if ($need_deal_flag) {
                $percent=$start_pecent + (int) floor(($i * $max_percent) / max(1, $deal_all_count));
                if ($percent !== $last_percent) {
                    printf("%02d%% %s\n", $percent, $src_file);
                    $last_percent=$percent;
                }
                $ctags->cleanFiles();
                $result = $ctags->process_single_file($src_file, $source);
                if ($result !== false) {
                    $tags_map[$tag_key] =[
                        "find_time" => $find_time ,
                        "gen_time" => $source_mtime,
                        "source_signature" => $source_signature,
                        "md5" => $md5,
                        "result" =>$result,
                    ];
                }
            } else {
                $result=isset($tags_map[$tag_key]["result"])
                    ? $tags_map[$tag_key]["result"] : null;
            }
        } catch (\Exception $e) {
            echo "PHPParser: {$e->getMessage()} - {$src_file}".PHP_EOL;
            $result=$cached_item && isset($tags_map[$tag_key]["result"])
                ? $tags_map[$tag_key]["result"] : null;
        }

        if ($result) {
            deal_tags($file_index, $result, $class_inherit_map, $class_map, $function_list, $construct_map, $class_define_map);
        }
    }

    construct_map_to_function_list($class_map, $construct_map, $class_inherit_map, $function_list, $class_define_map);

    // Clean data for files that are no longer part of the project.
    foreach (array_keys($tags_map) as $key) {
        if (!isset($seen_tag_keys[$key])) {
            unset($tags_map[$key]);
        }
    }


    if (! $cache_flag) {
        atomic_write_file_if_changed($tags_file, encode_json($tags_map, JSON_PRETTY_PRINT));
    }

    $json_flag= JSON_PRETTY_PRINT ;
    if ($test_flag) {
        //$json_flag= null;
        // if ($cache_flag) {
        //     $out_file_name=$cache_file_name;
        // } else {
        // }
        $out_file_name= "$obj_dir/tags.json";

        atomic_write_file_if_changed($out_file_name, encode_json([
            $class_map, $function_list, $class_inherit_map  , $file_list
        ], $json_flag));
    }
    if ($cache_flag) {
        save_as_el("$obj_dir/tags-vendor.el", $class_map, $function_list, $class_inherit_map, $file_list);
        atomic_write_file_if_changed($cache_file_name, encode_json([
            "gen_time" => time(),
            "vendor_file_count" => count($file_list) ,
        ], $json_flag));
    } else {
        save_as_el("$obj_dir/tags.el", $class_map, $function_list, $class_inherit_map, $file_list);
    }
}

function deal_config($config_file, $rebuild_all_flag, $realpath_flag, $need_tags_dir, $test_flag)
{
    //echo " rebuild_all_flag :$rebuild_all_flag  \n";
    $work_dir = dirname($config_file);
    $config   = get_config($config_file);
    chdir($work_dir);

    $tag_dir = @$config["tag-dir"];

    $cur_work_dir=$work_dir;
    if ($tag_dir) { // find
        $obj_dir=  get_path($cur_work_dir, $tag_dir);
    } else { // default
        $tag_dir=$need_tags_dir;
        if (strtoupper(substr(PHP_OS, 0, 3))==='WIN') {
            $work_dir = "/". preg_replace("/[\:\\ \t]/", "", $work_dir);
        }
        $obj_dir= $tag_dir."/tags". preg_replace("/[\/\\ \t]/", "-", $work_dir);
    }

    @mkdir($tag_dir, 0777, true);
    @mkdir($obj_dir, 0777, true);

    $filter                       = $config["filter"] ;
    //$can_use_external_dir         = @$filter["can-use-external-dir"];
    $php_path_list                = $filter ["php-path-list"];
    $php_file_ext_list            = $filter ["php-file-ext-list"];
    $php_path_list_without_subdir = isset($filter ["php-path-list-without-subdir"])? $filter ["php-path-list-without-subdir"]:[];
    $ignore_ruleset_list = isset($filter ["ignore-ruleset"])?$filter ["ignore-ruleset"]:[
        "/vendor/**/[tT]ests/**/*.php"
    ];

    $ignore_ruleset=Ruleset::loadFromStrings($ignore_ruleset_list);
    $cur_work_dir_len=strlen($cur_work_dir);
    $ignore_ruleset->check_start_pos=$cur_work_dir_len+1;

    print_r($ignore_ruleset);
    //echo "realpath_flag :$realpath_flag \n";


    $cache_file_name= "$obj_dir/tags-cache-v3.json" ;

    $start_pecent=0;
    $max_percent=100;
    $composer_lock_file="$cur_work_dir/composer.lock";
    $cache_flag=false;
    if (!file_exists($cache_file_name) || $rebuild_all_flag) {
        $cache_flag=true;
    }
    if (is_readable($composer_lock_file) && is_readable($cache_file_name)) {
        if (filemtime($composer_lock_file) > filemtime($cache_file_name)) {
            //echo "check_time:". filemtime($composer_lock_file) ." :". filemtime($cache_file_name) . "\n";
            //user update composer , need rebuild
            $cache_flag=true;
            $rebuild_all_flag=true;
        }
    }


    if ($cache_flag) {
        $max_percent=50;
        deal_file_tags($cache_flag, $cache_file_name, $test_flag, $rebuild_all_flag, $cur_work_dir, $obj_dir, $realpath_flag, $php_path_list, $php_path_list_without_subdir, $php_file_ext_list, $start_pecent, $max_percent, $ignore_ruleset);
        $start_pecent=50;
    }
    $cache_flag=false;
    deal_file_tags($cache_flag, $cache_file_name, $test_flag, $rebuild_all_flag, $cur_work_dir, $obj_dir, $realpath_flag, $php_path_list, $php_path_list_without_subdir, $php_file_ext_list, $start_pecent, $max_percent, $ignore_ruleset);
}
function save_as_el($file_name, $class_map, $function_list, $class_inherit_map, $file_list)
{
    return atomic_write_stream_if_changed(
        $file_name,
        function ($fp) use ($class_map, $function_list, $class_inherit_map, $file_list) {
            $buffer='';
            $append=function ($contents) use ($fp, &$buffer) {
                $buffer.=$contents;
                if (strlen($buffer) >= 1024 * 1024) {
                    write_all_to_stream($fp, $buffer);
                    $buffer='';
                }
            };

            $append("(setq  g-ac-php-tmp-tags  [\n(\n");

            foreach ($class_map as $class_name => $c_field_list) {
                $class_name_str=addslashes($class_name);
                $append("  (\"" . $class_name_str . "\".[\n");
                foreach ($c_field_list as $c_f_item) {
                    $doc=addslashes($c_f_item[2]??"");
                    $return_type_str=addslashes($c_f_item[4]??"");
                    $name=is_string($c_f_item[1]) ? addslashes($c_f_item[1]) : '';
                    $append(
                        "    [\"{$c_f_item[0]}\" \"$name\" \"{$doc}\"  \"{$c_f_item[3]}\"  \"$return_type_str\" \"$class_name_str\" \"{$c_f_item[6]}\" \"{$c_f_item[7]}\" ]\n"
                    );
                }
                $append("  ])\n");
            }
            $append(")\n[\n");

            foreach ($function_list as $f_item) {
                $doc=addslashes($f_item[2]??"");
                $function_name_str=addslashes($f_item[1]??"");
                $return_type_str=addslashes($f_item[4]??"");
                $append(
                    "  [\"{$f_item[0]}\" \"$function_name_str\" \"{$doc}\"  \"{$f_item[3]}\"  \"$return_type_str\"  ]\n"
                );
            }
            $append("]\n(\n");

            foreach ($class_inherit_map as $class_name => $i_list) {
                $class_name_str=addslashes($class_name);
                $line="  (\"" . $class_name_str . "\". [ ";
                foreach ($i_list as $i_item) {
                    $line .= "\"" . addslashes($i_item) . "\" ";
                }
                $append($line . "])\n");
            }
            $append(")\n[\n");

            foreach ($file_list as $f_file) {
                $append("  \"" . addslashes($f_file) . "\"\n");
            }
            $append("]\n])\n");
            if ($buffer !== '') {
                write_all_to_stream($fp, $buffer);
            }
        }
    );
}


function construct_map_to_function_list(&$class_map, &$construct_map, &$class_inherit_map, &$function_list, &$class_define_map)
{
    // construct_map => function_list
    $kind="f";
    $constructor_cache=[];
    foreach (array_keys($class_map) as $class_name) {
        $find_item=null;
        $parent_class=$class_name;
        $path=[];
        $seen=[];
        while ($parent_class && !isset($seen[$parent_class])) {
            if (array_key_exists($parent_class, $constructor_cache)) {
                $find_item=$constructor_cache[$parent_class];
                break;
            }
            if (!empty($construct_map[$parent_class])) {
                $find_item=$construct_map[$parent_class];
                $constructor_cache[$parent_class]=$find_item;
                break;
            }

            $seen[$parent_class]=true;
            $path[]=$parent_class;
            $next_parent=isset($class_inherit_map[$parent_class][0])
                ? $class_inherit_map[$parent_class][0] : null;
            if ($next_parent && $next_parent[0]!="\\") {
                $next_parent=preg_replace("/[A-Za-z0-9_]*\$/", "", $parent_class) . $next_parent;
            }
            $parent_class=$next_parent;
        }
        foreach ($path as $path_class) {
            $constructor_cache[$path_class]=$find_item;
        }

        if (!$find_item) { //没有找到,就用类定义
            $find_item= @$class_define_map[$class_name ];
        }
        if ($find_item) {
            $function_list[]= [ $kind, $class_name."(" ,  $find_item[0] , $find_item[1], $class_name ] ;
        }
    }
}

function get_filter_file_list($cache_flag, &$file_list, $dir, $file_ext_list, $reduce_flag, $ignore_ruleset)
{
    //vendor 里都是需要缓存的
    if (!$cache_flag) {  //
        if (preg_match("/\/vendor\//", $dir)) {
            return;
        }
    }


    $dir_list=scandir($dir);
    foreach ($dir_list as $file) {
        if ($file[0]!='.') {
            $sub_file=$dir.'/'.$file;
            if (is_dir($sub_file) && $reduce_flag) {
                if (!$cache_flag) {  //
                    if ($file=="vendor") {
                        continue;
                    }
                } else { //vendor 里 test ,tests 目录不处理
                    /**
                    if (in_array(strtolower($file), ["test", "tests" ]) !==false) {
                        continue;
                    }
                    */
                }

                /**  @var Ruleset  $ignore_ruleset */

                if (!$ignore_ruleset->match($sub_file."/")) {
                    get_filter_file_list($cache_flag, $file_list, $sub_file, $file_ext_list, $reduce_flag, $ignore_ruleset);
                } else {
                    echo "filter-ignore-dir:$sub_file\n";
                }
            } else {
                $file_path = pathinfo($file);
                if (in_array(@$file_path['extension'], $file_ext_list)) {
                    if ($cache_flag) {
                        if (preg_match("/\/vendor\//", $dir)) {
                            $file_list[]= "$dir/$file";
                        }
                    } else {
                        $file_list[]= "$dir/$file";
                    }
                }
            }
        }
    }
}

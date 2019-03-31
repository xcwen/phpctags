<?php
function get_config( $config_file ) {

    $json_data=trim(file_get_contents($config_file));
    if ($json_data=="") {
        $json_data=json_encode( [
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
                "php-path-list-without-subdir"=> []
            ]
        ]  ,JSON_PRETTY_PRINT);
        file_put_contents($config_file ,$json_data);
    }
    return json_decode($json_data,true);
}

function deal_tags($file_index, &$result ,&$class_inherit_map  ,&$class_map, &$function_list
                   , &$construct_map, &$class_define_map) {
    foreach ( $result as &$item ) {
        $kind=$item["kind"] ;
        $scope=$item["scope"];
        $name=$item["name"];
        //$file=$item["file"];
        $line=$item["line"];
        $file_pos=$file_index.":". $line;
        switch ( $kind ) {
        case "c" :
        case "i" :
        case "t" :
            $class_name=$scope."\\".$name;
            if (isset($item["inherits"])) {
                if ( isset($class_inherit_map[$class_name]) ) {
                    $arr=$item["inherits"];
                    foreach (  $class_inherit_map[$class_name] as $inherit ) {
                        $arr[]=  $inherit;
                    }
                    $class_inherit_map[$class_name] = $arr;

                }else{
                    $class_inherit_map[$class_name] = $item["inherits"];
                }
            }
            $return_type=$class_name;
            $function_list[ ]= [ $kind , $class_name , "" , $file_pos , $return_type ] ;
            if (!isset($class_map[$class_name]) ) {
                $class_map[$class_name] =[];
            }
            $class_define_map[ $class_name ] =[ "", $file_pos];

            break;

        case "T" :
            $class_name = $item["scope"];
            $class_inherit_map[$class_name][]=  $item["type"]  ;
            break;
        case "m" :
        case "p" :
            $class_name= $scope;
            $doc=@$item["args"];
            $return_type=@$item["type"];
            $access=$item["access"];

            $preg_str= "/\\\\$name\$/";
            if ( $name=="__construct" ||  preg_match($preg_str,$class_name,$matches) ) {
                $construct_map[ $class_name ] =[ $doc , $file_pos];
            }

            if ( $kind=="m"  ) {
                $name.="(";
            }
            $class_map[$class_name][] =[
                $kind , $name, $doc , $file_pos , $return_type, $class_name,$access  ];

            break;

        case "f" :
            $function_name=$scope."\\".$name;
            $doc=@$item["args"];
            $return_type=@$item["type"];
            $function_list[ ]= [ $kind , $function_name."(" , $doc , $file_pos , $return_type ] ;
            break;
        case "d" :

            $define_scope=$item["args"];
            $return_type=$item["type"];
            $access=$item["access"];
            $doc="";
            if ( $define_scope =="class")  {
                $class_name= $scope;
                $class_map[$class_name][] =[
                    $kind , $name, $doc , $file_pos , $return_type,$class_name  , $access ];
            }else{
                $define_name=$scope."\\".$name;
                $function_list[ ]= [ $kind , $define_name, $doc , $file_pos , $return_type ] ;
            }

            break;


        default:
            break;
        }

    }
}
function get_path( $cur_dir, $path  ) {
    $path=trim( $path );
    if ($path[0] =="/" ) {
        return $path;
    }
    //相对路径
    return  normalizePath( $cur_dir."/".$path  );
}
function normalizePath($path)
{
    $parts = array();// Array to build a new path from the good parts
    $path = str_replace('\\', '/', $path);// Replace backslashes with forwardslashes
    $path = preg_replace('/\/+/', '/', $path);// Combine multiple slashes into a single slash
    $segments = explode('/', $path);// Collect path segments
    $test = '';// Initialize testing variable
    foreach($segments as $segment)
    {
        if($segment != '.')
        {
            $test = array_pop($parts);
            if(is_null($test))
                $parts[] = $segment;
            else if($segment == '..')
            {
                if($test == '..')
                    $parts[] = $test;

                if($test == '..' || $test == '')
                    $parts[] = $segment;
            }
            else
            {
                $parts[] = $test;
                $parts[] = $segment;
            }
        }
    }
    return implode('/', $parts);
}

function deal_file_tags( $cache_flag , $cache_file_name , $test_flag, $rebuild_all_flag, $cur_work_dir, $obj_dir,   $realpath_flag, $php_path_list  , $php_path_list_without_subdir ,$php_file_ext_list, $start_pecent, $max_percent  ) {
    //得到要处理的文件
    $file_list=[];


    $ctags = new PHPCtags([]);
    $i=0;

    $class_map= [];
    $function_list= [];
    $class_inherit_map= [];



    if (!$test_flag   )  {
        if ($cache_flag ) {
            $common_json_file=__DIR__. "/common.json";
        }else{
            $common_json_file=  $cache_file_name;
        }
        $json_data= json_decode(file_get_contents($common_json_file  ),true );
        $class_map= $json_data[0];//类信息
        $function_list= $json_data[1];//函数,常量
        $class_inherit_map= $json_data[2];//继承
        $file_list= $json_data[3];//原先的文件列表
    }
    $cache_file_count=count($file_list);

    foreach ( $php_path_list as $dir ) {
        if ( $realpath_flag ) {
            $dir=   realpath($dir);
        }else{
            $dir=  get_path ($cur_work_dir, $dir);
        }
        get_filter_file_list( $cache_flag,  $file_list,  $dir,$php_file_ext_list, true);
    }

    foreach (  $php_path_list_without_subdir as $dir ) {
        if ( $realpath_flag ) {
            $dir=   realpath($dir);
        }else{
            $dir=  get_path ($cur_work_dir, $dir);
        }
        get_filter_file_list( $cache_flag, $file_list,  $dir,$php_file_ext_list, false);
    }

    $deal_all_count=count($file_list)-$cache_file_count;


    if ( $cache_flag ) {
        $tags_data='{}';
    }else{
        $tags_file="$obj_dir/tag-file-map.json";
        $tags_data=@file_get_contents( $tags_file );
        if (!$tags_data ) {
            $tags_data='{}';
        }
    }


    $tags_map = json_decode( $tags_data ,true);
    $find_time=time();
    $last_pecent=-1;
    $construct_map=[];
    $class_define_map=[];

    $result=null;
    for ( $i= 0; $i< $deal_all_count; $i++ ) {
        $file_index=$cache_file_count+$i ;
        $src_file= $file_list[$file_index ];
        $tag_key= $src_file;

        $need_deal_flag= $rebuild_all_flag || @$tags_map[$tag_key]["gen_time"] < filemtime($src_file);
        unset($result);
        if ($need_deal_flag) {
            $pecent =($i/$deal_all_count)*$max_percent;
            if ($pecent != $last_pecent) {
                printf("%02d%% %s\n", $start_pecent+$pecent , $src_file );
                $last_pecent = $pecent;
            }
            $ctags->cleanFiles();

            try {
                $result = $ctags->process_single_file($src_file);
                if ($result !== false ) {
                    $tags_map[$tag_key] =[
                        "find_time" => $find_time ,
                        "gen_time" => time(),
                        "result" =>$result,
                    ];
                }
            } catch(\Exception $e) {
                echo "PHPParser: {$e->getMessage()} - {$src_file}".PHP_EOL;
                $tags_map[$tag_key]["find_time"] = $find_time;
                $result= &$tags_map[$tag_key]["result"] ;

            }

        }else{
            $tags_map[$tag_key]["find_time"] = $find_time;
            $result= &$tags_map[$tag_key]["result"] ;
        }

        if ($result) {
            deal_tags($file_index, $result ,$class_inherit_map  ,$class_map, $function_list ,$construct_map ,$class_define_map );
        }
    }

    construct_map_to_function_list( $class_map , $construct_map, $class_inherit_map, $function_list , $class_define_map  );

    //clean old file-data
    foreach ( $tags_map as $key  =>&$item  ) {
        if ($item["find_time"] != $find_time) {
            unset( $tags_map[$key] );
        }
    }


    if (! $cache_flag ) {
        file_put_contents( $tags_file  ,json_encode($tags_map , JSON_PRETTY_PRINT ));
    }

    if ($test_flag || $cache_flag ) {
        $json_flag= JSON_PRETTY_PRINT ;
        //$json_flag= null;
        if ($cache_flag) {
            $out_file_name=$cache_file_name;
        }else{
            $out_file_name= "$obj_dir/tags.json";
        }

        file_put_contents(  $out_file_name ,json_encode( [
            $class_map, $function_list, $class_inherit_map  , $file_list
        ], $json_flag  ));
    }
    if (!$cache_flag) {
        save_as_el( "$obj_dir/tags.el", $class_map, $function_list, $class_inherit_map, $file_list);
    }
}

function deal_config( $config_file , $rebuild_all_flag, $realpath_flag, $need_tags_dir , $test_flag ) {
    //echo " rebuild_all_flag :$rebuild_all_flag  \n";
    $work_dir = dirname($config_file);
    $config   = get_config( $config_file);
    chdir($work_dir);

    $tag_dir = @$config["tag-dir"];

    $cur_work_dir=$work_dir;
    if ($tag_dir ) { // find
        $obj_dir=  get_path ($cur_work_dir, $tag_dir);
    }else{ // default
        $tag_dir=$need_tags_dir;
        if (strtoupper(substr(PHP_OS, 0, 3))==='WIN') {
            $work_dir = "/". preg_replace("/[\:\\ \t]/", "", $work_dir);
        }
        $obj_dir= $tag_dir."/tags". preg_replace("/[\/\\ \t]/", "-", $work_dir );
    }

    @mkdir($tag_dir, 0777, true );
    @mkdir($obj_dir, 0777, true );

    $filter                       = $config["filter"] ;
    $can_use_external_dir         = @$filter["can-use-external-dir"];
    $php_path_list                = $filter ["php-path-list"];
    $php_file_ext_list            = $filter ["php-file-ext-list"];
    $php_path_list_without_subdir = $filter ["php-path-list-without-subdir"];
    //echo "realpath_flag :$realpath_flag \n";


    $cache_file_name= "$obj_dir/tags-cache-v2.json" ;

    $start_pecent=0;
    $max_percent=100;
    if ( !file_exists( $cache_file_name )  || $rebuild_all_flag )  {
        $cache_flag=true;
        $max_percent=50;
        deal_file_tags( $cache_flag ,  $cache_file_name, $test_flag,$rebuild_all_flag, $cur_work_dir, $obj_dir,   $realpath_flag, $php_path_list  , $php_path_list_without_subdir  ,$php_file_ext_list, $start_pecent, $max_percent );
        $start_pecent=50;
    }
    $cache_flag=false;
    deal_file_tags( $cache_flag ,  $cache_file_name, $test_flag,$rebuild_all_flag, $cur_work_dir, $obj_dir,   $realpath_flag, $php_path_list  , $php_path_list_without_subdir  ,$php_file_ext_list ,$start_pecent, $max_percent);

}
function save_as_el( $file_name,  $class_map, $function_list, $class_inherit_map  , $file_list ) {
    $fp=fopen($file_name, "w");
    $str= "(setq  g-ac-php-tmp-tags  [\n(\n" ;
    //class_map
    foreach( $class_map as $class_name=> &$c_field_list ) {
        $class_name_str= addslashes($class_name);
        $str.=  "  (\"". $class_name_str  ."\".[\n"   ;
        foreach ($c_field_list as &$c_f_item ) {
            //print_r( $c_f_item );
            $doc=addslashes( $c_f_item[2]);
            $return_type_str=addslashes( $c_f_item[4]);
            $name=addslashes( $c_f_item[1]);
            $str.="    [\"{$c_f_item[0]}\" \"$name\" \"{$doc}\"  \"{$c_f_item[3]}\"  \"$return_type_str\" \"$class_name_str\" \"{$c_f_item[6]}\"  ]\n";
        }
        $str.=  "  ])\n"  ;
    }
    $str.=  ")\n"  ;
    fwrite($fp ,  $str );
    $str="[\n";

    //[ $kind , $define_name, $doc , $file_pos , $return_type ] ;
    foreach( $function_list as  &$f_item) {
        $doc=addslashes( $f_item[2]);
        $function_name_str=addslashes( $f_item[1]);
        $return_type_str=addslashes( $f_item[4]);
        $str.="  [\"{$f_item[0]}\" \"$function_name_str\" \"{$doc}\"  \"{$f_item[3]}\"  \"$return_type_str\"  ]\n";
    }
    $str.="]\n";
    fwrite($fp ,  $str );

    $str="(\n";
    foreach( $class_inherit_map as $class_name=> &$i_list ) {
        $class_name_str= addslashes($class_name);
        $str.=  "  (\"". $class_name_str  ."\". [ "   ;
        foreach ($i_list as &$i_item ) {
            $str .= "\"". addslashes($i_item) ."\" " ;
        }
        $str.=   "])\n"  ;
    }
    $str.=")\n";
    fwrite($fp ,  $str );



    $str="[\n";
    foreach( $file_list as  &$f_file) {
        $str.="  \"". addslashes($f_file )  ."\"\n" ;
    }
    $str.="]\n";
    fwrite($fp ,  $str );
    fwrite($fp ,  "])\n" );
    fclose($fp );
}


function construct_map_to_function_list( &$class_map , &$construct_map, &$class_inherit_map, &$function_list ,&$class_define_map ) {
    // construct_map => function_list
    $kind="f";
    foreach ($class_map as $class_name => &$_v ) {
        $cur_map=[];
        $find_item=null;
        $parent_class=$class_name;
        $tmp_parent_class="";
        do {

            $find_item=@$construct_map[$parent_class];
            if ($find_item) {
                break;
            }
            $cur_map[$parent_class ]=true;
            $tmp_parent_class=$parent_class;
            $parent_class=@$class_inherit_map[$parent_class][0];
            if ($parent_class) {
                if ($parent_class[0]!="\\" ) { //cur namespace
                    $parent_class=preg_replace("/[A-Za-z0-8_]*\$/","", $tmp_parent_class ). "$parent_class";
                }
            }
        } while($parent_class && !isset ($cur_map[$parent_class]) );
        if (!$find_item) { //没有找到,就用类定义
            $find_item= @$class_define_map[$class_name ];
        }
        if ($find_item) {
            $function_list[]= [ $kind, $class_name."(" ,  $find_item[0] , $find_item[1], $class_name ] ;
        }

    }

}

function get_filter_file_list( $cache_flag, &$file_list, $dir ,$file_ext_list , $reduce_flag   )
{
    //vendor 里都是需要缓存的
    if (!$cache_flag) {  //
        if (preg_match("/\/vendor\//", $dir  )) {
            return;
        }
    }


    $dir_list=scandir($dir);
    foreach($dir_list as $file){
        if($file[0]!='.'){
            if(  is_dir($dir.'/'.$file) && $reduce_flag){

                if (!$cache_flag) {  //
                    if ($file=="vendor") {
                        continue;
                    }
                }else { //vendor 里 test ,tests 目录不处理
                    if (in_array( strtolower($file), ["test", "tests" ] ) !==false  )  {
                        continue;
                    }
                }

                get_filter_file_list( $cache_flag, $file_list, $dir."/$file" ,$file_ext_list , $reduce_flag   );
            }else{
                $file_path = pathinfo( $file);
                if (in_array(@$file_path['extension'], $file_ext_list) ) {
                    if ($cache_flag ) {
                        if (preg_match("/\/vendor\//", $dir  )) {
                            $file_list[]= "$dir/$file";
                        }
                    }else {
                        $file_list[]= "$dir/$file";
                    }
                }
            }
        }
    }
}

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
                   , &$construct_map) {
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
            $doc=$class_name;
            $return_type=$class_name;
            $function_list[ ]= [ $kind , $class_name , $doc , $file_pos , $return_type ] ;
            if (!isset($class_map[$class_name]) ) {
                $class_map[$class_name] =[];
            }


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
            }else{
                $class_map[$class_name][] =[
                    $kind , $name, $doc , $file_pos , $return_type, $class_name,$access  ];
            }

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
            $doc="";
            if ( $define_scope =="class")  {
                $class_name= $scope;
                $class_map[$class_name][] =[
                    $kind , $name, $doc , $file_pos , $return_type ];
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
        $obj_dir= $tag_dir."/tags". preg_replace("/[\/\\ \t]/", "-", $work_dir );
    }

    @mkdir($tag_dir );

    $filter                       = $config["filter"] ;
    $can_use_external_dir         = @$filter["can-use-external-dir"];
    $php_path_list                = $filter ["php-path-list"];
    $php_file_ext_list            = $filter ["php-file-ext-list"];
    $php_path_list_without_subdir = $filter ["php-path-list-without-subdir"];
    //echo "realpath_flag :$realpath_flag \n";

    //得到要处理的文件
    $file_list=[];
    foreach ( $php_path_list as $dir ) {
        if ( $realpath_flag ) {
            $dir=   realpath($dir);
        }else{
            $dir=  get_path ($cur_work_dir, $dir);
        }
        get_filter_file_list( $file_list,  $dir,$php_file_ext_list, true);
    }

    foreach (  $php_path_list_without_subdir as $dir ) {
        if ( $realpath_flag ) {
            $dir=   realpath($dir);
        }else{
            $dir=  get_path ($cur_work_dir, $dir);
        }
        get_filter_file_list( $file_list,  $dir,$php_file_ext_list, false);
    }
    $deal_file_list=[];

    @mkdir($obj_dir);

    $ctags = new PHPCtags([]);
    $i=0;
    $all_count=count($file_list);

    $class_map= [];
    $function_list= [];
    $class_inherit_map= [];

    if (!$test_flag)  {
        $common_json_file=__DIR__. "/common.json";
        $json_data= json_decode(file_get_contents($common_json_file  ),true );
        $class_map= $json_data[0];//类信息
        $function_list= $json_data[1];//函数,常量
        $class_inherit_map= $json_data[2];//继承
    }

    $tags_file="$obj_dir/tag-file-map.json";
    $tags_data=@file_get_contents( $tags_file );
    if (!$tags_data ) {
        $tags_data='{}';
    }


    $tags_map = json_decode( $tags_data ,true);
    $find_time=time(NULL);
    $last_pecent=-1;
    $construct_map=[];

    $result=null;
    foreach ($file_list as $file_index=> $src_file) {
        $tag_key= $src_file;

        //echo $src_file ."->". $obj_file. "\n";
        $need_deal_flag= $rebuild_all_flag || @$tags_map[$tag_key]["gen_time"] < filemtime($src_file);
        unset($result);
        if ($need_deal_flag) {
            $pecent =($i/$all_count)*100;
            if ($pecent != $last_pecent) {
                printf("%02d%% %s\n",$pecent , $src_file );
                $last_pecent = $pecent;
            }
            $ctags->cleanFiles();

            try {
                $result = $ctags->process_single_file($src_file);
                if ($result !== false ) {
                    $tags_map[$tag_key] =[
                        "find_time" => $find_time ,
                        "gen_time" => time(NULL),
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
            deal_tags($file_index, $result ,$class_inherit_map  ,$class_map, $function_list ,$construct_map );
        }
        $i++;
    }

    construct_map_to_function_list( $class_map , $construct_map, $class_inherit_map, $function_list  );

    //clean old file-data
    foreach ( $tags_map as $key  =>&$item  ) {
        if ($item["find_time"] != $find_time) {
            unset( $tags_map[$key] );
        }
    }


    file_put_contents( $tags_file  ,json_encode($tags_map , JSON_PRETTY_PRINT ));

    $json_flag= JSON_PRETTY_PRINT ;
    //$json_flag= null;
    file_put_contents( "$obj_dir/tags.json" ,json_encode( [
        $class_map, $function_list, $class_inherit_map  , $file_list
    ], $json_flag  ));

}

function construct_map_to_function_list( &$class_map , &$construct_map, &$class_inherit_map, &$function_list  ) {
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
        if ($find_item) {
            $function_list[ ]= [ $kind, $class_name."(" ,  $find_item[0] , $find_item[1], $class_name ] ;
        }
    }

}

function get_filter_file_list( &$file_list, $dir ,$file_ext_list , $reduce_flag   )
{

    $dir_list=scandir($dir);
    foreach($dir_list as $file){
        if($file[0]!='.'){
            if(  is_dir($dir.'/'.$file) && $reduce_flag){
                get_filter_file_list( $file_list, $dir."/$file" ,$file_ext_list , $reduce_flag   );
            }else{
                $file_path = pathinfo( $file);
                if (in_array(@$file_path['extension'], $file_ext_list) ) {
                    $file_list[]= "$dir/$file";
                }
            }
        }
    }
}
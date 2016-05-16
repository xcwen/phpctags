<?php
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



function get_config( $project_root_dir ) {
    
    $config_file=$project_root_dir ."/.ac-php-conf.json";
    $config_str=trim(@file_get_contents($config_file));
    if ($config_str=="")  {
        $config=[
            "use-cscope"=> true,
            "filter"=> [
                "php-file-ext-list"=> [
                    "php"
                ],
                "php-path-list"=> [
                    "."
                ],
                "php-path-list-without-subdir"=> []
                ]
        ];
        file_put_contents($config_file , json_encode ( $config, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT  ) );
    }else{
        $config=json_decode($config_str,true ) ;
    }


    return $config;
}

$g_file_list=[];
$g_vendor_file_list=[];
function   filter_files( $basedir, $next_dir, $ext_list, $check_sub_dir_flag ) {

    global $g_file_list,$g_vendor_file_list;
    $dir="$basedir$next_dir";
    //check vendor.*tests
    if (preg_match("/\\/vendor\\/.*\\/tests\\//", $dir )  )   {
        return ;
    }
            

    if(is_dir($dir) ) {
        if ($dh = opendir($dir)) {
            while (($file = readdir($dh)) !== false) {
                if ( $file!="." && $file!=".." ) {
                    if((is_dir($dir."/".$file)) ) {
                        if ($check_sub_dir_flag ) {
                            filter_files( $basedir,$next_dir.$file."/",  $ext_list,$check_sub_dir_flag);
                        }
                    } else {
                        $file_info=pathinfo( $file );
                        $file_ext=strtolower( @$file_info["extension"]);

                        if (in_array($file_ext ,$ext_list )) {
                            
                            $filename=strtolower( $file_info["filename"]); // ""
                            $file_all_name="$dir$file";

                            $tags_file_name= preg_replace("/[ \\/]/", "-" ,
                                                          $next_dir.  $filename .".json") ;
                            
                            if (preg_match("/\\/vendor\\//", $dir )  )   {
                                $g_vendor_file_list[]=
                                    [ $file_all_name, $tags_file_name,filemtime($file_all_name ) ];
                            }else{
                                $g_file_list[]= [ $file_all_name, $tags_file_name,filemtime($file_all_name ) ];
                            }
    
                        }
                    }
                }
            }
            closedir($dh);
        }
    }
}

function filter_php_files( $project_root_dir, $config )  {
    $filter=$config["filter"]  ;
    $ext_list=$filter["php-file-ext-list"];
    $path_list=$filter["php-path-list"];
    $path_list_without_subdir=$filter["php-path-list-without-subdir"];

    foreach($path_list as $dir ) {
        
        filter_files($dir."/","", $ext_list,true );
    }

    foreach($path_list_without_subdir as $dir ) {
        filter_files($dir."/","" , $ext_list,false);
    }
}

function gen_tags(  $options,$tags_output_dir,$file_list, $get_last_files_flag ) {
    
    $deal_list=[];
    foreach($file_list as $item) {
        
        $obj_file= $tags_output_dir. $item[1];
        //if ( )
        $src_mtime=$item[2];
        if (  $do_all_flag || $src_mtime>=@filemtime($obj_file) ){
            $deal_list[]=$item;
        }
    }


    $ctags = new PHPCtags($options);
    $i=0;
    $all_count=count($deal_list);
    foreach($deal_list as $item) {
        
        $src_file=$item[0];
        $obj_file= $tags_output_dir. $item[1];
        
        printf("%02d%% %s\n",($i/$all_count)*100, $src_file );
        $ctags->cleanFiles();
        $ctags->addFiles([$src_file ]);
        $result = $ctags->export();
        if ($result !== false ) {
            file_put_contents($obj_file,$result);
        }
        $i++;
    }
    


    $last_files=[];
    if ($get_last_files_flag) {
        
        usort($file_list ,function($a,$b ){
            if  ($a[2]==$b[2] ) {
                return 0;
            }
            return $a[2]<$b[2]?1:-1;
        });
    
        $last_files_count=count($file_list)>20?20:count($file_list);

        for($i=0;$i<$last_files_count;$i++ ) {
            $last_files[$file_list[$i][0]]=true;
        }

    }

    return [ $deal_list, $last_files];
}

function deal_file( $options,$project_root_dir, $save_tags_dir , $do_all_flag ){
    
    global $g_file_list,$g_vendor_file_list;
    $config=get_config($project_root_dir);
    $tags_output_dir="$save_tags_dir/tags/";

    if (!file_exists( $tags_output_dir) ) {
        mkdir($tags_output_dir,0755, true);
    }
    

    filter_php_files( $project_root_dir,$config);
    
    list ($deal_list,$last_files )=gen_tags($options,$tags_output_dir,$g_file_list,true);
    list ($vendor_deal_list,$vendor_last_files )=gen_tags($options,$tags_output_dir,$g_vendor_file_list,false);
     
    echo count( $g_file_list)."\n";
    echo count( $g_vendor_file_list)."\n";
    //print_r($g_file_list );
    //处理vendor 
    
    //得到最近20个文件
    $last_files_config_name=$save_tags_dir ."/last_files.json" ;

    $old_last_files= json_decode(@file_get_contents($last_files_config_name),true );
    //check do cache1, or  cache2 

    file_put_contents($last_files_config_name , json_encode ( $last_files, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT  ) );
    

    
    


    
    



}


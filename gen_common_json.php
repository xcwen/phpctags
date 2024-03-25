#!/usr/bin/php
<?php

# 生成  ~/ac-php/ac-php-comm-tags-data.el 文件步骤:
#  cp   phpstorm-stubs/* ~/ac-php/commom_php
# 1:
/*
php ./bootstrap.php --config-file=/home/jim/ac-php/commom_php/.ac-php-conf.json --tags_dir=/home/jim/.ac-php  --rebuild=yes   --realpath_flag=yes --test=yes
*/
# 2: 运行本文件  ./gen_common_json.php

# 3 : 复制
/*
cp /home/jim/.ac-php/tags-home-jim-ac-php-commom_php/tags.el  ~/ac-php/ac-php-comm-tags-data.el
*/

$json_data= json_decode(file_get_contents("/home/jim/.ac-php/tags-home-jim-ac-php-commom_php/tags.json"), true);
$json_data[3]=[] ;
//class
foreach ($json_data[0] as &$item_list) {
    foreach ($item_list as &$item) {
        $item[3]="sys";
    }
    unset($item);
}
unset($item_list);


//function
foreach ($json_data[1] as &$item) {
    $item[3]="sys";
}

unset($item);
//$function_list[ ]= [ $kind , $function_name."(" , $doc , $file_pos , $return_type ] ;
$sys_def_fun=[
    ["f", "\\empty(", "mixed \$var", "sys", "bool"  ],
    ["f", "\\isset(", "mixed \$var ,mixed \$__args__=NULL", "sys", "bool"  ],
    ["f", "\\unset(", "mixed \$var ,mixed \$__args__=NULL", "sys", "void"  ],
    ["f", "\\require(", "string \$file_name ", "sys", "mixed"  ],
    ["f", "\\require_once(", "string \$file_name ", "sys", "mixed"  ],
    ["f", "\\include(", "string \$file_name ", "sys", "mixed"  ],
    ["f", "\\include_once(", "string \$file_name ", "sys", "mixed"  ],
];
foreach ($sys_def_fun as $s_item) {
    $json_data[1][]= $s_item;
}


#file_put_contents("./common.json", json_encode($json_data));
#file_put_contents("/home/jim/ac-php/ac-php-comm-tags-data.json" ,json_encode($json_data  ));

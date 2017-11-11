#!/usr/bin/php
<?php
$json_data= json_decode( file_get_contents( "/home/jim/.ac-php/tags-home-jim-ac-php-commom_php/tags.json"),true );
$json_data[3]=[] ;
//class
foreach($json_data[0] as &$item_list )  {
    foreach ($item_list as &$item  )  {
        $item[3]="sys";
    }
    unset( $item );
}
unset( $item_list );


//function
foreach($json_data[1] as &$item)  {
        $item[3]="sys";
}
unset( $item );

file_put_contents("./common.json" ,json_encode($json_data  ));
file_put_contents("/home/jim/ac-php/ac-php-comm-tags-data.json" ,json_encode($json_data  ));

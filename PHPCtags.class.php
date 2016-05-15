<?php
class PHPCtags
{
    const VERSION = '0.6.0';

    private   $mFile;

    private $mFiles;
    private $mFileLines ;

    private static $mKinds = array(
        't' => 'trait',
        'c' => 'class',
        'm' => 'method',
        'f' => 'function',
        'p' => 'property',
        'd' => 'constant',
        'v' => 'variable',
        'i' => 'interface',
        'n' => 'namespace',
        'T' => 'usetrait',
    );

    private $mParser;
    private $mLines;
    private $mOptions;
    private $mUseConfig=array();
    private $tagdata;
    private $cachefile;
    private $filecount;

    public function __construct($options)
    {
        $this->mParser = new PHPParser_Parser(new  PHPParser_Lexer());
        $this->mLines = array();
        $this->mOptions = $options;
        $this->filecount = 0;
    }

    public function setMFile($file)
    {
        if (empty($file)) {
            throw new PHPCtagsException('No File specified.');
        }

        if (!file_exists($file)) {
            throw new PHPCtagsException('Warning: cannot open source file "' . $file . '" : No such file');
        }

        if (!is_readable($file)) {
            throw new PHPCtagsException('Warning: cannot open source file "' . $file . '" : File is not readable');
        }

        //$this->mFile = realpath($file);
        $this->mFile=$file;
        $this->mFileLines = $this->mFiles[$this->mFile]  ;
    }

    public static function getMKinds()
    {
        return self::$mKinds;
    }
    public function cleanFiles(){
        $this->mFiles=array();
        $this->mLines=array();
    }
    public function addFile($file)
    {
        //$f=realpath($file);
        $f=$file;
        $this->mFiles[$f] = file($f ) ;
    }

    public function setCacheFile($file) {
        $this->cachefile = $file;
    }

    public function addFiles($files)
    {
        foreach ($files as $file) {
            $this->addFile($file);
        }
    }

    private function getNodeAccess($node)
    {
        if ($node->isPrivate()) return 'private';
        if ($node->isProtected()) return 'protected';
        return 'public';
    }

    /**
     * stringSortByLine
     *
     * Sort a string based on its line delimiter
     *
     * @author Techlive Zheng
     *
     * @access public
     * @static
     *
     * @param string  $str     string to be sorted
     * @param boolean $foldcse case-insensitive sorting
     *
     * @return string sorted string
     **/
    public static function stringSortByLine($str, $foldcase=FALSE)
    {
        $arr = explode("\n", $str);
        if (!$foldcase)
            sort($arr, SORT_STRING);
        else
            sort($arr, SORT_STRING | SORT_FLAG_CASE);
        $str = implode("\n", $arr);
        return $str;
    }

    private static function helperSortByLine($a, $b)
    {
        return $a['line'] > $b['line'] ? 1 : 0;
    }

    private function getRealClassName_ex($className , $scope =array() ){

        if ( $className=="\$this" ||  $className == "static"   ) {
            $c_scope = array_pop($scope);
            list($c_type, $c_name) = each($c_scope);
            $n_scope = array_pop($scope);
            if(!empty($n_scope)) {
                list($n_type, $n_name) = each($n_scope);
                $s_str =  $n_name . '\\' . $c_name ;
            } else {
                $s_str = $c_name;

            }
            return $s_str;
        }

        if (  $className[0] != "\\"  ){
            $ret_arr=explode("\\", $className , 2  );
            if (count($ret_arr)==2){

                $pack_name=$ret_arr[0];
                if (isset($this->mUseConfig[ $pack_name])){
                    return  $this->mUseConfig[$pack_name]."\\".$ret_arr[1] ;
                }else{
                    return $className;
                }
            }else{
                if (isset($this->mUseConfig[$className])){
                    return  $this->mUseConfig[$className];
                }else{
                    return $className;
                }
            }
    
        }else{
            return $className;
        }

    }

    /**
     *@return  array
     */
    private function getRealClassName($className , $scope =array() ){
        
        $classname_list=explode("/",$className);
        
        $ret_list=[];
        foreach ( $classname_list as  $item) {
            $ret_list[]= $this->getRealClassName_ex($item, $scope) ;
        }
        return $ret_list;
    }

    private function  func_get_return_type($node,$scope) {
        $return_type="". $node->returnType;

        if (!$return_type )  {
            if ( preg_match( "/@return[ \t]+([\$a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type= $matches[1];
            }
        }
        if ($return_type) {
            $return_type=$this->getRealClassName($return_type,$scope);
        }
        return $return_type;
    }
    private function struct($node, $reset=FALSE, $parent=array())
    {
        static $scope = array();
        static $structs = array();

        if ($reset) {
            $structs = array();
        }



        
        $kind = $name = $line = $access = $extends = '';
        $return_type="";
        $implements = array();

        

        if (!empty($parent)) array_push($scope, $parent);

        if (is_array($node)) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_UseUse ) {
            $this->mUseConfig[$node->alias ]= $node->name->toString() ;


        } elseif ($node instanceof PhpParser\Node\Stmt\TraitUse ) {

            
            $type= implode("\\" , $node->traits[0]->parts) ;
            $kind = 'T';
            $name = str_replace("\\","_",$type) ;
            $line = $node->getLine();
            $return_type=$this->getRealClassName($type,$filed_scope);

            $access = "public" ;


        } elseif ($node instanceof PHPParser_Node_Stmt_Use ) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_Class or  $node instanceof PHPParser_Node_Stmt_Trait) {
            $kind = 'c';
            $name = $node->name;
            $extends = $node->extends;
            $implements = $node->implements;
            $line = $node->getLine();
            
            $filed_scope=$scope;
            array_push($filed_scope, array('class' => $name ) );

            $doc_item= $node->getDocComment() ;
            if ($doc_item) {
                
                $doc_start_line=$doc_item->getLine();
                $arr=explode("\n", ($doc_item->__toString()));
                foreach ( $arr as  $line_num  => $line_str ) {
                    if ( preg_match(
                        "/@property[ \t]+([a-zA-Z0-9_\\\\]+)[ \t]+\\$?([a-zA-Z0-9_]+)/",
                        $line_str, $matches) ){
                        $field_name=$matches[2];
                        $field_return_type= $this->getRealClassName( $matches[1],$filed_scope);
                        $structs[] = array(
                            'file' => $this->mFile,
                            'kind' => "p",
                            'name' => $field_name,
                            'extends' => null,
                            'implements' => null,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $filed_scope ,
                            'access' => "public",
                            'type' => $field_return_type,
                        );


                    }else if ( preg_match(
                        "/@method[ \t]+(.+)[ \t]+([\$a-zA-Z0-9_]+)/",
                        $line_str, $matches) ){
                        //* @method string imageUrl($width = 640, $height = 480, $category = null, $randomize = true)
                        $field_name=$matches[2];
                        $field_return_type= $this->getRealClassName( $matches[1],$filed_scope);
                        $structs[] = array(
                            'file' => $this->mFile,
                            'kind' => "m",
                            'name' => $field_name,
                            'extends' => null,
                            'implements' => null,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $filed_scope ,
                            'access' => "public",
                            'type' => $field_return_type,
                        );
                    }else if ( preg_match(
                        "/@use[ \t]+([a-zA-Z0-9_\\\\]+)/",
                        $line_str, $matches) ){
                        //* @use classtype 

                        $type= $matches[1];
                        $field_name = str_replace("\\","_",$type) ;
                        $field_return_type= $this->getRealClassName( $type,$filed_scope);

                        $structs[] = array(
                            'file' => $this->mFile,
                            'kind' => "T",
                            'name' => $field_name,
                            'extends' => null,
                            'implements' => null,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $filed_scope ,
                            'access' => "public",
                            'type' => $field_return_type,
                        );

                    }

                }
            }

            foreach ($node as $key=> $subNode) {
                if ($key=="stmts"){
                    foreach ($subNode as $tmpNode) {
                        $comments=$tmpNode->getAttribute("comments");
                        if (is_array($comments)){
                            foreach( $comments  as $comment ){
                                if ( preg_match(
                                    "/@var[ \t]+\\$([a-zA-Z0-9_]+)[ \t]+([a-zA-Z0-9_\\\\]+)/",
                                    $comment->getText(), $matches) ){

                                    $field_name=$matches[1];
                                    $field_return_type= $this->getRealClassName( $matches[2],$filed_scope);
                                    $structs[] = array(
                                        'file' => $this->mFile,
                                        'kind' => "p",
                                        'name' => $field_name,
                                        'extends' => null,
                                        'implements' => null,
                                        'line' => $comment->getLine() ,
                                        'scope' => $filed_scope ,
                                        'access' => "public",
                                        'type' => $field_return_type,
                                    );

                                }
                            }
                        }
                    }
                }
                $this->struct($subNode, FALSE, array('class' => $name));
            }
        } elseif ($node instanceof PHPParser_Node_Stmt_Property) {
            $kind = 'p';
            $prop = $node->props[0];
            $name = $prop->name;
            $line = $prop->getLine();
            if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1],$scope);
            }else{
                //for old return format 
                if ( preg_match( "/\\/\\*.*::([a-zA-Z0-9_\\\\|]+)/",
                                 $this->mFileLines[$line-1] ,
                                 $matches) ){
                    $return_type=$this->getRealClassName( $matches[1],$scope);
                }
            }

            $access = $this->getNodeAccess($node);

        } elseif ($node instanceof PHPParser_Node_Stmt_ClassConst) {
            $kind = 'd';
            $cons = $node->consts[0];
            $name = $cons->name;
            $line = $cons->getLine();
            $access = "public"; 
            $return_type="void";
            if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1],$scope);
            }

        } elseif ($node instanceof PHPParser_Node_Stmt_ClassMethod) {
            $kind = 'm';
            $name = $node->name;
            $line = $node->getLine();
            $access = $this->getNodeAccess($node);
            $return_type=$this->func_get_return_type($node, $scope);


            /*
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('method' => $name));
            }
            */
        } elseif ($node instanceof PHPParser_Node_Stmt_If) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser_Node_Expr_LogicalOr ) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }

        } elseif ($node instanceof PHPParser_Node_Stmt_Const) {
            $kind = 'd';
            $access = "public"; 
            $cons = $node->consts[0];
            $name = $cons->name;
            $line = $node->getLine();

            $return_type="void";
            if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1],$scope);
            }



        } elseif ($node instanceof PHPParser_Node_Stmt_Global) {



        } elseif ($node instanceof PHPParser_Node_Stmt_Static) {
            //@todo
        } elseif ($node instanceof PHPParser_Node_Stmt_Declare) {
            //@todo
        } elseif ($node instanceof PHPParser_Node_Stmt_TryCatch) {
            /*
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
            */
        } elseif ($node instanceof PHPParser_Node_Stmt_Function) {
            


            $kind = 'f';
            $name = $node->name;
            $line = $node->getLine();

            $return_type = $this->func_get_return_type($node);

        } elseif ($node instanceof PHPParser_Node_Stmt_Interface) {
            $kind = 'i';
            $name = $node->name;
            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('interface' => $name));
            }

        } elseif ($node instanceof PHPParser_Node_Stmt_Trait ) {


            /*
            $kind = 't';
            $name = $node->name;
            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('trait' => $name));
            }
            */
        } elseif ($node instanceof PHPParser_Node_Stmt_Namespace) {
            $kind = 'n';
            $name = $node->name;
            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('namespace' => $name));
            }
        } elseif ($node instanceof PHPParser_Node_Expr_Assign) {
            if (isset($node->var->name) && is_string($node->var->name)) {
                $kind = 'v';
                $node = $node->var;
                $name = $node->name;
                $line = $node->getLine();

                $return_type="void";
                if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                    $return_type=$this->getRealClassName( $matches[1],$scope);
                }

            }
        } elseif ($node instanceof PHPParser_Node_Expr_AssignRef) {
            if (isset($node->var->name) && is_string($node->var->name)) {
                $kind = 'v';
                $node = $node->var;
                $name = $node->name;
                $line = $node->getLine();
                $return_type="void";
                if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                    $return_type=$this->getRealClassName( $matches[1],$scope);
                }

            }

        } elseif ($node instanceof PHPParser_Node_Expr_FuncCall) {
            switch ($node->name) {
                case 'define':
                    $kind = 'd';
                    $access = "public"; 
                    $node = $node->args[0]->value;
                    $name = $node->value;
                    $line = $node->getLine();
                    $return_type="void";
                    if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                        $return_type=$this->getRealClassName( $matches[1], $scope);
                    }

                    break;
            }
        } else {
            // we don't care the rest of them.
        }

        if (!empty($kind) && !empty($name) && !empty($line)) {
            $structs[] = array(
                'file' => $this->mFile,
                'kind' => $kind,
                'name' => $name,
                'extends' => $extends,
                'implements' => $implements,
                'line' => $line,
                'scope' => $scope,
                'access' => $access,
                'type' => $return_type,
            );
        }

        if (!empty($parent)) array_pop($scope);

        // if no --sort is given, sort by occurrence
        if (!isset($this->mOptions['sort']) || $this->mOptions['sort'] == 'no') {
            usort($structs, 'self::helperSortByLine');
        }

        return $structs;
    }

    private function render($structure)
    {
        $str = '';
        $ret_arr=[];
        foreach ($structure as $struct) {
            $ret_item=[];
            $file = $struct['file'];

            if (!in_array($struct['kind'], $this->mOptions['kinds'])) {
                print_r( $this->mOptions['kinds']);
                continue;
            }

            if (!isset($files[$file]))
                $files[$file] = file($file);

            $lines = $files[$file];

            if (empty($struct['name']) || empty($struct['line']) || empty($struct['kind']))
                return;

            $kind= $struct['kind'];
            //$str .= '(';
            if  ($struct['name'] instanceof PHPParser_Node_Expr_Variable ){
                //$str .= '"'. addslashes( $struct['name']->name) . '" ' ;
                $ret_item[]=  $struct['name']->name;
            }else{
                //$str .= '"'. addslashes( $struct['name']) . '" ' ;
                $ret_item[]=  $struct['name'];
            }

            $ret_item[]=  $file.":".$struct['line'] ;
            //$str .= ' "'. addslashes($file.":".$struct['line']  )  . '" ' ;


            if ($this->mOptions['excmd'] == 'number') {
                // $str .= "\t" . $struct['line'];
            } else { //excmd == 'mixed' or 'pattern', default behavior
                #$str .= "\t" . "/^" . rtrim($lines[$struct['line'] - 1], "\n") . "$/";
                if ($kind=="f" || $kind=="m" ){
                    //$str .= ' "'. addslashes(rtrim($lines[$struct['line'] - 1], "\n")) . '" ' ;
                    
                    $ret_item[]= preg_replace(  "/[ \t]*,[ \t]*/", ", " ,trim(preg_replace(  "/.*\\((.*)\\).*/","\\1" , $lines[$struct['line'] - 1])));
                }else{
                    //$str .= ' nil ' ;
                    $ret_item[]= false;
                }
            }

            if ($this->mOptions['format'] == 1) {
                $str .= "\n";
                continue;
            }

            //$str .= ";\"";

            #field=k, kind of tag as single letter
            if (in_array('k', $this->mOptions['fields'])) {
                //in_array('z', $this->mOptions['fields']) && $str .= "kind:";
                //$str .= "\t" . $struct['kind'];

                //$str .= ' "'. addslashes( $kind ) . '" ' ;
                $ret_item[]= $kind ;
            } else if (in_array('K', $this->mOptions['fields'])) {
            #field=K, kind of tag as fullname
                //in_array('z', $this->mOptions['fields']) && $str .= "kind:";
                //$str .= "\t" . self::$mKinds[$struct['kind']];
                //$str .= ' "'. addslashes( self::$mKinds[$kind] ) . '" ' ;
            }

            #field=n
            if (in_array('n', $this->mOptions['fields'])) {
                //$str .= "\t" . "line:" . $struct['line'];
                ;//$str .= ' "'. addslashes( $struct['line'] ) . '" ' ;
            }


            #field=s
            if (in_array('s', $this->mOptions['fields']) && !empty($struct['scope'])) {
                // $scope, $type, $name are current scope variables
                $scope = array_pop($struct['scope']);
                list($type, $name) = each($scope);
                switch ($type) {
                    case 'class':
                    case 'interface':
                        // n_* stuffs are namespace related scope variables
                        // current > class > namespace
                        $n_scope = array_pop($struct['scope']);
                        if(!empty($n_scope)) {
                            list($n_type, $n_name) = each($n_scope);
                            $s_str =  $n_name . '\\' . $name;
                        } else {
                            $s_str =   $name;
                        }

                        //$s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($s_str). "\")";
                        $ret_item[]=["$type" =>  $s_str ];
                        break;
                    case 'method':
                        // c_* stuffs are class related scope variables
                        // current > method > class > namespace
                        $c_scope = array_pop($struct['scope']);
                        list($c_type, $c_name) = each($c_scope);
                        $n_scope = array_pop($struct['scope']);
                        if(!empty($n_scope)) {
                            list($n_type, $n_name) = each($n_scope);
                            $s_str =  $n_name . '\\' . $c_name . '::' . $name;
                        } else {
                            $s_str = $c_name . '::' . $name;

                        }

                        //$s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($s_str). "\")";

                        $ret_item[]=["$type" =>  $s_str ];
                        break;
                    default:
                        //$s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($name). "\")";
                        $ret_item[]=["$type" =>  $name];
                        break;
                }
                $str .= $s_str ;
            }else{
                //scope
                if( $kind == "f" || $kind == "d" || $kind == "c" || $kind == "i" || $kind == "v"   ){

                    $ret_item[]=[];
                    //$str .= ' () ' ;
                }
            }


            #field=i
            if(in_array('i', $this->mOptions['fields'])) {
                $inherits = array();
                if(!empty($struct['extends'])) {
                    $inherits[] =  $this->getRealClassName( $struct['extends']->toString() );
                }
                if(!empty($struct['implements'])) {
                    foreach($struct['implements'] as $interface) {
                        $inherits[] = $this->getRealClassName( $interface->toString());
                    }
                }
                if(!empty($inherits)){
                    //$str .= "\t" . 'inherits:' . implode(',', $inherits);
                    //$str .= ' "'. addslashes( implode(',', $inherits) ) . '" ' ;
                    $ret_item[]= implode(',', $inherits);
                }else{
                    //scope
                    if(  $kind == "c" || $kind == "i"  ){
                        //$str .= ' nil ' ;
                        $ret_item[]=  false; 
                    }
                }
            }else{
                //scope
                if(  $kind == "c" || $kind == "i"  ){
                    //$str .= ' nil ' ;
                    $ret_item[]=  false; 
                }
            }

            #field=a
            if (in_array('a', $this->mOptions['fields']) && !empty($struct['access'])) {
                //$str .= "\t" . "access:" . $struct['access'];
                $ret_item[]=  $struct['access']  ; 
                //$str .= ' "'. addslashes(  $struct['access']  ) . '" ' ;
            }else{

            }

            #type
            if (  $kind == "f" || $kind == "p"  || $kind == "m"  || $kind == "d"  || $kind == "v"  || $kind == "T"  ) {
                //$str .= "\t" . "type:" . $struct['type'] ;
                if ( $struct['type']  ) {
                    //$str .= ' "'. addslashes(  $struct['type']  ) . '" ' ;
                    $ret_item[]=  $struct['type']  ; 
                }else{
                    $str .= ' nil ' ;
                    $ret_item[]= [] ; 
                }

            }

            $ret_arr[]=$ret_item;
            //$str .= ")\n";
        }

        //$str = str_replace("\x0D", "", $str);

        return json_encode ( $ret_arr, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT  );
    }

    private function gen_tags_list($ret_arr) {
        $function_list=[];
        $class_list=[];
        $inherit_list=[];
        foreach ($ret_arr  as $item ) {
            $tag_name = $item[0];
            $type     = $item[3];
            $doc      = $item[2]; 

            if ($type=="f") {

                $return_type=$item[5];
                $scope=$item[4];
                if (@$scope["namespace"]) {
                    $tag_name= @$scope["namespace"]."\\$tag_name";
                }

                $function_list[]=[ $tag_name, "$tag_name(",  $doc, $return_type  ];
            }else if  ($type=="v") {
                $return_type=$item[5];
                $function_list[]=[ $tag_name, "$tag_name(",  $doc, $return_type  ];
            }else if  ($type=="d") {
                $return_type=$item[5];
                $scope=$item[4];
                if ( @scope["class"] ) {
                    
                }else  {
                    if (@$scope["namespace"]) {
                        $tag_name= @$scope["namespace"]."\\$tag_name";
                    }
                    $function_list[]=[ $tag_name, "$tag_name(",  $doc, $return_type  ];
                }
            }else if  ($type=="v") {
            }else if  ($type=="v") {
            }else if  ($type=="v") {
            }else if  ($type=="v") {
            }
           
            
        }
    }

    private function full_render() {
        // Files will have been rendered already, just join and export.

        $str = '';
        foreach($this->mLines as $file => $data) {
          $str .= $data.PHP_EOL;
        }

    /*
        // sort the result as instructed
        if (isset($this->mOptions['sort']) && ($this->mOptions['sort'] == 'yes' || $this->mOptions['sort'] == 'foldcase')) {
            $str = self::stringSortByLine($str, $this->mOptions['sort'] == 'foldcase');
        }

    */
        // Save all tag information to a file for faster updates if a cache file was specified.
        if (isset($this->cachefile)) {
            file_put_contents($this->cachefile, serialize($this->tagdata));
            if ($this->mOptions['V']) {
                echo "Saved cache file.".PHP_EOL;
            }
        }

        $str = trim($str);

        return $str;
    }

    public function export()
    {
        $start = microtime(true);

        if (empty($this->mFiles)) {
            throw new PHPCtagsException('No File specified.');
        }


        foreach (array_keys($this->mFiles) as $file) {
            $ret=$this->process($file);
            if (!$ret){
                return $ret;
            }
        }

        $content = $this->full_render();

        $end = microtime(true);

        if ($this->mOptions['V']) {
            echo PHP_EOL."It took ".($end-$start)." seconds.".PHP_EOL;
        }

        return $content;
    }

    private function process($file)
    {
        // Load the tag md5 data to skip unchanged files.
        if (!isset($this->tagdata) && isset($this->cachefile) && file_exists(realpath($this->cachefile))) {
            if ($this->mOptions['V']) {
                echo "Loaded cache file.".PHP_EOL;
            }
            $this->tagdata = unserialize(file_get_contents(realpath($this->cachefile)));
        }

        if (is_dir($file) && isset($this->mOptions['R'])) {
            $iterator = new RecursiveIteratorIterator(
                new ReadableRecursiveDirectoryIterator(
                    $file,
                    FilesystemIterator::SKIP_DOTS |
                    FilesystemIterator::FOLLOW_SYMLINKS
                )
            );

            $extensions = array('.php', '.php3', '.php4', '.php5', '.phps');

            foreach ($iterator as $filename) {
                if (!in_array(substr($filename, strrpos($filename, '.')), $extensions)) {
                    continue;
                }

                if (isset($this->mOptions['exclude']) && false !== strpos($filename, $this->mOptions['exclude'])) {
                    continue;
                }

                try {
                    $this->process_single_file($filename);
                } catch(Exception $e) {
                    echo "PHPParser: {$e->getMessage()} - {$filename}".PHP_EOL;
                    return false;
                }
            }
        } else {
            try {
                $this->process_single_file($file);
            } catch(Exception $e) {
                echo "PHPParser: {$e->getMessage()} - {$file}".PHP_EOL;
                return false;
            }
        }
        return true;
    }

    private function process_single_file($filename)
    {
        if ($this->mOptions['V'] && $this->filecount > 1 && $this->filecount % 64 == 0) {
            echo " ".$this->filecount." files".PHP_EOL;
        }
        $this->filecount++;
        $startfile = microtime(true);

        $this->setMFile((string) $filename);
        $file = file_get_contents($this->mFile);
        $md5 = md5($file);
        if (isset($this->tagdata[$this->mFile][$md5])) {
            // The file is the same as the previous time we analyzed and saved.
            $this->mLines[$this->mFile] = $this->tagdata[$this->mFile][$md5];
            if ($this->mOptions['V']) {
                echo ".";
            }
            return;
        }

        $struct = $this->struct($this->mParser->parse($file), TRUE);
        $finishfile = microtime(true);
        $this->mLines[$this->mFile] = $this->render($struct);
        $finishmerge = microtime(true);
        $this->tagdata[$this->mFile][$md5] = $this->mLines[$this->mFile];
        if ( isset($this->mOptions['debug']) && $this->mOptions['debug']) {
            echo "Parse: ".($finishfile - $startfile).", Merge: ".($finishmerge-$finishfile)."; (".$this->filecount.")".$this->mFile.PHP_EOL;
        } else if ($this->mOptions['V']) {
            echo ".";
        }
    }

}
  
class PHPCtagsException extends Exception {
    public function __toString() {
        return "\nPHPCtags: {$this->message}\n";
    }
}

class ReadableRecursiveDirectoryIterator extends RecursiveDirectoryIterator {
    function getChildren() {
        try {
            return new ReadableRecursiveDirectoryIterator($this->getPathname());
        } catch(UnexpectedValueException $e) {
            file_put_contents('php://stderr', "\nPHPPCtags: {$e->getMessage()} - {$this->getPathname()}\n");
            return new RecursiveArrayIterator(array());
        }
    }
}

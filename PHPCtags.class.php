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

    /**
     * @var \PhpParser\Parser\Php7
    */
    private $mParser;
    private $mLines;
    private $mOptions;
    private $mUseConfig=array();
    private $tagdata;
    private $cachefile;
    private $filecount;

    public function __construct($options)
    {
        $this->mParser =  new \PhpParser\Parser\Php7(new \PhpParser\Lexer\Emulative);
        $this->mOptions = $options;
        $this->filecount = 0;
    }

    public function setMFile($file)
    {
        $this->mFile=$file;
        //$this->mFileLines = file($file ) ;
    }

    public static function getMKinds()
    {
        return self::$mKinds;
    }
    public function cleanFiles(){
        $this->mLines=array();
        $this->mUseConfig=array();
    }

    public function setCacheFile($file) {
        $this->cachefile = $file;
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


    private function get_key_in_scope( $scope, $key ) {
        foreach ( $scope as $item) {
            $value= @$item[$key];
            if ($value) {return $value ;}
        }
        return false;
    }

    private function getRealClassName($className ,$scope  ){
        if ( $className=="\$this" ||  $className == "static"  || $className =="self" ) {
            $namespace= $this-> get_key_in_scope( $scope, "namespace" );
            $className=  $this-> get_key_in_scope( $scope, "class" );
            if ( $namespace  ) {
                return  "\\$namespace\\$className";
            }else{
                return  "\\$className";
            }
        }

        if (  $className[0] != "\\"  ){
            $ret_arr=explode("\\", $className , 2  );
            $pack_name=$ret_arr[0];
            if (count($ret_arr)==2){
                if (isset($this->mUseConfig[ $pack_name])){
                    return $this->mUseConfig[$pack_name]."\\".$ret_arr[1] ;
                }else{
                    return $className;
                }
            }else{
                if (isset($this->mUseConfig[$pack_name])){
                    return $this->mUseConfig[$pack_name] ;
                }else{
                    return $className;
                }
            }

        }else{
            return $className;
        }

    }

    private function  func_get_return_type($node,$scope) {

        if ( $node->returnType instanceof PhpParser\Node\NullableType ) {
            $return_type="". $node->returnType->type ;
        }else{
            $return_type="". $node->returnType;
        }


        if (!$return_type )  {
            if ( preg_match( "/@return[ \t]+([\$a-zA-Z0-9_\\\\]+)/",$node->getDocComment(), $matches) ){
                $return_type= $matches[1];
            }
        }
        if ($return_type) {
            $return_type=$this->getRealClassName($return_type,$scope);
        }
        return $return_type;
    }
    private function gen_args_default_str( $node )  {


        if ( $node instanceof \PhpParser\Node\Scalar\LNumber ) {
            /**  @var  \PhpParser\Node\Scalar\LNumber  $node  */
            $kind= $node->getAttribute("kind");
            switch ($kind ) {
            case \PhpParser\Node\Scalar\LNumber::KIND_DEC:
                return strval($node->value);
                break;
            case \PhpParser\Node\Scalar\LNumber::KIND_OCT:
                return sprintf("0%o" , $node->value ) ;
                break;
            case \PhpParser\Node\Scalar\LNumber::KIND_HEX:
                return sprintf("0x%x" , $node->value ) ;
                break;
            case \PhpParser\Node\Scalar\LNumber::KIND_BIN:
                return sprintf("0b%b" , $node->value ) ;
                break;
            default:
                return strval($node->value);
                break;
            }

        }else if ( $node instanceof \PhpParser\Node\Scalar\String_ ){
            return "'".$node->value."'";
        }else if ( $node instanceof \PhpParser\Node\Expr\ConstFetch ){
            return strval($node->name);
        }else if ( $node instanceof \PhpParser\Node\Expr\UnaryMinus  ){
            return "" ;
        }else if ( $node instanceof \PhpParser\Node\Expr\BinaryOp\BitwiseOr ){
            return "" ;
        }else if ( $node instanceof  \PhpParser\Node\Expr\Array_  ){
            /**  @var  \PhpParser\Node\Expr\Array_   $node  */
            if ( count( $node->items)==0 ) {
                return "[]" ;
            }else{
                return "array" ;
            }
        }else{
            return @strval($node->value);
        }

    }

    private function get_args( $node ) {

        $args_list=[];
        foreach ( $node->getParams() as $param ) {
            $ref_str="";
            if (@$param->byRef == 1 ) {
                //$ref_str="&";
            }
            if ($param->default ) {
                $def_str=$this->gen_args_default_str( $param->default );
                $args_list[]="$ref_str\$".$param->name."=$def_str";
            }else{
                $args_list[]="$ref_str\$".$param->name;
            }
        }
        return join(", " ,$args_list  );
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
        $args="";


        if (!empty($parent)) array_push($scope, $parent);

        if (is_array($node)) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser\Node\Stmt\UseUse ) {
            $use_name=$node->name->toString();
            if ($use_name[0] != "\\") {
                $use_name="\\".$use_name;
            }
            $this->mUseConfig[$node->alias ]= $use_name ;

        } elseif ($node instanceof PhpParser\Node\Stmt\TraitUse ) {


            foreach ($node ->traits  as $trait ) {
                $type= implode("\\" , $trait->parts) ;

                $name = str_replace("\\","_",$type) ;
                $line = $node->getLine();

                $return_type=$this->getRealClassName($type,$scope);
                $structs[] = array(
                    //'file' => $this->mFile,
                    'kind' => "T",
                    'name' =>  $name,
                    'line' =>  $line ,
                    'scope' =>  $this->get_scope( $scope) ,
                    'access' => "public",
                    'type' => $return_type,
                );
            }


        } elseif ($node instanceof PHPParser\Node\Stmt\Use_ ) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser\Node\Stmt\Class_ or  $node instanceof PHPParser\Node\Stmt\Trait_) {
            $kind = 'c';
            $name = $node->name;
            $extends = @$node->extends;
            $implements = @$node->implements;
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
                            //'file' => $this->mFile,
                            'kind' => "p",
                            'name' => $field_name,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $this->get_scope( $filed_scope ) ,
                            'access' => "public",
                            'type' => $field_return_type,
                        );


                    }else if ( preg_match(
                        "/@method[ \t]+([^\\(]+)[ \t]+([a-zA-Z0-9_]+)[ \t]*\\((.*)\\)/",
                        $line_str, $matches) ){
                        //* @method string imageUrl($width = 640, $height = 480, $category = null, $randomize = true)
                        $field_name=$matches[2];
                        $args =$matches[3];
                        $field_return_type= $this->getRealClassName( $matches[1],$filed_scope);
                        $structs[] = array(
                            //'file' => $this->mFile,
                            'kind' => "m",
                            'name' => $field_name,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $this->get_scope( $filed_scope ) ,
                            'access' => "public",
                            'type' => $field_return_type,
                            "args" =>  $args,
                        );
                    }else if (
                        preg_match(
                        "/@use[ \t]+([a-zA-Z0-9_\\\\]+)/",
                        $line_str, $matches)
                        or (
                            $extends && $extends->toString() =="Facade" &&
                            preg_match(
                        "/@see[ \t]+([a-zA-Z0-9_\\\\]+)/",
                        $line_str, $matches) )

                    ){
                        //* @use classtype

                        $type= $matches[1];
                        $field_name = str_replace("\\","_",$type) ;
                        $field_return_type= $this->getRealClassName( $type,$filed_scope);

                        $structs[] = array(
                            //'file' => $this->mFile,
                            'kind' => "T",
                            'name' => $field_name,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $this->get_scope( $filed_scope ) ,
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
                                    "/@var[ \t]+([a-zA-Z0-9_]+)[ \t]+\\$([a-zA-Z0-9_\\\\]+)/",
                                    $comment->getText(), $matches) ){

                                    $field_name=$matches[2];
                                    $field_return_type= $this->getRealClassName( $matches[1],$filed_scope);
                                    $structs[] = array(
                                        // 'file' => $this->mFile,
                                        'kind' => "p",
                                        'name' => $field_name,
                                        'line' => $comment->getLine() ,
                                        'scope' => $this->get_scope( $filed_scope ) ,
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
        } elseif ($node instanceof PHPParser\Node\Stmt\Property) {
            $kind = 'p';

            $prop = $node->props[0];
            $name = $prop->name;
            $line = $prop->getLine();
            if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1],$scope);
            }

            $access = $this->getNodeAccess($node);


        } elseif ($node instanceof PHPParser\Node\Stmt\ClassConst) {
            $kind = 'd';
            $cons = $node->consts[0];
            $name = $cons->name;
            $line = $cons->getLine();
            $access = "public";
            $return_type="void";
            if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1],$scope);
            }
            $args="class";

        } elseif ($node instanceof PHPParser\Node\Stmt\ClassMethod) {
            $kind = 'm';
            $name = $node->name;
            $line = $node->getLine();

            $access = $this->getNodeAccess($node);
            $return_type=$this->func_get_return_type($node, $scope);

            $args=$this->get_args ( $node );


            /*
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('method' => $name));
            }
            */
        } elseif ($node instanceof PHPParser\Node\Stmt\If_) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser\Node\Expr\LogicalOr ) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }

        } elseif ($node instanceof PHPParser\Node\Stmt\Const_) {
            $kind = 'd';
            $access = "public";
            $cons = $node->consts[0];
            $name = $cons->name;
            $line = $node->getLine();

            $return_type="void";
            if ( preg_match( "/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/",$node->getDocComment(), $matches) ){
                $return_type=$this->getRealClassName( $matches[1],$scope);
            }

            $args="namespace";


        } elseif ($node instanceof PHPParser\Node\Stmt\Global_) {



        } elseif ($node instanceof PHPParser\Node\Stmt\Static_) {
            //@todo
        } elseif ($node instanceof PHPParser\Node\Stmt\Declare_) {
            //@todo
        } elseif ($node instanceof PHPParser\Node\Stmt\TryCatch) {
            /*
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
            */
        } elseif ($node instanceof PHPParser\Node\Stmt\Function_) {



            $kind = 'f';
            $name = $node->name;
            $line = $node->getLine();

            $return_type = $this->func_get_return_type($node,$scope);

            $args=$this->get_args ( $node );



        } elseif ($node instanceof PHPParser\Node\Stmt\Interface_) {
            $kind = 'i';
            $name = $node->name;
            $extends = @$node->extends;

            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('interface' => $name));
            }

        } elseif ($node instanceof PHPParser\Node\Stmt\Trait_ ) {


            $kind = 't';
            $name = $node->name;
            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('trait' => $name));
            }

        } elseif ($node instanceof PHPParser\Node\Stmt\Namespace_) {
            /*
            $kind = 'n';
            $line = $node->getLine();
            */
            $name = $node->name;
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('namespace' => $name));
            }
        } elseif ($node instanceof PHPParser\Node\Expr\Assign_) {
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
        } elseif ($node instanceof PHPParser\Node\Expr\AssignRef) {
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

        } elseif ($node instanceof PHPParser\Node\Expr\FuncCall) {
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
                    $args="namespace";

                    break;
            }
        } else {
            // we don't care the rest of them.
        }

        if (!empty($kind) && $kind !="n" && !empty($name) && !empty($line)) {

            $item=array(
                //'file' => $this->mFile,
                'line' => $line,
                'kind' => $kind,
                'name' => $name,
                "scope" => $this->get_scope( $scope),
            );
            if ( $access ) {
                $item["access"]= $access;
            }
            if ($return_type) {
                $item["type"]= $return_type;
            }
            $inherits= $this->get_inherits( $extends, $implements , $scope );
            if (!empty( $inherits) ) {
                $item["inherits"]= $inherits;
            }
            if ($args ) {
                $item["args"]= $args;
            }

            $structs[] = $item;
        }

        if (!empty($parent)) array_pop($scope);

        return $structs;
    }

    private function render($structure)
    {
        $str = '';
        foreach ($structure as $struct) {
            $file = $struct['file'];

            if (!in_array($struct['kind'], $this->mOptions['kinds'])) {
                continue;
            }

            if (!isset($files[$file]))
                $files[$file] = file($file);

            $lines = $files[$file];

            if (empty($struct['name']) || empty($struct['line']) || empty($struct['kind']))
                return;

            $kind= $struct['kind'];
            $str .= '(';
            if  ($struct['name'] instanceof PHPParser\Node\Expr\Variable ){
                $str .= '"'. addslashes( $struct['name']->name) . '" ' ;
            }else{
                $str .= '"'. addslashes( $struct['name']) . '" ' ;
            }

            $str .= ' "'. addslashes($file.":".$struct['line']  )  . '" ' ;


            if ($this->mOptions['excmd'] == 'number') {
                $str .= "\t" . $struct['line'];
            } else { //excmd == 'mixed' or 'pattern', default behavior
                #$str .= "\t" . "/^" . rtrim($lines[$struct['line'] - 1], "\n") . "$/";
                if ($kind=="f" || $kind=="m" ){
                    $str .= ' "'. addslashes(rtrim($lines[$struct['line'] - 1], "\n")) . '" ' ;
                }else{
                    $str .= ' nil ' ;
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

                $str .= ' "'. addslashes( $kind ) . '" ' ;
            } else if (in_array('K', $this->mOptions['fields'])) {
            #field=K, kind of tag as fullname
                //in_array('z', $this->mOptions['fields']) && $str .= "kind:";
                //$str .= "\t" . self::$mKinds[$struct['kind']];
                $str .= ' "'. addslashes( self::$mKinds[$kind] ) . '" ' ;
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

                        $s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($s_str). "\")";
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

                        $s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($s_str). "\")";
                        break;
                    default:
                        $s_str = "(\"" .  addslashes($type) .  "\".\"".    addslashes($name). "\")";
                        break;
                }
                $str .= $s_str ;
            }else{
                //scope
                if( $kind == "f" || $kind == "d" || $kind == "c" || $kind == "i" || $kind == "v"   ){
                    $str .= ' () ' ;
                }
            }


            #field=i
            if(in_array('i', $this->mOptions['fields'])) {
                $inherits = array();
                if(!empty($struct['extends'])) {
                    $inherits[] =  $this->getRealClassName( $struct['extends']->toString(), $scope );
                }
                if(!empty($struct['implements'])) {
                    foreach($struct['implements'] as $interface) {
                        $inherits[] = $this->getRealClassName( $interface->toString(), $scope);
                    }
                }
                if(!empty($inherits)){
                    //$str .= "\t" . 'inherits:' . implode(',', $inherits);
                    $str .= ' "'. addslashes( implode(',', $inherits) ) . '" ' ;
                }else{
                    //scope
                    if(  $kind == "c" || $kind == "i"  ){
                        $str .= ' nil ' ;
                    }
                }
            }else{
                //scope
                if(  $kind == "c" || $kind == "i"  ){
                    $str .= ' nil ' ;
                }
            }

            #field=a
            if (in_array('a', $this->mOptions['fields']) && !empty($struct['access'])) {
                //$str .= "\t" . "access:" . $struct['access'];
                $str .= ' "'. addslashes(  $struct['access']  ) . '" ' ;
            }else{

            }

            #type
            if (  $kind == "f" || $kind == "p"  || $kind == "m"  || $kind == "d"  || $kind == "v"  || $kind == "T"  ) {
                //$str .= "\t" . "type:" . $struct['type'] ;
                if ( $struct['type']  ) {
                    $str .= ' "'. addslashes(  $struct['type']  ) . '" ' ;
                }else{
                    $str .= ' nil ' ;
                }
            }



            $str .= ")\n";
        }

        $str = str_replace("\x0D", "", $str);

        return $str;
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
        /*
        if (!isset($this->tagdata) && isset($this->cachefile) && file_exists(realpath($this->cachefile))) {
            if ($this->mOptions['V']) {
                echo "Loaded cache file.".PHP_EOL;
            }
            $this->tagdata = unserialize(file_get_contents(realpath($this->cachefile)));
        }
        */

        if ( is_dir($file) && isset($this->mOptions['R'])) {
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

    public function process_single_file($filename)
    {
        $this->setMFile((string) $filename);
        $file = file_get_contents($this->mFile);
        return $this->struct($this->mParser->parse($file), TRUE);

    }
    public function get_inherits($extends, $implements, $scope  ) {
        $inherits = array();
        if(!empty( $extends  )) {
            if (is_array($extends )) {
                foreach ( $extends as $item ) {
                    $inherits[] =  $this->getRealClassName( $item->toString(), $scope );
                }
            }else{
                $inherits[] =  $this->getRealClassName( $extends->toString(), $scope );
            }
        }

        if(!empty( $implements)) {
            foreach( $implements as $interface) {
                $inherits[] = $this->getRealClassName( $interface->toString(), $scope );
            }
        }
        return  $inherits ;
    }
    public  function get_scope( $old_scope) {
        if (!empty($old_scope) ) {
            $scope = array_pop($old_scope);
            list($type, $name) = each($scope);
            switch ($type) {
            case 'class':
            case 'interface':
            case '':
                // n_* stuffs are namespace related scope variables
                // current > class > namespace
                $n_scope = array_pop($old_scope);
                if(!empty($n_scope)) {
                    list($n_type, $n_name) = each($n_scope);
                    $s_str =  '\\'. $n_name . '\\' . $name;
                } else {
                    $s_str =  '\\' . $name;
                }

                return  $s_str;
                break;
            case 'method':
                // c_* stuffs are class related scope variables
                // current > method > class > namespace
                $c_scope = array_pop($scope);
                list($c_type, $c_name) = each($c_scope);
                $n_scope = array_pop($scope);
                if(!empty($n_scope)) {
                    list($n_type, $n_name) = each($n_scope);
                    $s_str =  '\\'. $n_name . '\\' . $c_name . '::' . $name;
                } else {
                    $s_str = '\\'. $c_name . '::' . $name;

                }

                return  $s_str;
                break;
            default:
                return  "\\$name";
                break;
            }
        } else {
            return null;
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

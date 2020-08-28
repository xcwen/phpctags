<?php

use PhpParser\ParserFactory;

class PHPCtags
{
    const VERSION = '0.6.0';

    private $mFile;

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
        $this->mParser =  (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
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
    public function cleanFiles()
    {
        $this->mLines=array();
        $this->mUseConfig=array();
    }

    public function setCacheFile($file)
    {
        $this->cachefile = $file;
    }


    private function getNodeAccess($node)
    {
        if ($node->isPrivate()) {
            return 'private';
        }
        if ($node->isProtected()) {
            return 'protected';
        }
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
    public static function stringSortByLine($str, $foldcase = false)
    {
        $arr = explode("\n", $str);
        if (!$foldcase) {
            sort($arr, SORT_STRING);
        } else {
            sort($arr, SORT_STRING | SORT_FLAG_CASE);
        }
        $str = implode("\n", $arr);
        return $str;
    }

    private static function helperSortByLine($a, $b)
    {
        return $a['line'] > $b['line'] ? 1 : 0;
    }


    private function get_key_in_scope($scope, $key)
    {
        foreach ($scope as $item) {
            $value= @$item[$key];
            if ($value) {
                return $value ;
            }
        }
        return false;
    }

    private function getRealClassName($className, $scope)
    {
        if ($className=="\$this" ||  $className == "static"  || $className =="self") {
            $namespace= $this-> get_key_in_scope($scope, "namespace");
            $className=  $this-> get_key_in_scope($scope, "class");
            if ($namespace) {
                return  "\\$namespace\\$className";
            } else {
                return  "\\$className";
            }
        }

        if ($className[0] != "\\") {
            $ret_arr=explode("\\", $className, 2);
            $pack_name=$ret_arr[0];
            if (count($ret_arr)==2) {
                if (isset($this->mUseConfig[ $pack_name])) {
                    return $this->mUseConfig[$pack_name]."\\".$ret_arr[1] ;
                } else {
                    return $className;
                }
            } else {
                if (isset($this->mUseConfig[$pack_name])) {
                    return $this->mUseConfig[$pack_name] ;
                } else {
                    return $className;
                }
            }
        } else {
            return $className;
        }
    }

    private function func_get_return_type($node, $scope)
    {

        if ($node->returnType instanceof PhpParser\Node\NullableType) {
            $return_type="". $node->returnType->type ;
        } else {
            $return_type="". $node->returnType;
        }


        if (!$return_type) {
            if (preg_match("/@return[ \t]+([\$a-zA-Z0-9_\\\\]+)/", $node->getDocComment(), $matches)) {
                $return_type= $matches[1];
            }
        }
        if ($return_type) {
            $return_type=$this->getRealClassName($return_type, $scope);
        }
        return $return_type;
    }
    private function gen_args_default_str($node)
    {


        if ($node instanceof \PhpParser\Node\Scalar\LNumber) {
            /**  @var  \PhpParser\Node\Scalar\LNumber  $node  */
            $kind= $node->getAttribute("kind");
            switch ($kind) {
                case \PhpParser\Node\Scalar\LNumber::KIND_DEC:
                    return strval($node->value);
                break;
                case \PhpParser\Node\Scalar\LNumber::KIND_OCT:
                    return sprintf("0%o", $node->value) ;
                break;
                case \PhpParser\Node\Scalar\LNumber::KIND_HEX:
                    return sprintf("0x%x", $node->value) ;
                break;
                case \PhpParser\Node\Scalar\LNumber::KIND_BIN:
                    return sprintf("0b%b", $node->value) ;
                break;
                default:
                    return strval($node->value);
                break;
            }
        } elseif ($node instanceof \PhpParser\Node\Scalar\String_) {
            return "'".$node->value."'";
        } elseif ($node instanceof \PhpParser\Node\Expr\ConstFetch) {
            return strval($node->name);
        } elseif ($node instanceof \PhpParser\Node\Expr\UnaryMinus) {
            return "" ;
        } elseif ($node instanceof \PhpParser\Node\Expr\BinaryOp\BitwiseOr) {
            return "" ;
        } elseif ($node instanceof  \PhpParser\Node\Expr\Array_) {
            /**  @var  \PhpParser\Node\Expr\Array_   $node  */
            if (count($node->items)==0) {
                return "[]" ;
            } else {
                return "array" ;
            }
        } else {
            return @strval($node->value);
        }
    }

    private function get_args($node)
    {

        $args_list=[];

        foreach ($node->getParams() as $param) {
            $ref_str="";
            if (@$param->byRef == 1) {
                //$ref_str="&";
            }

            if (!$param->var) {
                continue;
            }

            if ($param->default) {
                $def_str=$this->gen_args_default_str($param->default);
                $args_list[]="$ref_str\$".$param->var ->name."=$def_str";
            } else {
                $args_list[]="$ref_str\$".$param->var->name;
            }
        }
        return join(", ", $args_list);
    }

    private function struct($node, $reset = false, $parent = array())
    {
        static $scope = array();
        static $structs = array();

        if ($reset) {
            $structs = array();
        }




        $kind = $name = $line = $access = $extends = '';
        $static =false;
        $return_type="";
        $implements = array();
        $args="";


        if (!empty($parent)) {
            array_push($scope, $parent);
        }

        if (is_array($node)) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser\Node\Stmt\UseUse) {
            $use_name=$node->name->toString();
            if ($use_name[0] != "\\") {
                $use_name="\\".$use_name;
            }
            if ($node->alias) {
                $this->mUseConfig[$node->alias->name]= $use_name ;
            } else {
                // \use think\console\Command; to : Command => \think\console\Command
                $tmp_arr=preg_split('/\\\\/', $use_name);
                $this->mUseConfig[ $tmp_arr[count($tmp_arr)-1] ]= $use_name ;
            }
        } elseif ($node instanceof PhpParser\Node\Stmt\TraitUse) {
            foreach ($node ->traits as $trait) {
                $type= implode("\\", $trait->parts) ;

                $name = str_replace("\\", "_", $type) ;
                $line = $node->getLine();

                $return_type=$this->getRealClassName($type, $scope);
                $structs[] = array(
                    //'file' => $this->mFile,
                    'kind' => "T",
                    'name' =>  $name,
                    'line' =>  $line ,
                    'scope' =>  $this->get_scope($scope) ,
                    'access' => "public",
                    'type' => $return_type,
                );
            }
        } elseif ($node instanceof PHPParser\Node\Stmt\Use_) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser\Node\Stmt\Class_ or  $node instanceof PHPParser\Node\Stmt\Trait_) {
            $kind = 'c';
            //$name = $node->name;
            $name = $node->name->name;
            $extends = @$node->extends;
            $implements = @$node->implements;
            $line = $node->getLine();

            $filed_scope=$scope;
            array_push($filed_scope, array('class' => $name ));


            $doc_item= $node->getDocComment() ;
            if ($doc_item) {
                $doc_start_line=$doc_item->getLine();
                $arr=explode("\n", ($doc_item->__toString()));
                foreach ($arr as $line_num => $line_str) {
                    if (preg_match(
                        "/@property[ \t]+([a-zA-Z0-9_\\\\]+)[ \t]+\\$?([a-zA-Z0-9_]+)/",
                        $line_str,
                        $matches
                    ) ) {
                        $field_name=$matches[2];
                        $field_return_type= $this->getRealClassName($matches[1], $filed_scope);
                        $structs[] = array(
                            //'file' => $this->mFile,
                            'kind' => "p",
                            'name' => $field_name,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $this->get_scope($filed_scope) ,
                            'access' => "public",
                            'type' => $field_return_type,
                        );
                    } elseif (preg_match(
                        "/@method[ \t]+(static[ \t]+)*([^\\(]+)[ \t]+([a-zA-Z0-9_]+)[ \t]*\\((.*)\\)/",
                        $line_str,
                        $matches
                    ) ) {
                        //* @method static string imageUrl($width = 640, $height = 480, $category = null, $randomize = true)
                        $static_flag=($matches[1]!="");
                        $field_name=$matches[3];
                        $args =$matches[4];
                        $field_return_type= $this->getRealClassName($matches[2], $filed_scope);
                        $structs[] = array(
                            //'file' => $this->mFile,
                            'kind' => "m",
                            'name' => $field_name,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $this->get_scope($filed_scope) ,
                            'access' => "public",
                            'type' => $field_return_type,
                            'static' => $static_flag,
                            "args" =>  $args,
                        );
                    } elseif (preg_match(
                        "/@use[ \t]+([a-zA-Z0-9_\\\\]+)/",
                        $line_str,
                        $matches
                    )
                        or preg_match(
                            "/@mixin[ \t]+([a-zA-Z0-9_\\\\]+)/",
                            $line_str,
                            $matches
                        )
                        or (
                            $extends && $extends->toString() =="Facade" &&
                            preg_match(
                                "/@see[ \t]+([a-zA-Z0-9_\\\\]+)/",
                                $line_str,
                                $matches
                            ) )

                    ) {
                        //* @use classtype

                        $type= $matches[1];
                        $field_name = str_replace("\\", "_", $type) ;
                        $field_return_type= $this->getRealClassName($type, $filed_scope);

                        $structs[] = array(
                            //'file' => $this->mFile,
                            'kind' => "T",
                            'name' => $field_name,
                            'line' =>  $doc_start_line+ $line_num   ,
                            'scope' => $this->get_scope($filed_scope) ,
                            'access' => "public",
                            'type' => $field_return_type,
                        );
                    }
                }
            }

            foreach ($node as $key => $subNode) {
                if ($key=="stmts") {
                    foreach ($subNode as $tmpNode) {
                        $comments=$tmpNode->getAttribute("comments");
                        if (is_array($comments)) {
                            foreach ($comments as $comment) {
                                if (preg_match(
                                    "/@var[ \t]+([a-zA-Z0-9_]+)[ \t]+\\$([a-zA-Z0-9_\\\\]+)/",
                                    $comment->getText(),
                                    $matches
                                ) ) {
                                    $field_name=$matches[2];
                                    $field_return_type= $this->getRealClassName($matches[1], $filed_scope);
                                    $structs[] = array(
                                        // 'file' => $this->mFile,
                                        'kind' => "p",
                                        'name' => $field_name,
                                        'line' => $comment->getLine() ,
                                        'scope' => $this->get_scope($filed_scope) ,
                                        'access' => "public",
                                        'type' => $field_return_type,
                                    );
                                }
                            }
                        }
                    }
                }
                $this->struct($subNode, false, array('class' => $name));
            }
        } elseif ($node instanceof PHPParser\Node\Stmt\Property) {
            $kind = 'p';

            $prop = $node->props[0];
            $name = $prop->name->name;

            $static=$node->isStatic();
            $line = $prop->getLine();
            if (preg_match("/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/", $node->getDocComment(), $matches)) {
                $return_type=$this->getRealClassName($matches[1], $scope);
            }

            $access = $this->getNodeAccess($node);
        } elseif ($node instanceof PHPParser\Node\Stmt\ClassConst) {
            $kind = 'd';
            $cons = $node->consts[0];
            $name = $cons->name->name;
            $line = $cons->getLine();
            $access = "public";
            $return_type="void";
            if (preg_match("/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/", $node->getDocComment(), $matches)) {
                $return_type=$this->getRealClassName($matches[1], $scope);
            }
            $args="class";
        } elseif ($node instanceof PHPParser\Node\Stmt\ClassMethod) {
            $kind = 'm';
            $name = $node->name->name;
            $line = $node->getLine();

            $access = $this->getNodeAccess($node);
            $static =$node->isStatic();
            $return_type=$this->func_get_return_type($node, $scope);

            $args=$this->get_args($node);



            /*
            foreach ($node as $subNode) {
                $this->struct($subNode, FALSE, array('method' => $name));
            }
            */
        } elseif ($node instanceof PHPParser\Node\Stmt\If_) {
            foreach ($node as $subNode) {
                $this->struct($subNode);
            }
        } elseif ($node instanceof PHPParser\Node\Expr\LogicalOr) {
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
            if (preg_match("/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/", $node->getDocComment(), $matches)) {
                $return_type=$this->getRealClassName($matches[1], $scope);
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
            //$name = $node->name;
            $name = $node->name->name;
            $line = $node->getLine();

            $return_type = $this->func_get_return_type($node, $scope);

            $args=$this->get_args($node);
        } elseif ($node instanceof PHPParser\Node\Stmt\Interface_) {
            $kind = 'i';
            //$name = $node->name;
            $name = $node->name->name;
            $extends = @$node->extends;

            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, false, array('interface' => $name));
            }
        } elseif ($node instanceof PHPParser\Node\Stmt\Trait_) {
            $kind = 't';
            //$name = $node->name;
            $name = $node->name->name;
            $line = $node->getLine();
            foreach ($node as $subNode) {
                $this->struct($subNode, false, array('trait' => $name));
            }
        } elseif ($node instanceof PHPParser\Node\Stmt\Namespace_) {
            /*
            $kind = 'n';
            $line = $node->getLine();
            */
            $name = $node->name;
            foreach ($node as $subNode) {
                $this->struct($subNode, false, array('namespace' => $name));
            }
        } elseif ($node instanceof PHPParser\Node\Expr\Assign_) {
            if (isset($node->var->name) && is_string($node->var->name)) {
                $kind = 'v';
                $node = $node->var;
                //$name = $node->name;
                $name = $node->name->name;
                $line = $node->getLine();

                $return_type="void";
                if (preg_match("/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/", $node->getDocComment(), $matches)) {
                    $return_type=$this->getRealClassName($matches[1], $scope);
                }
            }
        } elseif ($node instanceof PHPParser\Node\Expr\AssignRef) {
            if (isset($node->var->name) && is_string($node->var->name)) {
                $kind = 'v';
                $node = $node->var;
                //$name = $node->name;
                $name = $node->name->name;
                $line = $node->getLine();
                $return_type="void";
                if (preg_match("/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/", $node->getDocComment(), $matches)) {
                    $return_type=$this->getRealClassName($matches[1], $scope);
                }
            }
        } elseif ($node instanceof PHPParser\Node\Expr\FuncCall) {
            $name = $node->name->name;
            switch ($name) {
                case 'define':
                    $kind = 'd';
                    $access = "public";
                    $node = $node->args[0]->value;
                    $name = $node->value;
                    $line = $node->getLine();
                    $return_type="void";
                    if (preg_match("/@var[ \t]+([a-zA-Z0-9_\\\\|]+)/", $node->getDocComment(), $matches)) {
                        $return_type=$this->getRealClassName($matches[1], $scope);
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
                "scope" => $this->get_scope($scope),
            );
            if ($access) {
                $item["access"]= $access;
            }
            if ($return_type) {
                $item["type"]= $return_type;
            }
            $inherits= $this->get_inherits($extends, $implements, $scope);
            if (!empty($inherits)) {
                $item["inherits"]= $inherits;
            }
            if ($args) {
                $item["args"]= $args;
            }
            if ($static) {
                $item["static"]= 1;
            }


            $structs[] = $item;
        }

        if (!empty($parent)) {
            array_pop($scope);
        }

        return $structs;
    }





    public function process_single_file($filename)
    {
        $this->setMFile((string) $filename);
        $file = file_get_contents($this->mFile);
        return $this->struct($this->mParser->parse($file), true);
    }
    public function get_inherits($extends, $implements, $scope)
    {
        $inherits = array();
        if (!empty($extends)) {
            if (is_array($extends)) {
                foreach ($extends as $item) {
                    $inherits[] =  $this->getRealClassName($item->toString(), $scope);
                }
            } else {
                $inherits[] =  $this->getRealClassName($extends->toString(), $scope);
            }
        }

        if (!empty($implements)) {
            foreach ($implements as $interface) {
                $inherits[] = $this->getRealClassName($interface->toString(), $scope);
            }
        }
        return  $inherits ;
    }

    public function get_scope($old_scope)
    {
        if (!empty($old_scope)) {
            $scope = array_pop($old_scope);
            list($type, $name) = [key($scope), current($scope)];
            switch ($type) {
                case 'class':
                case 'interface':
                case '':
                    // n_* stuffs are namespace related scope variables
                    // current > class > namespace
                    $n_scope = array_pop($old_scope);
                    if (!empty($n_scope)) {
                        list($n_type, $n_name) = [key($n_scope), current($n_scope)];
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
                    list($c_type, $c_name) = [key($c_scope), current($c_scope)];
                    $n_scope = array_pop($scope);
                    if (!empty($n_scope)) {
                        list($n_type, $n_name) = [key($n_scope), current($n_scope)];
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

class PHPCtagsException extends Exception
{
    public function __toString()
    {
        return "\nPHPCtags: {$this->message}\n";
    }
}

class ReadableRecursiveDirectoryIterator extends RecursiveDirectoryIterator
{
    function getChildren()
    {
        try {
            return new ReadableRecursiveDirectoryIterator($this->getPathname());
        } catch (UnexpectedValueException $e) {
            file_put_contents('php://stderr', "\nPHPPCtags: {$e->getMessage()} - {$this->getPathname()}\n");
            return new RecursiveArrayIterator(array());
        }
    }
}

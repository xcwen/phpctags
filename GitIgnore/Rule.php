<?php
require_once( __DIR__. "/Util.php");
require_once( __DIR__."/Pattern.php");

class Rule
{
    protected $_isExclusion;
    /**
      @var Pattern
     */
    protected $_pattern;

    public function __construct(Pattern $pattern, $isExclusion)
    {
        $this->_pattern = $pattern;
        $this->_isExclusion = $isExclusion;
    }

    /** @return true: include this file, false: exclude this file, null: rule does not apply to this file */
    public function match($path)
    {
        if (!is_string($path)) {
            throw new Exception(__METHOD__." expects a string; given ".Util::describe($path));
        }
        if ($this->_pattern->match($path)) {
            return $this->_isExclusion ? false : true;
        }
        return null;
    }

    public static function parse($str)
    {
        $isExclusion = false;
        if ($str[0] == '!') {
            $isExclusion = true;
            $str = substr($str, 1);
        }
        $pattern = Pattern::parse($str);
        return new self($pattern, $isExclusion);
    }

    public function isExclusion()
    {
        return $this->_isExclusion;
    }

    public function __toString()
    {
        return ($this->isExclusion() ? "!" : "") . $this->_pattern->getPatternString();
    }
}

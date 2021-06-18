<?php

require_once(__DIR__ . "/Util.php");
require_once(__DIR__ . "/Rule.php");


class Ruleset
{
    protected $rules=[];
    public $check_start_pos=0;

    public function addRule($rule)
    {
        if (is_string($rule)) {
            $str = trim($rule);
            if ($str == '') {
                return;
            }
            if ($str[0] == '#') {
                return;
            }
            if (substr($str, 0, 2) == '\\#') {
                $str = substr($str, 1);
            }
            $rule = Rule::parse($str);
        }
        if (!($rule instanceof Rule)) {
            throw new Exception("Argument to Ruleset#addRule should be a string or TOGoS_GitIgnore_Rule; received ".Util::describe($rule));
        }
        $this->rules[] = $rule;
    }

    public function match($path)
    {
        $path=substr($path, $this->check_start_pos);
        if ($path===false) {
            return null;
        }
        if (!is_string($path)) {
            throw new Exception(__METHOD__." expects a string; given ".Util::describe($path));
        }
        $lastResult = null;
        foreach ($this->rules as $rule) {

            /**  @var Rule $rule */
            $result = $rule->match($path);
            if ($result !== null) {
                $lastResult = $result;
            }
        }
        return $lastResult;
    }

    /**
     *@return self
     */
    public static function loadFromStrings($lines)
    {
        $rs = new self;
        foreach ($lines as $line) {
            $rs->addRule($line);
        }
        return $rs;
    }

    public static function loadFromString($str)
    {
        $lines = explode("\n", $str);
        return self::loadFromStrings($lines);
    }

    public static function loadFromFile($filename)
    {
        $rs = new self;
        $fh = fopen($filename);
        while (($line = fgets($fh))) {
            $rs->addRule($line);
        }
        fclose($fh);
        return $rs;
    }
}

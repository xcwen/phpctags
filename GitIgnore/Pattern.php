<?php

require_once(__DIR__. "/Util.php");
class Pattern
{
    protected $patternString;
    protected $regex;
    protected function __construct($pattern, $regex)
    {
        $this->patternString = $pattern;
        $this->regex = $regex;
    }

    public function getPatternString()
    {
        return $this->patternString;
    }

    protected static function patternToRegex($pp)
    {
        preg_match_all('/\*\*|\*|\?|\[![^\]]+\]|\[[^\]]+\]|[^\*\?]/', $pp, $bifs);
        $regex = '';
        $start_close_flag=false;
        //print_r($bifs);

        foreach ($bifs[0] as $part) {
            if ($part == '**') {
                $regex .= ".*";
            } elseif ($part == '*') {
                $regex .= "[^/]*";
            } elseif ($part == '?') {
                $regex .= '?';
            } elseif ($part[0] == '[') {
                // Not exactly, but maybe close enough.
                // Maybe fnmatch is the thing to use
                if (@$part[1] == '!') {
                    $part[1] = '^';
                }
                $regex .= $part;
                $start_close_flag=true;
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return $regex;
    }

    public static function parse($pattern)
    {
        $r = self::patternToRegex($pattern);
        if (strlen($pattern) == 0) {
            throw new Exception("Zero-length pattern string passed to ".__METHOD__);
        }
        if ($pattern[0] == '/') {
            $r = '#^'.substr($r, 1).'.*#';
        } else {
            $r = '#(?:^|/)'.$r.'.*#';
        }
        return new self($pattern, $r);
    }


    public function match($path)
    {
        if (strlen($path) > 0 and $path[0] == '/') {
            throw new Exception("Paths passed to #match should not start with a slash; given: «".$path."»");
        }
        if (!is_string($path)) {
            throw new Exception(__METHOD__." expects a string; given ".Util::describe($path));
        }
        //echo "check  [". $this->regex . "]=>".  $path . "\n";
        return preg_match($this->regex, $path);
    }
}

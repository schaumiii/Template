<?php
class OverrideCustomFunction implements ezcTemplateCustomFunction
{
    public static function getCustomFunctionDefinition( $name )
    {
        if ( $name == "override" )
        {
            $def = new ezcTemplateCustomFunctionDefinition();
            $def->class = __CLASS__;
            $def->method = "override";

            return $def;
        }
    }

    public static function override()
    {
        return new OverrideLocation( "override_test" ); // Refer to override_test.ezt
    }
}


class OverrideLocation implements ezcTemplateLocationInterface
{
    private $keys = array();
    private $path;

    
    public function __construct( $path )
    {
        $this->path = $path;
    }

    public function getPath()
    {
        return $this->path . ".ezt";
    }
}



?>

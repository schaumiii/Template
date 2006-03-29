<?php


class ezcTemplateSymbolTable
{
    const VARIABLE = 1;
    const CYCLE = 2;
    const IMPORT = 3;  // USE is a keyword.

    // Messages.
    const SYMBOL_REDECLARATION = "The symbol <\$%s> is already declared.";
    const SYMBOL_TYPES_NOT_EQUAL = "The %s <\$%s> is already declared as '%s'.";
    const SYMBOL_NOT_DECLARED = "The symbol <\$%s> is not declared";
    const SYMBOL_INVALID_SCOPE = "The %s <\$%s> is cannot be declared in a subblock: while, foreach, if, etc";
    const SYMBOL_IMPORT_FIRST = "'Use' should be declared before other declarations";

    protected $symbols;

    private $scope;

    private $errorMessage = "";

    public function __construct()
    {
        $this->symbols = array();
        $this->scope = 1;

        $this->firstDeclaredType = false;
    }

    public function enter($symbol, $type, $isAutoDeclared = false)
    {
        // Check for redeclaration.
        if( isset( $this->symbols[ $symbol ] ) )
        {
            if( $isAutoDeclared )
            {
                $storedType = $this->symbols[ $symbol ];

                // Check whether the types are equal, when redeclaration is allowed.
                if( $type != $storedType )
                {
                    $this->errorMessage = sprintf( self::SYMBOL_TYPES_NOT_EQUAL, self::symbolToString( $type ), $symbol, self::symbolToString( $storedType ) );
                    return false;
                }
            }
            else
            {
                $this->errorMessage = sprintf( self::SYMBOL_REDECLARATION, $symbol );
                return false;
            }
        }

        // Check whether the declaration is at the top scope.  Scope level 1.
        if( !$isAutoDeclared && $this->scope != 1 )
        {
            $this->errorMessage = sprintf( self::SYMBOL_INVALID_SCOPE, self::symbolToString( $type ), $symbol );
            return false;
        }

        if( $this->firstDeclaredType === false )
        {
            $this->firstDeclaredType = $type;
        }
        else
        {
            if ($type === self::IMPORT && $this->firstDeclaredType !== self::IMPORT )
            {
                $this->errorMessage = sprintf( self::SYMBOL_IMPORT_FIRST );
                return false;
            }
        }

        $this->symbols[ $symbol ] = $type;
        return true;
    }

    public function retrieve( $symbol )
    {
        if( !isset( $this->symbols[ $symbol ] ) )
        {
            $this->errorMessage = sprintf ( self::SYMBOL_NOT_DECLARED, $symbol );
            return false;
        }

        return $this->symbols[ $symbol ];
    }

    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    public static function symbolToString( $type )
    {
        switch ( $type )
        {
            case self::VARIABLE: return "variable";
            case self::CYCLE: return "cycle";
            case self::IMPORT: return "use";
        }
    }

    public function increaseScope()
    {
        ++$this->scope;
    }

    public function decreaseScope()
    {
        --$this->scope;
    }



}


?>
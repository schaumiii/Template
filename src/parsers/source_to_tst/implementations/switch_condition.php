<?php
/**
 * File containing the ezcTemplateSwitchConditionSourceToTstParser class
 *
 * @package Template
 * @version //autogen//
 * @copyright Copyright (C) 2005, 2006 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */
/**
 *
 * @package Template
 * @copyright Copyright (C) 2005, 2006 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 * @version //autogen//
 */
class ezcTemplateSwitchConditionSourceToTstParser extends ezcTemplateSourceToTstParser
{
    /**
     * Passes control to parent.
     */
    function __construct( ezcTemplateParser $parser, /*ezcTemplateSourceToTstParser*/ $parentParser, /*ezcTemplateCursor*/ $startCursor )
    {
        parent::__construct( $parser, $parentParser, $startCursor );
        $this->block = null;
    }

    /**
     * Parses the expression by using the ezcTemplateExpressionSourceToTstParser class.
     */
    protected function parseCurrent( ezcTemplateCursor $cursor )
    {
        $name = $this->block->name;

        // handle closing block
        if ( $this->block->isClosingBlock )
        {
            if ( $this->parser->debug )
                echo "Starting end of \"switch or case\"\n";

            // skip whitespace and comments
            $this->findNextElement();
            
            if( !$cursor->match( '}' ) )
            {
                throw new ezcTemplateSourceToTstParserException( $this, $cursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_CURLY_BRACKET_CLOSE );
            }

            if( $name == 'switch' )
            {
                $sw = $this->parser->createSwitch( $this->startCursor, $cursor );
            }
            else
            {
                // Tricky: Skip the spaces and new lines. Next element should be an case, or default.
                $this->findNextElement();

                $sw = $this->parser->createCase( $this->startCursor, $cursor );
            }
            //$el->name = 'switch';
            $sw->isClosingBlock = true;
            $this->appendElement( $sw );
            return true;
        }


        if( $name == 'switch' )
        {
            $this->findNextElement();
            if ( !$this->parseRequiredType( 'Expression', null, false ) )
            {
                throw new ezcTemplateSourceToTstParserException( $this, $cursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_EXPRESSION );
            }

            $this->findNextElement();
            if( !$cursor->match( '}' ) )
            {
                throw new ezcTemplateSourceToTstParserException( $this, $cursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_CURLY_BRACKET_CLOSE );
            }

            // Tricky: Skip the spaces and new lines. Next element should be an case, or default.
            $this->findNextElement();

            $sw = $this->parser->createSwitch( $this->startCursor, $cursor );

            $sw->condition = $this->lastParser->rootOperator;
            $this->appendElement( $sw );

            return true;
        }
        elseif( $name == 'case' )
        {
            $case = $this->parser->createCase( $this->startCursor, $cursor );

            do
            {
                $this->findNextElement();

                if ( !$this->parseRequiredType( 'Literal', null, false ) )
                {
                    throw new ezcTemplateSourceToTstParserException( $this, $cursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_EXPRESSION );
                }

                $case->conditions[] = $this->lastParser->element;

                $this->findNextElement();

            } 
            while( $cursor->match( ',' ) );

            if( !$cursor->match( '}' ) )
            {
                throw new ezcTemplateSourceToTstParserException( $this, $cursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_CURLY_BRACKET_CLOSE );
            }

            // Tricky: Skip the spaces and new lines. Next element should be an case, or default.
            $this->findNextElement();


            $this->appendElement( $case );

            return true;
        }
        elseif( $name == 'default' )
        {
            $case = $this->parser->createCase( $this->startCursor, $cursor );
            $case->conditions = null;
            $this->findNextElement();

            if( !$cursor->match( '}' ) )
            {
                throw new ezcTemplateSourceToTstParserException( $this, $cursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_CURLY_BRACKET_CLOSE );
            }

            // Tricky: Skip the spaces and new lines. Next element should be an case, or default.
            $this->findNextElement();
            $this->appendElement( $case );

            return true;
        }
    }
}

?>
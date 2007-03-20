<?php
/**
 * File containing the ezcTemplateTstToAstTransformer class
 *
 * @package Template
 * @version //autogen//
 * @copyright Copyright (C) 2005-2007 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 * @access private
 */
/**
 * Transforms the TST tree to an AST tree.
 *
 * Implements the ezcTemplateTstNodeVisitor interface for visiting the nodes
 * and generating the appropriate ast nodes for them.
 *
 * @package Template
 * @version //autogen//
 * @access private
 */
class ezcTemplateTstToAstTransformer implements ezcTemplateTstNodeVisitor
{
    /**
     * Prefix or all internal variables, e.g. counter and output.
     */
    const INTERNAL_PREFIX = "i_";
    /**
     * Prefix or all external variables, ie. those coming from the template source.
     */
    const EXTERNAL_PREFIX = "t_";

    /**
     * The root node for the AST tree.
     *
     * @var ezcTemplateAstNode
     */
    public $programNode = null;

    /**
     * The object which contains all the template functions which are available.
     *
     * @var ezcTemplateFunctions
     */
    public $functions;

    /**
     * The main parser object, passed to the constructor.
     *
     * @var ezcTemplateParser
     */
    public $parser;

    /**
     * Keeps track of the current output variable and name.
     * When the output variable needs to be changed (e.g. a delimiter) this can be used
     * by calling {@link ezcTemplateOutputVariableManager::push() push()} on it.
     *
     * @var ezcTemplateOutputVariableManager
     */
    protected $outputVariable;

    /**
     * Keeps track of the current delimiter output variable and name.
     * When the delimiter output variable needs to be changed (e.g. a new delimiter) this can be used
     * by calling {@link ezcTemplateOutputVariableManager::push() push()} on it.
     *
     * @var ezcTemplateOutputVariableManager
     */
    protected $delimOutputVar;

    /**
     * Keeps track of the current delimiter counter variable.
     * When the delimiter counter variable needs to be changed (e.g. a new delimiter) this can be used
     * by calling {@link ezcTemplateOutputVariableManager::push() push()} on it.
     *
     * @var ezcTemplateOutputVariableManager
     */
    protected $delimCounterVar;

    /**
     * An associative array which keeps track of the number of variables
     * in use with the same name. This ensures that unique variable names
     * can be made for one compiled file.
     *
     * @see getUniqueVariableName()
     * @var array(string=>int)
     */
    private $variableNames = array();

    /**
     * Controls the output of visitVariableTstNode().
     *
     * @var bool
     */
    private $noProperty = false;

    /**
     * This is set to true when method calls are to be generated.
     *
     * Note: Method calls are currently not allowed and will throw an exception.
     *
     * @see visitFunctionCallTstNode()
     * @see appendReferenceOperatorRecursively()
     * @var bool
     */
    private $isFunctionFromObject = false;

    /**
     * Controls whether array append operators are currently allowed or not.
     * @var bool
     */
    private $allowArrayAppend = false;

    /**
     * Contains the names of all declared variables, the name is the key in the associative array.
     * @var array(string=>bool)
     */
    protected $declaredVariables = array();

    /**
     * Initialize the transformer, after this send this object to the accept() method on a node.
     *
     * @param ezcTemplateParser $parser The main parser object.
     */
    public function __construct( ezcTemplateParser $parser )
    {
        $this->functions = new ezcTemplateFunctions( $parser );
        $this->parser = $parser;

        $this->outputVariable  = new ezcTemplateOutputVariableManager( "" );
        $this->delimOutputVar  = new ezcTemplateOutputVariableManager( "" );
        $this->delimCounterVar = new ezcTemplateOutputVariableManager( 0 );
    }

    public function __destruct()
    {
    }

    /**
     * Returns a unique variable name based on the input $name.
     * This method keeps track of the number of variables with the same
     * name and will append a unique number to the end.
     *
     * @param string $name
     * @return string
     */
    private function getUniqueVariableName( $name )
    {
        if ( !isset( $this->variableNames[$name] ) )
        {
            $this->variableNames[$name] = 1;
        }
        else
        {
            ++$this->variableNames[$name];
        }

        $ret = self::INTERNAL_PREFIX . $name . $this->variableNames[$name];
        return $ret;
    }

    /**
     * This creates a new AST node which holds an internal variable.
     *
     * @see createTemplateVariableNode()
     * @param string $name
     * @return ezcTemplateVariableAstNode
     */
    protected function createVariableNode( $name )
    {
        $node = new ezcTemplateVariableAstNode( $name );
        $symbolTable = ezcTemplateSymbolTable::getInstance();
        if ( $symbolTable->getTypeHint( $name ) == false )
        {
            $node->typeHint = ezcTemplateAstNode::TYPE_ARRAY | ezcTemplateAstNode::TYPE_VALUE;
        }
        else
        {
            // Will this work, values from this function is different than AST contants?
            $node->typeHint = $symbolTable->getTypeHint( $name );
        }
        return $node;
    }

    /**
     * This creates a new AST node which holds a template variable (from the template source).
     *
     * @see createVariableNode()
     * @param string $name
     * @return ezcTemplateVariableAstNode
     */
    private function createTemplateVariableNode( $name )
    {
        $astName = self::EXTERNAL_PREFIX . $name;
        $node = new ezcTemplateVariableAstNode( $astName );

        $symbolTable = ezcTemplateSymbolTable::getInstance();
        if ( $symbolTable->getTypeHint( $astName ) == false )
        {
            $node->typeHint = ezcTemplateAstNode::TYPE_ARRAY | ezcTemplateAstNode::TYPE_VALUE;
        }
        else
        {
            // Will this work, values from this function is different than AST contants?
            $node->typeHint = $symbolTable->getTypeHint( $astName );
        }

        return $node;
    }

    /**
     * Goes through all parameter of the operator, transforms it and appends the result to the operator.
     * The new AST operator node is returned.
     *
     * @param ezcTemplateOperatorTstNode $type The operator TST node.
     * @param ezcTemplateOperatorAstNode $astNode The AST node type to create, will be cloned.
     * @param int $currentParameterNumber Which parameter it is looking at. Used internally, do not touch.
     * @return ezcTemplateAstNode
     * @throws ezcTemplateParserException if a type hint error is found.
     */
    private function appendOperatorRecursively( ezcTemplateOperatorTstNode $type, ezcTemplateOperatorAstNode $astNode, $currentParameterNumber = 0)
    {
        $this->allowArrayAppend = false;
        $node = clone( $astNode );
        
        try
        {
            $appendNode = $type->parameters[ $currentParameterNumber ]->accept( $this );
            $node->appendParameter( $appendNode );
            $typeHint1 = $appendNode->typeHint; // TODO: Remove?

            $currentParameterNumber++;

            if ( $currentParameterNumber == sizeof( $type->parameters ) - 1 ) 
            {
                // The last node.
                $appendNode = $type->parameters[ $currentParameterNumber ]->accept( $this );
                $node->appendParameter( $appendNode );
            }
            else
            {
                // More than two parameters, so repeat.
                $appendNode = $this->appendOperatorRecursively( $type, $astNode, $currentParameterNumber );
                $node->appendParameter( $appendNode  );
            }
        }
        catch ( ezcTemplateTypeHintException $e )
        {
            throw new ezcTemplateParserException( $type->source, $type->endCursor, $type->endCursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_VALUE );
        }
        return $node;
    }

    private function createMultiBinaryOperatorAstNode( $type, ezcTemplateOperatorAstNode $astNode, $addParenthesis = true )
    {
        $this->allowArrayAppend =false; // TODO: check this line.

        try
        {
            $node = clone $astNode;
            $node->appendParameter( $type->parameters[0]->accept( $this ) );

            for($i = 1; $i < sizeof( $type->parameters ) - 1; $i++ )
            {
                $node->appendParameter( $type->parameters[$i]->accept( $this ) );
                $tmp = ( $addParenthesis ?  new ezcTemplateParenthesisAstNode( $node ) : $node );

                $node = clone $astNode;
                $node->appendParameter( $tmp );
            }

            $node->appendParameter( $type->parameters[$i]->accept( $this ) );
        } 
        catch ( Exception $e )
        {
            throw new ezcTemplateParserException( $type->source, $type->endCursor, $type->endCursor, ezcTemplateSourceToTstErrorMessages::MSG_TYPEHINT_FAILURE );
        }

        return $node;
    }


    private function createUnaryOperatorAstNode( $type, ezcTemplateOperatorAstNode $astNode, $addParenthesis = true )
    {
        $astNode->appendParameter( $type->parameters[0]->accept( $this ) );

        return ( $addParenthesis ?  new ezcTemplateParenthesisAstNode( $astNode ) : $astNode );
    }

    /**
     * Checks if the AST node $astNode is considered an assignment.
     *
     * Currently these nodes are considered as assignments:
     * - ezcTemplateAssignmentOperatorAstNode
     * - ezcTemplateIncrementOperatorAstNode
     * - ezcTemplateDecrementOperatorAstNode
     *
     * @param ezcTemplateAstNode $astNode
     * @return bool
     */
    private function isAssignmentNode( $astNode )
    {
        if ( $astNode instanceof ezcTemplateAssignmentOperatorAstNode ) return true;
        if ( $astNode instanceof ezcTemplateIncrementOperatorAstNode )  return true;
        if ( $astNode instanceof ezcTemplateDecrementOperatorAstNode )  return true;

        return false;
    }

    /**
     * Assigns the AST node $astNode to the output if it is suitable.
     *
     * Unsuitable nodes are assignment and statement nodes.
     *
     * @param ezcTemplateAstNode $astNode
     * @return ezcTemplateAstNode
     */
    private function addOutputNodeIfNeeded( ezcTemplateAstNode $astNode )
    {
        if ( $this->isAssignmentNode( $astNode ) ||  $astNode instanceof ezcTemplateStatementAstNode )
        {
            return $astNode;
        }

        return $this->assignToOutput( $astNode );
    }

    /**
     * It's an implementation that creates a new body part.
     * Convenience method for creating a Body AST node from an
     * array of AST nodes. Each element in the array can be a sub-array containing nodes.
     * For each node in the array it will call
     * ezcTemplateBodyAstNode::appendStatement().
     *
     * <code>
     * $list[] = new ezcTemplateGenericStatementAstNode( $node );
     * $body = $this->createBody( $list );
     * </code>
     *
     * @param array $elements
     * @return ezcTemplateBodyAstNode
     * @throws ezcTemplateInternalException if the node is not of the interface ezcTemplateStatementAstNode.
     */
    protected function createBody( array $elements )
    {
        $body = new ezcTemplateBodyAstNode();

        foreach ( $elements as $element )
        {
            $astNode = $element->accept( $this );
            if ( is_array( $astNode ) )
            {
                foreach ( $astNode as $ast )
                {
                    if ( $ast instanceof ezcTemplateStatementAstNode )
                    {
                        $body->appendStatement( $ast );
                    }
                    else
                    {
                        throw new ezcTemplateInternalException( sprintf( "Expected an ezcTemplateStatementAstNode, got %s: " . __FILE__ . ":" . __LINE__, get_class( $ast ) ) );
                    }

                }
            }
            else
            {
                if ( $astNode instanceof ezcTemplateStatementAstNode )
                {
                    $body->appendStatement( $astNode );
                }
                else
                {
                    throw new ezcTemplateInternalException ("Expected an ezcTemplateStatementAstNode: " . __FILE__ . ":" . __LINE__ );
                }
            }
        }

        return $body;
    }

    /**
     * Assigns the AST node $node to the output by creating the
     * correct output AST tree and returning it.
     * The output variable is (ie. left hand side) is extracted from
     * {@link ezcTemplateTstToAstTransform::outputVariable $outputVariable}.
     *
     * @param ezcTemplateAstNode $node
     * @return ezcTemplateAstNode
     */
    private function assignToOutput( $node )
    {
        return $this->outputVariable->getConcatAst( $node );
    }

    /**
     * Sets up the standard header of the program node $programNode
     * by adding some comments and some checks.
     *
     * Note: This must be called by any subclasses of this class if visitProgramTstNode() is overriden.
     *
     * @param ezcTemplateRootAstNode $programNode
     * @return void
     */
    protected function handleProgramHeader( $programNode )
    {
        $programNode->appendStatement( new ezcTemplateEolCommentAstNode( "Generated PHP file from template code." ) );
        $programNode->appendStatement( new ezcTemplateEolCommentAstNode( "If you modify this file your changes will be lost when it is regenerated." ) );

        // Add: $this->checkRequirements()
        $compileFlags = new ezcTemplateLiteralArrayAstNode();
        $compileFlags->keys[] = new ezcTemplateLiteralAstNode("disableCache");
        $compileFlags->value[] = new ezcTemplateLiteralAstNode( $this->parser->template->usedConfiguration->disableCache );

        $args = array( new ezcTemplateLiteralAstNode( ezcTemplateCompiledCode::ENGINE_ID ), $compileFlags );
        $call = new ezcTemplateFunctionCallAstNode( "checkRequirements", $args );
        $programNode->appendStatement( new ezcTemplateGenericStatementAstNode( new ezcTemplateReferenceOperatorAstNode( new ezcTemplateVariableAstNode( "this" ), $call ) ) );
    }

    /**
     * Common method for loop constructors to handle the initialization of
     * the loop.
     *
     * This is currently used by visitForeachLoopTstNode() and visitWhileLoopTstNode().
     *
     * @param array(ezcTemplateAstNode) $astNode Array of AST nodes which is placed in the beginning of the loop construct.
     * @param int $i Counter for the $astNode array, use this to insert at the correct place. Remember to increment it.
     * @param ezcTemplateAstNode $body The body node which is used to create some extra code for each iteration in the loop construct.
     * @return void
     */
    protected function handleLoopInit( &$astNode, &$i, &$body )
    {
        if ( $this->delimOutputVar->isUsed() )
        {
            // Assign the delimiter variable to 0 (above foreach).
            // $_ezcTemplate_delimiterCounter = 0
            $astNode[$i++] = $this->delimCounterVar->getInitializationAst();

            // Assign delimiter output to "" (above foreach)
            // $_ezcTemplate_delimiterOut = ""
            $astNode[$i++] = $this->delimOutputVar->getInitializationAst();

            array_unshift( $body->statements, $this->delimOutputVar->getInitializationAst() );

            $inc = new ezcTemplateIncrementOperatorAstNode( true );
            $inc->appendParameter( $this->delimCounterVar->getAst() );

            array_unshift( $body->statements,
                           new ezcTemplateGenericStatementAstNode( $inc ) );

            // output delimiter output (in foreach).
            // $_ezcTemplate_output .= $_ezcTemplate_delimiterOut;
            array_unshift( $body->statements,
                           $this->outputVariable->getConcatAst( $this->delimOutputVar->getAst() ) );
        }
    }

    private function appendReferenceOperatorRecursively( ezcTemplateOperatorTstNode $type, $currentParameterNumber = 0)
    {
        $this->allowArrayAppend = false;
        $node = new ezcTemplateReferenceOperatorAstNode;
        
        $appendNode = $type->parameters[ $currentParameterNumber ]->accept( $this );
        $node->appendParameter( $appendNode );

        $this->isFunctionFromObject = true;
        $currentParameterNumber++;

        if ( $currentParameterNumber == sizeof( $type->parameters ) - 1 ) 
        {
            // The last node.
            $appendNode = $type->parameters[ $currentParameterNumber ]->accept( $this );
            $node->appendParameter( $appendNode );
        }
        else
        {
            // More than two parameters, so repeat.
            $appendNode = $this->appendReferenceOperatorRecursively( $type, $currentParameterNumber );
            $node->appendParameter( $appendNode  );
        }

        $this->isFunctionFromObject = false;
        return $node;
    }

    private function appendFunctionCallRecursively( ezcTemplateOperatorTstNode $type, $functionName, $checkNonArray = false, $currentParameterNumber = 0)
    {
        $paramAst = array();

        $paramAst[] = $type->parameters[ $currentParameterNumber ]->accept( $this );
        if ( $checkNonArray && !( $paramAst[0]->typeHint & ezcTemplateAstNode::TYPE_VALUE ) )
        {
            throw new ezcTemplateParserException( $type->source, $type->parameters[$currentParameterNumber]->startCursor, 
                $type->parameters[$currentParameterNumber]->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_VALUE_NOT_ARRAY );
        }

        $currentParameterNumber++;

        if ( $currentParameterNumber == sizeof( $type->parameters ) - 1 ) 
        {
            // The last node.
            $paramAst[] = $type->parameters[ $currentParameterNumber ]->accept( $this );
            
        }
        else
        {
            // More than two parameters, so repeat.
            $paramAst[] = $this->appendFunctionCallRecursively( $type, $functionName, $checkNonArray, $currentParameterNumber );
        }

        if ( $checkNonArray && !( $paramAst[1]->typeHint & ezcTemplateAstNode::TYPE_VALUE ) )
        {
            throw new ezcTemplateParserException( $type->source, $type->parameters[$currentParameterNumber]->startCursor, 
                $type->parameters[$currentParameterNumber]->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_VALUE_NOT_ARRAY );
        }


        // return $this->functions->getAstTree( $functionName, $paramAst );

        $ast = $this->functions->getAstTree( $functionName, $paramAst );
        return $ast;
   }

    /**
     * Prepare for the program run.
     * Caching uses this method as well.
     */
    protected function prepareProgram()
    {
        // Prepare for program run
        $this->programNode = new ezcTemplateRootAstNode();
        $this->handleProgramHeader( $this->programNode );
        $this->outputVariable->push( self::INTERNAL_PREFIX . "output" );
        $this->programNode->appendStatement( $this->outputVariable->getInitializationAst() );
    }


    /**
     * Visits the program TST node.
     *
     * Note: This is the first node in the TST tree.
     *
     * @see handleProgramHeader()
     * @return ezcTemplateAstNode
     */
    public function visitProgramTstNode( ezcTemplateProgramTstNode $type )
    {
        if ( $this->programNode === null )
        {
            $this->prepareProgram();

            foreach ( $type->elements as $element )
            {
                $astNode = $element->accept( $this );
                if ( is_array( $astNode ) )
                {
                    foreach ( $astNode as $ast )
                    {
                        if ( $ast instanceof ezcTemplateStatementAstNode )
                        {
                            $this->programNode->appendStatement( $ast );
                        }
                        else
                        {
                            throw new ezcTemplateInternalException ("Expected an ezcTemplateStatementAstNode: ". __FILE__ . ":" . __LINE__ );

                        }
                    }
                }
                else
                {
                    if ( $astNode instanceof ezcTemplateStatementAstNode )
                    {
                        $this->programNode->appendStatement( $astNode );
                    }
                    else
                    {
                        throw new ezcTemplateInternalException ("Expected an ezcTemplateStatementAstNode: ". __FILE__ . ":" . __LINE__  );
                    }
                }
            }

            $this->programNode->appendStatement( new ezcTemplateReturnAstNode( $this->outputVariable->getAst()) );
        }
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitCustomBlockTstNode( ezcTemplateCustomBlockTstNode $type )
    {
        $def = $type->definition;

        $params = new ezcTemplateLiteralArrayAstNode();
        foreach ( $type->namedParameters as $key => $value )
        {
            $params->keys[] = new ezcTemplateLiteralAstNode( $key );
            $params->value[] = $value->accept($this);
        }

        if( $def->hasCloseTag && $def->isStatic )
        {
            throw new ezcTemplateParserException($type->source, $type->startCursor, $type->startCursor, "The *static* CustomBlock cannot have a open and close tag.");
        }

        if ( $def->hasCloseTag )
        {
            $result = array(); // Will contain an array with AST nodes.

            // Write to the custom block output. 
            $this->outputVariable->push( $this->getUniqueVariableName( self::INTERNAL_PREFIX . "custom" ) );

            // Set the output to "".
            $result[] = $this->outputVariable->getInitializationAst();

            // execute all the 'children' in the custom block.
            foreach ( $type->elements as $element )
            {
                $r = $element->accept( $this );
                // It could be an array :-(. Should change this one time to a pseudo node.

                if ( is_array( $r ) )
                {
                    foreach ($r as $a ) 
                    {
                        $result[] = $a; 
                    }
                }
                else
                {
                    $result[]  = $r;
                }
            }

            $customBlockOutput = $this->outputVariable->getAst();
            $this->outputVariable->pop();

            $result[] = new ezcTemplateGenericStatementAstNode( 
                new ezcTemplateConcatAssignmentOperatorAstNode( $this->outputVariable->getAst(), 
                   new ezcTemplateFunctionCallAstNode( $def->class . "::".$def->method, 
                   array( $params, $customBlockOutput ) ) ) ); 

            return $result;
        }
        else
        {
            // If static.
            if( $def->isStatic )
            {
                $p = array();
                
                // Check whether all values are static.
                for( $i = 0; $i < sizeof( $params->value ); $i++)
                {
                    if( !($params->value[$i] instanceof ezcTemplateLiteralAstNode ) )
                    {
                        throw new ezcTemplateParserException($type->source, $type->startCursor, $type->startCursor, "The *static* CustomBlock needs static parameters.");
                    }

                    $p[$params->keys[$i]->value] = $params->value[$i]->value;

                }

                // call the method.
                $r = call_user_func_array( array($def->class, $def->method), array($p) );

                // And assign it to the output.
                return $this->assignToOutput( new ezcTemplateLiteralAstNode($r) );
            }
         
            return new ezcTemplateGenericStatementAstNode( 
                new ezcTemplateConcatAssignmentOperatorAstNode( $this->outputVariable->getAst(), 
                   new ezcTemplateFunctionCallAstNode( $def->class . "::".$def->method, 
                   array( $params ) ) ) ); 
        }
    }

    /*
    public function visitCacheTstNode( ezcTemplateCacheTstNode $type )
    {
        if ( $type->type == ezcTemplateCacheTstNode::TYPE_TEMPLATE_CACHE )
        {
            // Modify the root node.
            $this->programNode->cacheTemplate = true;

            foreach ( $type->keys as $key )
            {
                // Translate the 'old' variableName to the new name.
                $k = $key->accept($this);
                $this->programNode->cacheKeys[] = $k->name;
            }

            // And translate the ttl.
            if ( $type->ttl != null ) 
            {
                $this->programNode->ttl = $type->ttl->accept($this);
            }

            return new ezcTemplateNopAstNode();
        }
    }
     */

    /**
     * Return NOP.
     *
     * @return ezcTemplateNopAstNode
     */
    public function visitCacheTstNode( ezcTemplateCacheTstNode $type )
    {
        return new ezcTemplateNopAstNode();
    }


    /**
     * Skips the dynamic block and process the statements inside.
     *
     * @return array(ezcTemplateAstNode)
     */
    public function visitDynamicBlockTstNode( ezcTemplateDynamicBlockTstNode $node )
    {
        $t = $this->createBody( $node->elements );
        return $t->statements; 
    }

    /**
     * Skips the cache block and process the statements inside.
     *
     * @return array(ezcTemplateAstNode)
     */
    public function visitCacheBlockTstNode( ezcTemplateCacheBlockTstNode $node )
    {
        $t = $this->createBody( $node->elements );
        return $t->statements; 
    }
 

    /**
     * @return ezcTemplateAstNode
     */
    public function visitCycleControlTstNode( ezcTemplateCycleControlTstNode $cycle )
    {
        if ( $cycle->name == "increment" || $cycle->name == "decrement" || $cycle->name == "reset" )
        {
            $ast = array();
            foreach ( $cycle->variables as $var )
            {
                $this->noProperty = true;

                $fc = new ezcTemplateFunctionCallAstNode( $cycle->name, array() );
                $fc->typeHint = ezcTemplateAstNode::TYPE_ARRAY | ezcTemplateAstNode::TYPE_VALUE;

                $b = new ezcTemplateGenericStatementAstNode( new ezcTemplateReferenceOperatorAstNode( $var->accept( $this ), $fc ) );
 /* new ezcTemplateFunctionCallAstNode( "\$".$var->name . "->".$cycle->name, array() )*/ 
                $this->noProperty = false;

                $ast[] = $b;
            }
            return $ast;
        }

    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitLiteralBlockTstNode( ezcTemplateLiteralBlockTstNode $type )
    {
        return $this->assignToOutput( new ezcTemplateLiteralAstNode( $type->text ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitEmptyBlockTstNode( ezcTemplateEmptyBlockTstNode $type )
    {
        return new ezcTemplateEolCommentAstNode( 'Result of empty block {}' );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitParenthesisTstNode( ezcTemplateParenthesisTstNode $type )
    {
        $expression = $type->expressionRoot->accept( $this );
        $newNode = new ezcTemplateParenthesisAstNode( $expression );
        $newNode->typeHint = $expression->typeHint;
        return $newNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitOutputBlockTstNode( ezcTemplateOutputBlockTstNode $type )
    {
        if ( $type->expressionRoot === null ) // The output block may be empty.
        {
            return new ezcTemplateNopAstNode();  
        }

        $expression = $type->expressionRoot->accept( $this ); 
        $output = new ezcTemplateOutputAstNode( $expression );

        $output->isRaw = $type->isRaw;

        return $this->assignToOutput( $output );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitModifyingBlockTstNode( ezcTemplateModifyingBlockTstNode $type )
    {
        $expression = $type->expressionRoot->accept( $this ); 
        return  new ezcTemplateGenericStatementAstNode( $expression );
    }

    /**
     * Transform the literal from a TST node and to an AST node.
     * The text will transformed by processing the escape sequences
     * according to the type which is either
     * {@link ezcTemplateLiteralTstNode::SINGLE_QUOTE single quote} or
     * {@link ezcTemplateLiteralTstNode::DOUBLE_QUOTE double quite}.
     *
     * @return ezcTemplateAstNode
     *
     * @see ezcTemplateStringTool::processSingleQuotedEscapes()
     * @see ezcTemplateStringTool::processDoubleQuotedEscapes()
     */
    public function visitLiteralTstNode( ezcTemplateLiteralTstNode $type )
    {
        // TODO: The handling of escape characters should be done in the
        //       parser and not here. Like the text/literal blocks.
        if ( $type->quoteType == ezcTemplateLiteralTstNode::SINGLE_QUOTE )
        {
            $text = ezcTemplateStringTool::processSingleQuotedEscapes( $type->value );
        }
        elseif( $type->quoteType == ezcTemplateLiteralTstNode::DOUBLE_QUOTE )
        {
            $text = ezcTemplateStringTool::processDoubleQuotedEscapes( $type->value );
        }
        else
        {
            // Numbers
            $text = $type->value;
        }

        return new ezcTemplateLiteralAstNode( $text );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitLiteralArrayTstNode( ezcTemplateLiteralArrayTstNode $type )
    {
        $astVal = array();
        foreach ( $type->value as $key => $val )
        {
            $astVal[ $key ] = $val->accept( $this );
        }

        $astKeys = array();
        foreach ( $type->keys as $key => $val )
        {
            $astKeys[ $key ] = $val->accept( $this );
        }


        $ast = new ezcTemplateLiteralArrayAstNode();
        $ast->value = $astVal;
        $ast->keys = $astKeys;

        return $ast;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitIdentifierTstNode( ezcTemplateIdentifierTstNode $type )
    {
        $newNode = new ezcTemplateIdentifierAstNode( $type->value );
        return $newNode; 
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitVariableTstNode( ezcTemplateVariableTstNode $type )
    {

        $symbolType = $this->parser->symbolTable->retrieve( $type->name );
        if (  $symbolType == ezcTemplateSymbolTable::IMPORT) 
        {
            $newName = "this->send->" . $type->name;
            $this->parser->symbolTable->enter( $newName, $symbolType, true );
            return $this->createVariableNode( "this->send->" . $type->name );
        }

        if ( !$this->noProperty && $this->parser->symbolTable->retrieve( $type->name ) == ezcTemplateSymbolTable::CYCLE ) 
        {
            $this->isCycle = true;
            return $this->createTemplateVariableNode( $type->name . "->v" );
        }

        return $this->createTemplateVariableNode( $type->name );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitTextBlockTstNode( ezcTemplateTextBlockTstNode $type )
    {
        // @todo This should be handled by a more generic TST optimizer
        // Check for empty texts, there is no need to generate AST nodes
        // for them
        if ( strlen( $type->text ) == 0 )
        {
            return new ezcTemplateNopAstNode();
        }

        return $this->assignToOutput( new ezcTemplateLiteralAstNode( $type->text ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitFunctionCallTstNode( ezcTemplateFunctionCallTstNode $type )
    {
        if ( $this->isFunctionFromObject )
        {
            // The function call method is not allowed. Throw an exception.
            throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_OBJECT_FUNCTION_CALL_NOT_ALLOWED );

            // The code below is never reached. However if you remove the exception above,
            // you can call object methods.
            $p = array();
            foreach ( $type->parameters as $parameter )
            {
                $p[] = $parameter->accept( $this );
            }
            
            $tf = new ezcTemplateFunctionCallAstNode( $type->name, $p );


            $tf->typeHint = ezcTemplateAstNode::TYPE_ARRAY | ezcTemplateAstNode::TYPE_VALUE;

            return $tf;
        }

        $paramAst = array();
        foreach ( $type->parameters as $name => $parameter )
        {
            $paramAst[$name] = $parameter->accept( $this );
        }

        try
        {
            return $this->functions->getAstTree( $type->name, $paramAst );
        }
        catch ( Exception $e )
        {
            throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->startCursor, $e->getMessage() ); 
        }
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitDocCommentTstNode( ezcTemplateDocCommentTstNode $type )
    {
        return new ezcTemplateBlockCommentAstNode ( $type->commentText );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitForeachLoopTstNode( ezcTemplateForeachLoopTstNode $type )
    {
        $this->delimCounterVar->push( $this->getUniqueVariableName( "delim" ) );
        $this->delimOutputVar->push( $this->getUniqueVariableName( "delimOut" ) );

        // Define the variable, _ezcTemplate_limit and set it to 0.
        $limitVar = null;

        // Process body.
        $body = $this->createBody( $type->elements );
        $astNode = array();
        $i = 0;

        if ( $type->limit !== null )
        {
            $limitVar = $this->createVariableNode( $this->getUniqueVariableName( "limit" ) );

            $assign = new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode( 
                    $limitVar, new ezcTemplateLiteralAstNode( 0 ) ) );

            $astNode[$i++] = $assign;
        }

        $this->handleLoopInit( $astNode, $i, $body );

        $astNode[$i] = new ezcTemplateForeachAstNode();

        if ( $type->offset !== null )
        {
            $params[] = $type->array->accept( $this );
            if ( !( $params[ sizeof( $params ) - 1 ]->typeHint & ezcTemplateAstNode::TYPE_ARRAY) )
            {
                throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_ARRAY );
            }

            $params[] = $type->offset->accept( $this );

            $astNode[$i]->arrayExpression = $this->functions->getAstTree( "array_remove_first", $params );
        }
        else
        {
            $astNode[$i]->arrayExpression = $type->array->accept( $this );

            if ( !( $astNode[$i]->arrayExpression->typeHint & ezcTemplateAstNode::TYPE_ARRAY) )
            {
                throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_ARRAY );
            }
        }

        if ( $type->keyVariableName  !== null )
        {
            $astNode[$i]->keyVariable = $this->createTemplateVariableNode( $type->keyVariableName );
            $this->declaredVariables[$type->keyVariableName] = true;
        }

        $astNode[$i]->valueVariable = $this->createTemplateVariableNode( $type->itemVariableName );
        $this->declaredVariables[$type->itemVariableName] = true;

        $astNode[$i]->body = $body;

        // Increment by one, and do the limit check.
        if ( $type->limit !== null )
        {
            $inc = new ezcTemplateIncrementOperatorAstNode( true );
            $inc->appendParameter( $limitVar );

            $astNode[$i]->body->statements[] = new ezcTemplateGenericStatementAstNode( $inc );

            $eq = new ezcTemplateEqualOperatorAstNode();
            $eq->appendParameter( $limitVar );
            $eq->appendParameter( $type->limit->accept( $this ) );

            $if = new ezcTemplateIfAstNode();
            $cb = new ezcTemplateConditionBodyAstNode();
            $cb->condition = $eq;
            $cb->body = new ezcTemplateBreakAstNode();
            $if->conditions[] = $cb;

            $astNode[$i]->body->statements[] = $if;
        }

        // Increment cycle.
        foreach ( $type->increment as $var )
        {
                $fc = new ezcTemplateFunctionCallAstNode( "increment", array() );
                $fc->typeHint = ezcTemplateAstNode::TYPE_ARRAY | ezcTemplateAstNode::TYPE_VALUE;

                $astNode[$i]->body->statements[] = new ezcTemplateGenericStatementAstNode( new ezcTemplateReferenceOperatorAstNode( $this->createTemplateVariableNode( $var->name ), $fc ) );
        }

        // Decrement cycle.
        foreach ( $type->decrement as $var )
        {
                $fc = new ezcTemplateFunctionCallAstNode( "decrement", array() );
                $fc->typeHint = ezcTemplateAstNode::TYPE_ARRAY | ezcTemplateAstNode::TYPE_VALUE;

                $astNode[$i]->body->statements[] = new ezcTemplateGenericStatementAstNode( new ezcTemplateReferenceOperatorAstNode( $this->createTemplateVariableNode( $var->name ), $fc ) );
        }

        // Restore previous delimiter variables
        $this->delimOutputVar->pop();
        $this->delimCounterVar->pop();

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitDelimiterTstNode( ezcTemplateDelimiterTstNode $type ) 
    {
        // The new output will be set to  the delimiter output variable
        // (created by foreach/while)
        $this->outputVariable->push( $this->delimOutputVar->getName(),
                                     $this->delimOutputVar->getAst() );

        if ( $type->modulo === null )
        {
            $body = $this->createBody( $type->children );

            // Restore the output variable
            $this->outputVariable->pop();

            return array( new ezcTemplateGenericStatementAstNode( $body, false ) );
        }

        // if ( $counter % $modulo == $rest )
        $mod = new ezcTemplateModulusOperatorAstNode();
        $mod->appendParameter( $this->delimCounterVar->getAst() );
        $mod->appendParameter( $type->modulo->accept( $this ) );

        $eq = new ezcTemplateEqualOperatorAstNode();
        $eq->appendParameter( $mod );
        $eq->appendParameter( $type->rest->accept( $this ) );

        $if = new ezcTemplateIfAstNode();
        $cb = new ezcTemplateConditionBodyAstNode();
        $cb->condition = $eq;

        $cb->body = $this->createBody( $type->children );
        $if->conditions[] = $cb;

        // Restore the output variable
        $this->outputVariable->pop();

        return array( $if );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitWhileLoopTstNode( ezcTemplateWhileLoopTstNode $type )
    {
        $this->delimCounterVar->push( $this->getUniqueVariableName( "delim" ) );
        $this->delimOutputVar->push( $this->getUniqueVariableName( "delimOut" ) );

        $body = $this->createBody( $type->elements );
        $astNode = array();
        $i = 0;

        $this->handleLoopInit( $astNode, $i, $body );

        $astNode[$i] = new ezcTemplateWhileAstNode();

        $cb = new ezcTemplateConditionBodyAstNode();
        $cb->condition = $type->condition->accept( $this );
        $cb->body = $body;

        $astNode[$i]->conditionBody = $cb;

        // Restore previous delimiter variables
        $this->delimOutputVar->pop();
        $this->delimCounterVar->pop();

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitIfConditionTstNode( ezcTemplateIfConditionTstNode $type )
    {
        $astNode = new ezcTemplateIfAstNode();

        $i = 0;
        foreach ( $type->children as $child )
        {
            $astNode->conditions[$i++] = $child->accept( $this );
        }

        // $cb = $type->children[0]->accept( $this );
        // $astNode->conditions[0] = $cb;

        /*

        // First condition, the 'if'.
        $if = new ezcTemplateConditionBodyAstNode();
        $if->condition = null; // $type->condition->accept( $this );
        $if->body = $this->addOutputNodeIfNeeded( $type->children[0]->accept( $this ) );
        $astNode->conditions[0] = $if;
        */

        // Second condition, the 'elseif'.
        /*
        if ( count( $type->elements ) == 3 )
        {
            $elseif = new ezcTemplateConditionBodyAstNode();
            $elseif->body = $this->addOutputNodeIfNeeded( $type->elements[1]->accept( $this ) );
            $astNode->conditions[1] = $else;

        }
        */
/*
        if ( isset( $type->elements[1] ) )
        {
            $else = new ezcTemplateConditionBodyAstNode();
            $else->body = $this->addOutputNodeIfNeeded( $type->elements[1]->accept( $this ) );
            $astNode->conditions[2] = $else;
        }
        */

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitConditionBodyTstNode( ezcTemplateConditionBodyTstNode $type ) 
    {
        $cb = new ezcTemplateConditionBodyAstNode();
        $cb->condition = ( $type->condition !== null ? $type->condition->accept( $this ) : null );
        $cb->body = $this->createBody( $type->children );
        return $cb;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitLoopTstNode( ezcTemplateLoopTstNode $type )
    {
        if ( $type->name == "skip" )
        {
            $dec = new ezcTemplateGenericStatementAstNode( new ezcTemplateDecrementOperatorAstNode( true ) );
            $dec->expression->appendParameter( $this->delimCounterVar->getAst() );

            return array( $dec,
                          $this->delimOutputVar->getInitializationAst(),
                          new ezcTemplateContinueAstNode() );
        }
        elseif( $type->name == "continue")
        {
            return new ezcTemplateContinueAstNode();
        }
        elseif( $type->name == "break")
        {
            return new ezcTemplateBreakAstNode();
        }

        // STRANGE name
        throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->startCursor, "Unhandled loop control name: " . $type->name );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitPropertyFetchOperatorTstNode( ezcTemplatePropertyFetchOperatorTstNode $type )
    {
        return $this->appendReferenceOperatorRecursively( $type );
    }


    /**
     * @return ezcTemplateAstNode
     */
    public function visitArrayFetchOperatorTstNode( ezcTemplateArrayFetchOperatorTstNode $type )
    {
        $node = new ezcTemplateArrayFetchOperatorAstNode();
        $node->appendParameter( $type->parameters[0]->accept( $this ) );
        $node->appendParameter( $type->parameters[1]->accept( $this ) );

        $nrOfParameters = sizeof( $type->parameters );

        for( $i = 2; $i < $nrOfParameters; $i++)
        {
            $tmp = new ezcTemplateArrayFetchOperatorAstNode();
            $tmp->appendParameter( $node );
            $tmp->appendParameter( $type->parameters[$i]->accept( $this ));
            $node = $tmp;
        }

        return $node;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitArrayAppendOperatorTstNode( ezcTemplateArrayAppendOperatorTstNode $type )
    {
        if ( !$this->allowArrayAppend )
        {
            throw new ezcTemplateParserException( $type->source, $type->parameters[0]->startCursor, $type->parameters[0]->endCursor, ezcTemplateSourceToTstErrorMessages::MSG_UNEXPECTED_ARRAY_APPEND );
        }

        return new ezcTemplateArrayAppendOperatorAstNode( $type->parameters[0]->accept( $this ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitPlusOperatorTstNode( ezcTemplatePlusOperatorTstNode $type )
    {
        return new ezcTemplateParenthesisAstNode( $this->appendOperatorRecursively( $type, new ezcTemplateAdditionOperatorAstNode) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitMinusOperatorTstNode( ezcTemplateMinusOperatorTstNode $type )
    {
        return new ezcTemplateParenthesisAstNode( $this->appendOperatorRecursively( $type, new ezcTemplateSubtractionOperatorAstNode ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitConcatOperatorTstNode( ezcTemplateConcatOperatorTstNode $type )
    {
        return new ezcTemplateParenthesisAstNode( $this->appendOperatorRecursively( $type, new ezcTemplateConcatOperatorAstNode ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitMultiplicationOperatorTstNode( ezcTemplateMultiplicationOperatorTstNode $type )
    {
        return new ezcTemplateParenthesisAstNode( $this->appendOperatorRecursively( $type, new ezcTemplateMultiplicationOperatorAstNode ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitDivisionOperatorTstNode( ezcTemplateDivisionOperatorTstNode $type )
    {
        return new ezcTemplateParenthesisAstNode( $this->appendOperatorRecursively( $type, new ezcTemplateDivisionOperatorAstNode ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitModuloOperatorTstNode( ezcTemplateModuloOperatorTstNode $type )
    {
        return new ezcTemplateParenthesisAstNode( $this->appendOperatorRecursively( $type, new ezcTemplateModulusOperatorAstNode ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitEqualOperatorTstNode( ezcTemplateEqualOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateEqualOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitNotEqualOperatorTstNode( ezcTemplateNotEqualOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateNotEqualOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitIdenticalOperatorTstNode( ezcTemplateIdenticalOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateIdenticalOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitNotIdenticalOperatorTstNode( ezcTemplateNotIdenticalOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateNotIdenticalOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitLessThanOperatorTstNode( ezcTemplateLessThanOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateLessThanOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitGreaterThanOperatorTstNode( ezcTemplateGreaterThanOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateGreaterThanOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitLessEqualOperatorTstNode( ezcTemplateLessEqualOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateLessEqualOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitGreaterEqualOperatorTstNode( ezcTemplateGreaterEqualOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateGreaterEqualOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitLogicalAndOperatorTstNode( ezcTemplateLogicalAndOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateLogicalAndOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitLogicalOrOperatorTstNode( ezcTemplateLogicalOrOperatorTstNode $type )
    {
        return $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateLogicalOrOperatorAstNode() );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitAssignmentOperatorTstNode( ezcTemplateAssignmentOperatorTstNode $type )
    {
        $this->allowArrayAppend = true;
        $this->isCycle = false;

        $astNode = new ezcTemplateAssignmentOperatorAstNode(); 
        $this->previousType = $astNode; // TODO , can be removed???

        $parameters = sizeof( $type->parameters );

        $astNode->appendParameter( $type->parameters[0]->accept( $this ) ); // Set cycle, if it's a cycle.

        for ($i = 1; $i < $parameters - 1; $i++ )
        {
            $astNode->appendParameter( $type->parameters[$i]->accept( $this ) ); // Set cycle, if it's a cycle.
            $tmp = new ezcTemplateAssignmentOperatorAstNode(); 
            $tmp->appendParameter( $astNode );
            $astNode = $tmp;
        }

        $this->allowArrayAppend = false;

        $assignment = $type->parameters[$i]->accept( $this );

        if ( $this->isCycle && !( $assignment->typeHint & ezcTemplateAstNode::TYPE_ARRAY ) )
        {
            throw new ezcTemplateParserException( $type->source, $type->parameters[$i]->startCursor, 
                $type->parameters[$i]->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_ARRAY );
        }

        $astNode->appendParameter( $assignment );

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitPlusAssignmentOperatorTstNode( ezcTemplatePlusAssignmentOperatorTstNode $type )
    {
        $this->isCycle = false;
        $astNode = $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateAdditionAssignmentOperatorAstNode(), false );
        if ( $this->isCycle )
        {
            throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_INVALID_OPERATOR_ON_CYCLE );
        }

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitMinusAssignmentOperatorTstNode( ezcTemplateMinusAssignmentOperatorTstNode $type )
    {
        $this->isCycle = false;
        $astNode = $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateSubtractionAssignmentOperatorAstNode(), false );
        if ( $this->isCycle )
        {
            throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_INVALID_OPERATOR_ON_CYCLE );
        }

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitMultiplicationAssignmentOperatorTstNode( ezcTemplateMultiplicationAssignmentOperatorTstNode $type )
    {
        $this->isCycle = false;
        $astNode = $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateMultiplicationAssignmentOperatorAstNode(), false );
        if ( $this->isCycle )
        {
            throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->startCursor, ezcTemplateSourceToTstErrorMessages::MSG_INVALID_OPERATOR_ON_CYCLE );
        }

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitDivisionAssignmentOperatorTstNode( ezcTemplateDivisionAssignmentOperatorTstNode $type )
    {
        $this->isCycle = false;
        $astNode = $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateDivisionAssignmentOperatorAstNode(), false );
        if ( $this->isCycle )
        {
            throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->endCursor, ezcTemplateSourceToTstErrorMessages::MSG_INVALID_OPERATOR_ON_CYCLE );
        }

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitConcatAssignmentOperatorTstNode( ezcTemplateConcatAssignmentOperatorTstNode $type )
    {
        $this->isCycle = false;
        $astNode = $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateConcatAssignmentOperatorAstNode(), false );
        if ( $this->isCycle )
        {
            throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->endCursor, ezcTemplateSourceToTstErrorMessages::MSG_INVALID_OPERATOR_ON_CYCLE );
        }

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitModuloAssignmentOperatorTstNode( ezcTemplateModuloAssignmentOperatorTstNode $type )
    {
        $this->isCycle = false;
        $astNode = $this->createMultiBinaryOperatorAstNode( $type, new ezcTemplateModulusAssignmentOperatorAstNode(), false );
        if ( $this->isCycle )
        {
            throw new ezcTemplateParserException( $type->source, $type->startCursor, $type->endCursor, ezcTemplateSourceToTstErrorMessages::MSG_INVALID_OPERATOR_ON_CYCLE );
        }

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitPreIncrementOperatorTstNode( ezcTemplatePreIncrementOperatorTstNode $type )
    {
        // Pre increment has the parameter in the constructor set to true.
        return $this->createUnaryOperatorAstNode( $type, new ezcTemplateIncrementOperatorAstNode( true ), false );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitPreDecrementOperatorTstNode( ezcTemplatePreDecrementOperatorTstNode $type )
    {
        // Pre increment has the parameter in the constructor set to false.
        return $this->createUnaryOperatorAstNode( $type, new ezcTemplateDecrementOperatorAstNode( true ), false );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitPostIncrementOperatorTstNode( ezcTemplatePostIncrementOperatorTstNode $type )
    {
        // Post increment has the parameter in the constructor set to false.
        return $this->createUnaryOperatorAstNode( $type, new ezcTemplateIncrementOperatorAstNode( false ), false );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitPostDecrementOperatorTstNode( ezcTemplatePostDecrementOperatorTstNode $type )
    {
        // Post increment has the parameter in the constructor set to false.
        return $this->createUnaryOperatorAstNode( $type, new ezcTemplateDecrementOperatorAstNode( false ), false );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitNegateOperatorTstNode( ezcTemplateNegateOperatorTstNode $type )
    {
        // Is the minus.
        return $this->createUnaryOperatorAstNode( $type, new ezcTemplateArithmeticNegationOperatorAstNode(), true );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitLogicalNegateOperatorTstNode( ezcTemplateLogicalNegateOperatorTstNode $type )
    {
        return $this->createUnaryOperatorAstNode( $type, new ezcTemplateLogicalNegationOperatorAstNode(), true );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitBlockCommentTstNode( ezcTemplateBlockCommentTstNode $type )
    {
        throw new ezcTemplateInternalException( "The visitBlockCommentTstNode is called, however this node shouldn't be in the TST tree. It's used for testing purposes." );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitEolCommentTstNode( ezcTemplateEolCommentTstNode $type )
    {
        throw new ezcTemplateInternalException( "The visitEolCommentTstNode is called, however this node shouldn't be in the TST tree. It's used for testing purposes." );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitBlockTstNode( ezcTemplateBlockTstNode $type ) 
    {
        // Used abstract, but is parsed. Unknown.
        throw new ezcTemplateInternalException( "The visitBlockTstNode is called, however this node shouldn't be in the TST tree." );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitDeclarationTstNode( ezcTemplateDeclarationTstNode $type ) 
    {
        $this->declaredVariables[ $type->variable->name ] = true;

        if ( $this->parser->symbolTable->retrieve( $type->variable->name ) == ezcTemplateSymbolTable::CYCLE ) 
        {
            $this->noProperty = true;
            $var = $type->variable->accept( $this );
            $a = new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode( $var, new ezcTemplateNewAstNode( "ezcTemplateCycle" ) ) );
            $this->noProperty = false;

            $expression = $type->expression === null ? new ezcTemplateConstantAstNode( "NULL" ) : $type->expression->accept( $this );

            if ( $type->expression !== null && !( $expression->typeHint & ezcTemplateAstNode::TYPE_ARRAY ) )
            {
                throw new ezcTemplateParserException( $type->source, $type->expression->startCursor, $type->expression->endCursor, ezcTemplateSourceToTstErrorMessages::MSG_EXPECT_ARRAY );
            }

            $b = new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode(  $type->variable->accept( $this ), $expression ) );

            return array( $a, $b );
        }
        elseif( $this->parser->symbolTable->retrieve( $type->variable->name ) == ezcTemplateSymbolTable::IMPORT ) 
        {
            $call = new ezcTemplateFunctionCallAstNode( "isset", array( $type->variable->accept( $this ) ) );

            $if = new ezcTemplateIfAstNode();
            $cb = new ezcTemplateConditionBodyAstNode();
            $cb->condition = new ezcTemplateLogicalNegationOperatorAstNode( $call );

            if ( $type->expression === null )
            {
                $cb->body = new ezcTemplateGenericStatementAstNode( new ezcTemplateThrowExceptionAstNode( new ezcTemplateLiteralAstNode( sprintf( ezcTemplateSourceToTstErrorMessages::RT_IMPORT_VALUE_MISSING, $type->variable->name ) ) ) );
            }
            else
            {
                $expression = $type->expression->accept( $this );
                $cb->body = new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode( $type->variable->accept( $this ), $expression ) );
            }

            $if->conditions[] = $cb;
            return $if;
        }

        $expression = $type->expression === null ? new ezcTemplateConstantAstNode( "NULL" ) : $type->expression->accept( $this );
        return new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode( $type->variable->accept( $this ), $expression ) );
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitSwitchTstNode( ezcTemplateSwitchTstNode $type )
    {
        $astNode = new ezcTemplateSwitchAstNode();
        $astNode->expression = $type->condition->accept( $this );

        foreach ( $type->children as $child )
        {
            $res = $child->accept( $this );
            if ( is_array( $res ) )
            {
                foreach ( $res as $r )
                {
                    $astNode->cases[] = $r;
                }
            }
            else
            {
                $astNode->cases[] = $res;
            }
        }

        return $astNode;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitCaseTstNode( ezcTemplateCaseTstNode $type ) 
    {
        // Default.
        if ( $type->conditions === null  )
        {
            $default = new ezcTemplateDefaultAstNode();
            $default->body = $this->createBody( $type->children );
            $default->body->statements[] = new ezcTemplateBreakAstNode(); // Add break;
            return $default;
        }

        // Case, with multipe values. {case 1,2,3}, return as an array with astNodes.
        // Switch will create multiple cases: case 1: case2: case3: <my code>
        foreach ( $type->conditions as $condition )
        {
            $cb = new ezcTemplateCaseAstNode();
            $cb->match = $condition->accept( $this );
            $cb->body = new ezcTemplateBodyAstNode();

            $res[] = $cb;
        }

        $cb->body = $this->createBody( $type->children );
        $cb->body->statements[] = new ezcTemplateBreakAstNode();

        return $res;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitIncludeTstNode( ezcTemplateIncludeTstNode $type )
    {
        $ast = array();

        // $t = clone \$this->manager; 
        $ast[] = new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode( 
                $t = $this->createVariableNode( "_t" ), 
                new ezcTemplateCloneAstNode( $this->createVariableNode( "this->template" ) ) ) 
            );

        // $t->send = new ezcTemplateVariableCollection();
        $ast[] = new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode( 
                    $s = new ezcTemplateReferenceOperatorAstNode( $t, new ezcTemplateIdentifierAstNode( "send" ) ),
                    new ezcTemplateNewAstNode( "ezcTemplateVariableCollection" ) ) );

        // Send parameters
        foreach ( $type->send as $name => $expr )
        {
            if ( $expr !== null )
            {
                $rhs = $expr->accept( $this ); 
            }
            else
            {
                $symType = $this->parser->symbolTable->retrieve( $name );
                if ( $symType == ezcTemplateSymbolTable::IMPORT) 
                {
                    $rhs = $this->createVariableNode( "this->send->" . $name );
                }
               else
                {
                    $rhs = $this->createTemplateVariableNode( $name );
                }
            }

            // $t->send-><name> = <name> | send-><name>
            $ast[] = new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode( 
                        new ezcTemplateReferenceOperatorAstNode( $s, new ezcTemplateIdentifierAstNode( $name ) ), 
                        $rhs ) );
        }

        $usedConfigurationAst = $this->createVariableNode( "this->template->usedConfiguration" );
         
        // $ezcTemplate_output .= $t->process( <file> );
        $ast[] = new ezcTemplateGenericStatementAstNode( new ezcTemplateConcatAssignmentOperatorAstNode( 
            $this->outputVariable->getAst(), new ezcTemplateReferenceOperatorAstNode( $t , new ezcTemplateFunctionCallAstNode( "process", array( $type->file->accept( $this), $usedConfigurationAst ) ) ) ) );

        $r = new ezcTemplateReferenceOperatorAstNode( $t, new ezcTemplateIdentifierAstNode( "receive" ) );


        // Receive parameters
        foreach ( $type->receive as $oldName => $name )
        {
            if ( is_numeric( $oldName ) )
            {
                $oldName = $name;
            }

            $symType = $this->parser->symbolTable->retrieve( $name );
            if ( $symType == ezcTemplateSymbolTable::IMPORT) 
            {
                $varAst = $this->createVariableNode( "this->send->" . $name );
            }
            else
            {
                $varAst = $this->createTemplateVariableNode( $name );
            }
      
            $ast[] = new ezcTemplateGenericStatementAstNode( new ezcTemplateAssignmentOperatorAstNode( 
                        $varAst,
                        new ezcTemplateReferenceOperatorAstNode( $r, new ezcTemplateIdentifierAstNode( $oldName ) ) ) );
        }

        // unset ( $t );
        $ast[] = new ezcTemplateGenericStatementAstNode( new ezcTemplateFunctionCallAstNode( "unset", array( $t ) ) );

        return $ast;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitReturnTstNode( ezcTemplateReturnTstNode $type )
    {
        $astNodes = array();
        foreach ( $type->variables as $var => $expr )
        {
            $assign = new ezcTemplateAssignmentOperatorAstNode();
            $assign->appendParameter( $this->createVariableNode( "this->receive->" . $var ) );

            if ( $expr === null )
            {
               $symType = $this->parser->symbolTable->retrieve( $var );
               if ( $symType == ezcTemplateSymbolTable::IMPORT )
               {
                    $assign->appendParameter( $this->createVariableNode( "this->send->" . $var ) );
               }
               else
               {
                    $assign->appendParameter( $this->createTemplateVariableNode( $var ) );
               }
            }
            else
            {
                $assign->appendParameter( $expr->accept( $this ) );
            }

            $astNodes[] = new ezcTemplateGenericStatementAstNode( $assign );
        }

        $astNodes[] = new ezcTemplateReturnAstNode( $this->outputVariable->getAst() );
        return $astNodes;
    }

    /**
     * @return ezcTemplateAstNode
     */
    public function visitArrayRangeOperatorTstNode( ezcTemplateArrayRangeOperatorTstNode $type ) 
    {
        return $this->appendFunctionCallRecursively( $type, "array_fill_range", true );
    }

}
?>

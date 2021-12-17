<?php

declare(strict_types=1);

namespace Rector\RectorGenerator\Generator;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FindingVisitor;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

final class Context
{
    /**
     * @var array
     */
    public $usedNames = [];
    /**
     * @var array
     *
     * [
     *    'variableName' => $oldNodeWithVariableName,
     * ]
     */
    public $variableNames = [];
}


final class SimpleCodeGenerator
{
    /**
     * @param string $originalCode
     * @param string $expectedCode
     * @param int $kind
     * @return array
     */
    public function getDiffCode(string $originalCode, string $expectedCode, int $kind = ParserFactory::PREFER_PHP7): array
    {
        $parser = (new ParserFactory)->create($kind);
        try {
            $fromNodes = $parser->parse('<?php ' . $originalCode);
            $toNodes = $parser->parse('<?php ' . $expectedCode);
        } catch (PhpParser\Error $e) {
            throw new \InvalidArgumentException('Parse Error: ' . $e->getMessage());
        }

        $diff = $this->getNodeDiff($fromNodes, $toNodes);
        if(count($diff)) {
            $original = $diff[0];
            $new = $diff[1];
            echo "Hook:".get_class($original)."\n";

            $context = new Context();
            $context->usedNames = [];
            $context->variableNames = $this->getVariableNames($original);

            return $this->getPhpCode($new, $context);
        }

        return [];
    }

    private function getPathToNode(Node $node) : string
    {
        $path = [];
        while ($node) {
            $path[] = $node;
            $node = $node->getAttribute('parent');
        }
        $path = array_reverse($path);

        $pathToNode = '';
        for($i=0; $i < count($path); $i++) {
            $currentNode = $path[$i];
            $childNode = $path[$i+1] ?? null;
            if($childNode === null) {
                $pathToNode .= '->name';
                break;
            }

            foreach ($currentNode->getSubNodeNames() as $subNodeName) {
                $subNode = $currentNode->$subNodeName;

                if(is_array($subNode)) {
                    foreach ($subNode as $index => $subNodeItem) {
                        if($subNodeItem === $childNode) {
                            $pathToNode .= $subNodeName . '[' . $index . ']';
                            break;
                        }
                    }

                    continue;
                }

                if ($subNode === $path[$i+1]) {
                    $pathToNode .= '->'.$subNodeName;
                    break;
                }
            }
        }

        return $pathToNode;
    }

    /**
     * Finds all variable nodes and collects their names
     * @param Node $node
     * @return array
     */
    public function getVariableNames(Node $node): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $visitor = new FindingVisitor(function(Node $node) {
            return $node instanceof Node\Expr\Variable;
        });
        $traverser->addVisitor($visitor);
        $traverser->traverse([$node]);

        $variableNodes = [];
        foreach($visitor->getFoundNodes() as $foundNode) {
            $variableNodes[$foundNode->name] = $this->getPathToNode($foundNode);
        }

        return $variableNodes;
    }

    /**
     * Calculates the difference between two nodes.
     *
     * @param $fromNode
     * @param $toNode
     * @return array
     */
    public function getNodeDiff($fromNode, $toNode): array
    {
        if(is_array($fromNode)) {
            foreach($fromNode as $key => $value) {
                $diff = $this->getNodeDiff($value, $toNode[$key]);
                if(!empty($diff)) {
                    return $diff;
                }
            }
        }

        if(is_string($fromNode)) {
            if($fromNode !== $toNode) {
                return [$fromNode, $toNode];
            }

            return [];
        }

        if(!($fromNode instanceof Node)) {
            throw new \InvalidArgumentException('node is not an instance of Node');
        }

        if ($fromNode->getType() !== $toNode->getType()) {
            return [$fromNode, $toNode];
        }

        // check the children of the node
        foreach ($fromNode->getSubNodeNames() as $name) {
            $fromSubNode = $fromNode->$name;
            $toSubNode = $toNode->$name;
            $diff = $this->getNodeDiff($fromSubNode, $toSubNode);
            if (count($diff)) {
                return $diff;
            }
        }

        return [];
    }

    /**
     * Gets the generated PHP code for a node.
     *
     * @param Node $node
     * @param array $usedNames
     * @return array 0-variableName, 1-content
     */
    public function getPhpCode(Node $node, Context $context) : array
    {
        $content = "";
        $params = [];
        foreach ($node->getSubNodeNames() as $name)
        {
            $subNode = $node->$name;
            if(is_string($subNode)) {
                if($node instanceof Node\Expr\Variable && isset($context->variableNames[$subNode])) {
                    $params[$name] = '$node->'.$context->variableNames[$subNode];
                }
                else {
                    $params[$name] = "'$subNode'";
                }

                continue;
            }

            if ($subNode instanceof Node) {
                [$subVarName, $subContent] = $this->getPhpCode($subNode, $context);
                $content .= $subContent;
                $params[$name] = $subVarName;
                continue;
            }

            if(is_array($subNode)) {
                // TODO: handle arrays
                if($name == "args") {
                    $argVariables = [];
                    foreach($subNode as $arg) {
                        [$subVarName, $subContent] = $this->getPhpCode($arg, $context);
                        $argVariables[] = $subVarName;
                        $content .= $subContent;
                    }

                    $params[$name] = "[ ".implode(',', $argVariables)." ]";
                }

                if($name == "parts") {
                    $params[$name] = "'".implode('\\', $subNode)."'";
                }
            }
        }

        $class = get_class($node);
        $varName = '$'. $this->getVariableName(lcfirst(basename(str_replace('\\','/', $class))), $context->usedNames);
        $content .= "$varName = new \\$class(".implode(', ', $params).");\n";

        return [$varName, $content];
    }

    private function getVariableName($requestedName, array &$usedNames) : string
    {
        if(!isset($usedNames[$requestedName])) {
            $usedNames[$requestedName] = 0;
            return $requestedName;
        }

        return $requestedName . (++$usedNames[$requestedName]);
    }
}

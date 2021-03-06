<?php

/**
 * This file is part of the FOSElasticaBundle project.
 *
 * (c) FriendsOfSymfony <https://github.com/FriendsOfSymfony/FOSElasticaBundle/graphs/contributors>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\ElasticaBundle\Provider;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * class for indexing elastica documents
 */
class Indexable implements IndexableInterface
{
    /**
     * An array of raw configured callbacks for all types.
     *
     * @var array
     */
    private $callbacks = array();

    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface
     */
    private $container;

    /**
     * An instance of ExpressionLanguage.
     *
     * @var ExpressionLanguage
     */
    private $expressionLanguage;

    /**
     * An array of initialised callbacks.
     *
     * @var array
     */
    private $initialisedCallbacks = array();

    /**
     * PropertyAccessor instance.
     *
     * @var PropertyAccessorInterface
     */
    private $propertyAccessor;

    /**
     * @param array              $indexCallbacks
     * @param array              $updateCallbacks
     * @param ContainerInterface $container
     */
    public function __construct(array $indexCallbacks, ContainerInterface $container, array $updateCallbacks = array())
    {
        $this->callbacks = array(
            'index' => $indexCallbacks,
            'update' => $updateCallbacks,
        );
        $this->container = $container;
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    /**
     * Return callback result for index and type, default result is true.
     *
     * @param string $indexName
     * @param string $typeName
     * @param mixed  $object
     * @param string $callbackType
     *
     * @return bool|string
     */
    private function getCallbackResult($indexName, $typeName, $object, $callbackType)
    {
        $type = sprintf('%s/%s', $indexName, $typeName);
        $callback = $this->getCallback($type, $object, $callbackType);
        if (!$callback) {
            return true;
        }

        if ($callback instanceof Expression) {
            return (bool) $this->getExpressionLanguage()->evaluate($callback, array(
                'object' => $object,
                $this->getExpressionVar($object) => $object,
            ));
        }

        return is_string($callback)
            ? call_user_func(array($object, $callback))
            : call_user_func($callback, $object);
    }

    /**
     * Return whether the object is indexable with respect to the callback.
     *
     * @param string $indexName
     * @param string $typeName
     * @param mixed  $object
     *
     * @return bool
     */
    public function isObjectIndexable($indexName, $typeName, $object)
    {
        $result = $this->getCallbackResult($indexName, $typeName, $object, 'index');
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function isObjectNeedUpdate($indexName, $typeName, $object)
    {
        $result = $this->getCallbackResult($indexName, $typeName, $object, 'update');
        return $result;
    }

    /**
     * Builds and initialises a callback.
     *
     * @param string $type
     * @param object $object
     * @param string $callbackType
     *
     * @return mixed
     */
    private function buildCallback($type, $object, $callbackType = 'index')
    {
        if (!isset($this->callbacks[$callbackType][$type])) {
            return null;
        }

        $callback = $this->callbacks[$callbackType][$type];

        if (is_callable($callback) || is_callable(array($object, $callback))) {
            return $callback;
        }

        if (is_array($callback) && !is_object($callback[0])) {
            return $this->processArrayToCallback($type, $callback);
        }

        if (is_string($callback)) {
            return $this->buildExpressionCallback($type, $object, $callback);
        }

        throw new \InvalidArgumentException(sprintf('Callback for type "%s" is not a valid callback.', $type));
    }

    /**
     * Processes a string expression into an Expression.
     *
     * @param string $type
     * @param mixed $object
     * @param string $callback
     *
     * @return Expression
     */
    private function buildExpressionCallback($type, $object, $callback)
    {
        $expression = $this->getExpressionLanguage();
        if (!$expression) {
            throw new \RuntimeException('Unable to process an expression without the ExpressionLanguage component.');
        }

        try {
            $callback = new Expression($callback);
            $expression->compile($callback, array(
                'object', $this->getExpressionVar($object)
            ));

            return $callback;
        } catch (SyntaxError $e) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Callback for type "%s" is an invalid expression',
                    $type
                ), $e->getCode(), $e);
        }
    }

    /**
     * Returns the ExpressionLanguage class if it is available.
     *
     * @return ExpressionLanguage|null
     */
    private function getExpressionLanguage()
    {
        if (null === $this->expressionLanguage && class_exists('Symfony\Component\ExpressionLanguage\ExpressionLanguage')) {
            $this->expressionLanguage = new ExpressionLanguage();
        }

        return $this->expressionLanguage;
    }
    
    /**
     * Retreives a cached callback, or creates a new callback if one is not found.
     *
     * @param string $type
     * @param object $object
     * @param string $callbackType
     *
     * @return mixed
     */
    private function getCallback($type, $object, $callbackType = 'index')
    {
        if (!isset($this->initialisedCallbacks[$callbackType][$type])) {
            $this->initialisedCallbacks[$callbackType][$type] = $this->buildCallback($type, $object, $callbackType);
        }

        return $this->initialisedCallbacks[$callbackType][$type];
    }


    /**
     * Returns the variable name to be used to access the object when using the ExpressionLanguage
     * component to parse and evaluate an expression.
     *
     * @param mixed $object
     *
     * @return string
     */
    private function getExpressionVar($object = null)
    {
        if (!is_object($object)) {
            return 'object';
        }

        $ref = new \ReflectionClass($object);

        return strtolower($ref->getShortName());
    }

    /**
     * Processes an array into a callback. Replaces the first element with a service if
     * it begins with an @.
     *
     * @param string $type
     * @param array $callback
     * @return array
     */
    private function processArrayToCallback($type, array $callback)
    {
        list($class, $method) = $callback + array(null, '__invoke');

        if (strpos($class, '@') === 0) {
            $service = $this->container->get(substr($class, 1));
            $callback = array($service, $method);

            if (!is_callable($callback)) {
                throw new \InvalidArgumentException(sprintf(
                    'Method "%s" on service "%s" is not callable.',
                    $method,
                    substr($class, 1)
                ));
            }

            return $callback;
        }

        throw new \InvalidArgumentException(sprintf(
            'Unable to parse callback array for type "%s"',
            $type
        ));
    }
}

<?php

namespace Mpociot\ApiDoc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\DocBlock;
use ReflectionClass;

class ApiDocGenerator
{

    /**
     * @param Route $route
     * @return array
     */
    public function processRoute(Route $route)
    {
        $routeAction = $route->getAction();
        $response = $this->getRouteResponse($route);
        $routeDescription = $this->getRouteDescription($routeAction['uses']);
        $routeData = [
            'title' => $routeDescription['short'],
            'description' => $routeDescription['long'],
            'methods' => $route->getMethods(),
            'uri' => $route->getUri(),
            'parameters' => [],
            'response' => ($response->headers->get('Content-Type') === 'application/json') ? json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT) : $response->getContent()
        ];

        $validator = Validator::make([], $this->getRouteRules($routeAction['uses']));
        foreach ($validator->getRules() as $attribute => $rules) {
            $attributeData = [
                'required' => false,
                'type' => 'string',
                'default' => '',
                'description' => []
            ];
            foreach ($rules as $rule) {
                $this->parseRule($rule, $attributeData);
            }
            $routeData['parameters'][$attribute] = $attributeData;
        }

        return $routeData;
    }

    /**
     * @param \Illuminate\Routing\Route $route
     * @return \Illuminate\Http\Response
     */
    private function getRouteResponse(Route $route)
    {
        $methods = $route->getMethods();
        $response = $this->callRoute(array_shift($methods), $route->getUri());
        return $response;
    }

    /**
     * @param $route
     * @return string
     */
    private function getRouteDescription($route)
    {
        list($class, $method) = explode('@', $route);
        $reflection = new ReflectionClass($class);
        $reflectionMethod = $reflection->getMethod($method);

        $comment = $reflectionMethod->getDocComment();
        $phpdoc = new DocBlock($comment);
        return [
            'short' => $phpdoc->getShortDescription(),
            'long' => $phpdoc->getLongDescription()->getContents()
        ];
    }


    /**
     * @param $route
     * @return array
     */
    private function getRouteRules($route)
    {
        list($class, $method) = explode('@', $route);
        $reflection = new ReflectionClass($class);
        $reflectionMethod = $reflection->getMethod($method);

        foreach ($reflectionMethod->getParameters() as $parameter) {
            $parameterType = $parameter->getType();
            if (!is_null($parameterType) && class_exists($parameterType)) {
                $className = $parameterType->__toString();
                $parameterReflection = new $className;
                if ($parameterReflection instanceof FormRequest) {
                    if (method_exists($parameterReflection, 'validator')) {
                        return $parameterReflection->validator()->getRules();
                    } else {
                        return $parameterReflection->rules();
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param $arr
     * @param $first
     * @param $last
     * @return string
     */
    protected function fancyImplode($arr, $first, $last)
    {
        $arr = array_map(function ($value) {
            return '`' . $value . '`';
        }, $arr);
        array_push($arr, implode($last, array_splice($arr, -2)));
        return implode($first, $arr);
    }

    /**
     * @param $rule
     * @param $attributeData
     */
    protected function parseRule($rule, &$attributeData)
    {
        $parsedRule = $this->parseStringRule($rule);
        $parsedRule[0] = $this->normalizeRule($parsedRule[0]);
        list($rule, $parameters) = $parsedRule;

        switch ($rule) {
            case 'required':
                $attributeData['required'] = true;
                break;
            case 'accepted':
                $attributeData['type'] = 'boolean';
                break;
            case 'after':
                $attributeData['type'] = 'date';
                $attributeData['description'][] = 'Must be a date after: `' . date(DATE_RFC850, strtotime($parameters[0])) . '`';
                break;
            case 'alpha':
                $attributeData['description'][] = 'Only alphabetic characters allowed';
                break;
            case 'alpha_dash':
                $attributeData['description'][] = 'Allowed: alpha-numeric characters, as well as dashes and underscores.';
                break;
            case 'alpha_num':
                $attributeData['description'][] = 'Only alpha-numeric characters allowed';
                break;
            case 'in':
                $attributeData['description'][] = $this->fancyImplode($parameters, ', ', ' or ');
                break;
            case 'not_in':
                $attributeData['description'][] = 'Not in: ' . $this->fancyImplode($parameters, ', ', ' or ');
                break;
            case 'min':
                $attributeData['description'][] = 'Minimum: `' . $parameters[0] . '`';
                break;
            case 'max':
                $attributeData['description'][] = 'Maximum: `' . $parameters[0] . '`';
                break;
            case 'between':
                $attributeData['type'] = 'numeric';
                $attributeData['description'][] = 'Between: `' . $parameters[0] . '` and `' . $parameters[1] . '`';
                break;
            case 'before':
                $attributeData['type'] = 'date';
                $attributeData['description'][] = 'Must be a date preceding: `' . date(DATE_RFC850, strtotime($parameters[0])) . '`';
                break;
            case 'date_format':
                $attributeData['type'] = 'date';
                $attributeData['description'][] = 'Date format: `' . $parameters[0] . '`';
                break;
            case 'different':
                $attributeData['description'][] = 'Must have a different value than parameter: `' . $parameters[0] . '`';
                break;
            case 'digits':
                $attributeData['type'] = 'numeric';
                $attributeData['description'][] = 'Must have an exact length of `' . $parameters[0] . '`';
                break;
            case 'digits_between':
                $attributeData['type'] = 'numeric';
                $attributeData['description'][] = 'Must have a length between `' . $parameters[0] . '` and `' . $parameters[1] . '`';
                break;
            case 'image':
                $attributeData['description'][] = 'Must be an image (jpeg, png, bmp, gif, or svg)';
                break;
            case 'json':
                $attributeData['type'] = 'string';
                $attributeData['description'][] = 'Must be a valid JSON string.';
                break;
            case 'mimetypes':
            case 'mimes':
                $attributeData['description'][] = 'Allowed mime types: ' . $this->fancyImplode($parameters, ', ', ' or ');
                break;
            case 'required_if':
                $attributeData['description'][] = 'Required if `' . $parameters[0] . '` is `' . $parameters[1] . '`';
                break;
            case 'required_unless':
                $attributeData['description'][] = 'Required unless `' . $parameters[0] . '` is `' . $parameters[1] . '`';
                break;
            case 'required_with':
                $attributeData['description'][] = 'Required if the parameters ' . $this->fancyImplode($parameters, ', ', ' or ') . ' are present.';
                break;
            case 'required_with_all':
                $attributeData['description'][] = 'Required if the parameters ' . $this->fancyImplode($parameters, ', ', ' and ') . ' are present.';
                break;
            case 'required_without':
                $attributeData['description'][] = 'Required if the parameters ' . $this->fancyImplode($parameters, ', ', ' or ') . ' are not present.';
                break;
            case 'required_without_all':
                $attributeData['description'][] = 'Required if the parameters ' . $this->fancyImplode($parameters, ', ', ' and ') . ' are not present.';
                break;
            case 'same':
                $attributeData['description'][] = 'Must be the same as `' . $parameters[0] . '`';
                break;
            case 'size':
                $attributeData['description'][] = 'Must have the size of `' . $parameters[0] . '`';
                break;
            case 'timezone':
                $attributeData['description'][] = 'Must be a valid timezone identifier';
                break;
            case 'exists':
                $attributeData['description'][] = 'Valid ' . Str::singular($parameters[0]) . ' ' . $parameters[1];
                break;
            case 'active_url':
                $attributeData['type'] = 'url';
                break;
            case 'regex':
                $attributeData['type'] = 'string';
                $attributeData['description'][] = 'Must match this regular expression: `' . $parameters[0] . '`';
                break;
            case 'boolean':
            case 'array':
            case 'date':
            case 'email':
            case 'image':
            case 'string':
            case 'integer':
            case 'numeric':
            case 'url':
            case 'ip':
                $attributeData['type'] = $rule;
                break;
        }
    }

    /**
     * Call the given URI and return the Response.
     *
     * @param  string $method
     * @param  string $uri
     * @param  array $parameters
     * @param  array $cookies
     * @param  array $files
     * @param  array $server
     * @param  string $content
     * @return \Illuminate\Http\Response
     */
    public function callRoute($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $kernel = App::make('Illuminate\Contracts\Http\Kernel');
        App::instance('middleware.disable', true);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ];

        $request = Request::create(
            $uri, $method, $parameters,
            $cookies, $files, $this->transformHeadersToServerVars($server), $content
        );

        $response = $kernel->handle($request);

        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * Transform headers array to array of $_SERVER vars with HTTP_* format.
     *
     * @param  array $headers
     * @return array
     */
    protected function transformHeadersToServerVars(array $headers)
    {
        $server = [];
        $prefix = 'HTTP_';

        foreach ($headers as $name => $value) {
            $name = strtr(strtoupper($name), '-', '_');

            if (!starts_with($name, $prefix) && $name != 'CONTENT_TYPE') {
                $name = $prefix . $name;
            }

            $server[$name] = $value;
        }

        return $server;
    }

    /**
     * Parse a string based rule.
     *
     * @param  string $rules
     * @return array
     */
    protected function parseStringRule($rules)
    {
        $parameters = [];

        // The format for specifying validation rules and parameters follows an
        // easy {rule}:{parameters} formatting convention. For instance the
        // rule "Max:3" states that the value may only be three letters.
        if (strpos($rules, ':') !== false) {
            list($rules, $parameter) = explode(':', $rules, 2);

            $parameters = $this->parseParameters($rules, $parameter);
        }

        return [strtolower(trim($rules)), $parameters];
    }

    /**
     * Parse a parameter list.
     *
     * @param  string $rule
     * @param  string $parameter
     * @return array
     */
    protected function parseParameters($rule, $parameter)
    {
        if (strtolower($rule) == 'regex') {
            return [$parameter];
        }

        return str_getcsv($parameter);
    }

    /**
     * Normalizes a rule so that we can accept short types.
     *
     * @param  string $rule
     * @return string
     */
    protected function normalizeRule($rule)
    {
        switch ($rule) {
            case 'int':
                return 'integer';
            case 'bool':
                return 'boolean';
            default:
                return $rule;
        }
    }
}
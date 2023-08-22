<?php

namespace flux711\yii2\rest_api_doc\controllers;

use flux711\yii2\rest_api_doc\ModuleAsset;
use ReflectionClass;
use Yii;
use yii\base\Controller;
use yii\base\Exception;
use yii\helpers\BaseInflector;
use yii\rest\UrlRule;

class DefaultController extends Controller
{

	public function init()
	{
		$view = $this->getView();
		ModuleAsset::register($view);
		parent::init();
	}

	public function actionIndex()
	{
		$rules = [];
		foreach(Yii::$app->urlManager->rules as $urlRule) {
			if ($urlRule instanceof UrlRule) {
				$entity = [];
				$controllerName = current($urlRule->controller);

				if (strpos($controllerName, '/')) {
					$version = substr($controllerName, 0, strpos($controllerName, '/'));
					$controller_title = substr($controllerName, strpos($controllerName, '/') + 1);
					$entity['title'] = ucfirst($controller_title).'_'.ucfirst($version);
				} else {
					$entity['title'] = ucfirst(substr($controllerName, strpos($controllerName, '/')));
				}

				$urlRuleReflection = new ReflectionClass($urlRule);
				$rulesObject = $urlRuleReflection->getProperty('rules');
				$rulesObject->setAccessible(true);
				$generatedRules = $rulesObject->getValue($urlRule);
				$entity['rules'] = $this->_processRules($generatedRules[$controllerName]);
				$rules[] = $entity;
			}
		}

		usort($rules, function($item_a, $item_b) {
			return strcmp($item_a['title'], $item_b['title']);
		});

		return $this->render('index', [
			'rules' => $rules,
		]);
	}

	function _processRules($generatedRules)
	{
		$rules = [];
		foreach($generatedRules as $generatedRule) {
			$reflectionObject = new ReflectionClass($generatedRule);
			$templateObject = $reflectionObject->getProperty('_template');
			$templateObject->setAccessible(true);
			if (empty($generatedRule->verb)) {
				continue;
			}
			$rule = [];
			$rule['url'] = str_replace(['<', '>'], ['{', '}'], rtrim($templateObject->getValue($generatedRule), '/'));
			$rule['method'] = current($generatedRule->verb);
			preg_match_all('/\{[^}]*\}/', $rule['url'], $matched);

			$params = [];
			if (!empty($matched[0])) {
				foreach($matched[0] as $key) {
					$name = str_replace(['{', '}'], '', $key);
					$params[] = [
						'key' => $key,
						'name' => $name,
						'title' => $name == 'id' ? 'ID' : ucfirst(str_replace('_', ' ', $name)),
					];
				}
			}

			$rule['params'] = $params;

			[$controller, $actionID] = \Yii::$app->createController($generatedRule->route);

			$rest_parameters = ['Rest Fields', 'Rest Filters', 'Rest Expand', 'Rest Description'];
			$combined_rest_parameters = array_map(function($param) {
				return $param.':';
			}, $rest_parameters);
			$combined_rest_parameters = implode('|', $combined_rest_parameters);
			$pattern = "([\s\S]*?)(?=".$combined_rest_parameters."|\/|$)";

			try {
				$methodName = 'action'.BaseInflector::id2camel($actionID);
				$controllerReflection = new \ReflectionClass($controller);
				$methodInfo = $controllerReflection->getMethod($methodName);

				$fieldsString = $this->_findString($methodInfo->getDocComment(), 'Rest Fields', $pattern);
				if ($fieldsString['full']) {
					$fieldsString = rtrim($fieldsString['full'], '.');
					$fieldsOptions = explode('||', $fieldsString);
					foreach($fieldsOptions as $fieldsOption) {
						eval('$rule[\'fields\'][] = '.$fieldsOption.';');
					}
				}

				$rule['filters'] = $this->_findElements($methodInfo->getDocComment(), 'Rest Filters', $pattern);
				$rule['expand'] = $this->_findElements($methodInfo->getDocComment(), 'Rest Expand', $pattern);
				$rule['description'] = $this->_findString($methodInfo->getDocComment(), 'Rest Description', $pattern);
			} catch (\ReflectionException $e) {
				// Silence exceptions on not existing routes
			}

			if (!empty($rule['fields'])) {
				$rule['fields'] = $this->_fieldsFlip($rule['fields']);
			}

			$rules[] = $rule;
		}

		usort($rules, function($a, $b) {
			return strcmp($a['url'], $b['url']);
		});

		return $rules;
	}

	function _fieldsFlip($fields)
	{
		$flipped = [];
		foreach($fields as $key => $field) {
			if (is_array($field)) {
				$flipped[$key] = $this->_fieldsFlip($field);
			} else {
				if (substr($field, 0, 1) == '_') {
					$field = substr($field, 1);
				}
				$flipped[$field] = '';
			}
		}
		return $flipped;
	}

	function _findString($string, $title, $pattern)
	{
		$full_description = "";
		$pattern = "/".$title.":".$pattern."/";
		preg_match($pattern, str_replace('*', '', $string), $matched);
		if (!empty($matched[1])) {
			$full_description = trim($matched[1]);
		}

		$short_description = "";
		$extended_description = "";
		if ($full_description) {
			$description_sentences = explode('.', $full_description);
			if (!empty($description_sentences)) {
				$short_description = $description_sentences[0];
				// Remove the first sentence from the array
				array_shift($description_sentences);
				$extended_description = implode('. ', $description_sentences);
			}
		}

		return [
			'short' => $short_description,
			'extended' => $extended_description,
			'full' => $full_description
		];
	}

	function _findElements($string, $title, $pattern)
	{
		$elementsString = $this->_findString($string, $title, $pattern);
		$elementsString = rtrim($elementsString['full'], '.');
		$elements = [];
		if ($elementsString) {
			eval('$elements = '.$elementsString.';');
		}
		$finalElements = [];
		if (!empty($elements)) {
			foreach($elements as $element) {
				$finalElements[] = [
					'title' => ucfirst(str_replace('_', ' ', $element)),
					'key' => $element,
				];
			}
		}
		return $finalElements;
	}
}

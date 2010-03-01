<?php

/**
 * MetadataBehavior
 *
 * Model behavior to support metadata
 *
 * @package     metadata
 * @subpackage  metadata.models.behaviors
 * @license		Licensed under the MIT license: http://www.opensource.org/licenses/mit-license.php
 * @copyright	Copyright (c) 2009,2010 Joshua M. McNeese, HouseParty Inc.
 */
final class MetadataBehavior extends ModelBehavior {

	/**
	 * default config for all models
	 *
	 * @var     array
	 */
	private $_defaults = array(
		'validate' => array()
	);

	/**
	 * Contain settings indexed by model name.
	 *
	 * @var     array
	 */
	private $_settings = array();

	/**
	 * Contain validation errors per model
	 *
	 * @var     array
	 */
	private $_validationErrors = array();

	/**
	 * afterSave callback
	 *
	 * @param	object	$Model
	 * @param	boolean $created
	 * @return	void
	 */
	public function afterSave(&$Model, $created) {

		if(
			isset($Model->data[$Model->name]['Metadatum']) &&
			!empty($Model->data[$Model->name]['Metadatum'])
		) {

			return $this->setMeta($Model, $Model->data[$Model->name]['Metadatum']);

		}

		return true;

	}

	/**
	 * Parse a nested array of data into a nicely formatted threaded array.
	 *
	 * @param   array   $data
	 * @return  array
	 */
	private function _parseThreaded($data = array()) {

		$parsed = array();

		foreach($data as $datum) {

			$parsed[$datum['Metadatum']['name']] = (!empty($datum['children']))
				? $this->_parseThreaded($datum['children'])
				: $datum['Metadatum']['value'];
		}

		return $parsed;

	}

	/**
	 * "Unflattens" a delimited string into a nested array
	 *
	 * @param	string  $key
	 * @param	mixed	$val
	 * @param	string	$sep
	 * @return	array
	 */
	private function _unflatten($key, $val, $sep = '.') {

		$data  = array();
		$parts = explode($sep, $key);
		$last  = end($parts);

		reset($parts);

		$tmp =& $data;

		foreach($parts as $part) {

			if($part == $last) {

				$tmp[$part] = $val;

			} elseif(!isset($tmp[$part])) {

				$tmp[$part] = array();

			}

			$tmp =& $tmp[$part];
		}

		return $data;

	}

	/**
	 * Mapped method to retrieve metadata for an attached model.
	 *
	 * @param   object  $Model
	 * @param   mixed   $options
	 * @return  mixed
	 */
	public function getMeta(&$Model, $options = array()) {

		if (is_string($options)) {

			$options = array(
				'name' => $options
			);

		}

		$options = array_merge(array(
			'name'      => null,
			'model'     => $Model->name,
			'foreign_id'=> $Model->id
			), $options);

		if(empty($options['foreign_id'])) {

			return false;

		}

		if (empty($options['name'])) {

			$Model->Metadatum->setScope($Model->name, $Model->id);

			$all = $Model->Metadatum->find('threaded', array(
				'fields'    => array('id','parent_id', 'name','value'),
				'conditions'=> array(
					'model'     => $options['model'],
					'foreign_id'=> $options['foreign_id']
				)
			));

			if (empty($all)) {

				return null;

			}

			return $this->_parseThreaded($all);

		}

		return $Model->Metadatum->getKey($options);

	}

	/**
	 * Mapped method to find invalid metadata for an attached model.
	 *
	 * @param   object  $Model
	 * @param   array   $data
	 * @return  mixed
	 */
	public function invalidMeta(&$Model, $data = array()) {

		extract($this->__settings[$Model->alias]);

		$this->_validationErrors[$Model->name] = $errors = array();

		if(isset($validate) && !empty($validate)) {

			App::import('Core', 'Validation');

			$Validation = Validation::getInstance();
            $methods    = array_map('strtolower', get_class_methods($Model));

			foreach(Set::flatten($data, '/') as $k=>$v) {

				$rules = Set::extract("/{$k}/.", $validate);

				if(!empty($rules)) {

					foreach($rules as $ruleSet) {

                        if(!Set::numeric(array_keys($ruleSet))) {

                            $ruleSet = array($ruleSet);

                        }

                        foreach($ruleSet as $rule) {

                            if (
                                isset($rule['allowEmpty']) &&
                                $rule['allowEmpty'] === true &&
                                $v == ''
                            ) {

                                break 2;

                            }

                            if(is_array($rule['rule'])) {

                                $ruleName	= array_shift($rule['rule']);
                                $ruleParams	= $rule['rule'];

                                array_unshift($ruleParams, $v);

                            } else {

                                $ruleName	= $rule['rule'];
                                $ruleParams	= array($v);

                            }

                            $valid = true;

                            if (in_array(strtolower($ruleName), $methods)) {

                                $valid = $Model->dispatchMethod($ruleName, $ruleParams);

                            } elseif (method_exists($Validation, $ruleName)) {

                                $valid = $Validation->dispatchMethod($ruleName, $ruleParams);

                            }

                            if(!$valid) {

                                $ruleMessage = (isset($rule['message']) && !empty($rule['message']))
                                    ? __($rule['message'], true)
                                    : sprintf('%s %s', __('Not', true), __($rule, true));


                                $errors[] = $this->_unflatten($k, $ruleMessage, '/');

                                if (isset($rule['last']) && $rule['last'] === true) {

                                    break 3;

                                }

                            }

                        }

					}

				}

			}

		}

		if(empty($errors)) {

			return false;

		}

		$this->_validationErrors[$Model->name] = $errors;

		$Model->validationErrors = array_merge($Model->validationErrors, array(
			'Metadatum' => $errors
		));

		return $errors;

	}

	/**
	 * Mapped method to recover a scoped corrupted tree
	 *
	 * @param	object	$Model Model instance
	 * @param	string	$mode parent or tree
	 * @param	mixed	$missingParentAction
	 *  - 'return' to do nothing and return
	 *	- 'delete' to delete
	 *  - the id of the parent to set as the parent_id
	 * @return	boolean true on success, false on failure
	 */
	public function recoverMeta(&$Model, $mode = 'parent', $missingParentAction = null) {

		$Model->Metadatum->setScope($Model->name, $Model->id);

		return $Model->Metadatum->recover($mode, $missingParentAction);

	}

	/**
	 * Mapped method to set metadata for an attached model.
	 *
	 * @param   object  $Model
	 * @param   mixed   $options
	 * @param   mixed   $val
	 * @return  boolean
	 */
	public function setMeta(&$Model, $key = null, $val = null) {

		if(empty($Model->id) || empty($Model->name) || is_null($key)) {

			return false;

		}

		$extra = array(
			'model'     => $Model->name,
			'foreign_id'=> $Model->id
		);

		if(is_array($key)) {

			$invalid = $this->invalidMeta($Model, $key);

			return (empty($invalid))
				? $Model->Metadatum->setKey($key, $extra)
				: false;

		}

		$invalid = $this->invalidMeta($Model, $this->_unflatten($key, $val, '.'));

		return (empty($invalid))
			? $Model->Metadatum->setKey($key, $val, $extra)
			: false;

	}

	/**
	 * Initiate behavior for the model using specified settings.
	 *
	 * @param   object  $Model      Model using the behaviour
	 * @param   array   $settings   Settings to override for model.
	 * @return  void
	 */
	public function setup(&$Model, $settings = array()) {

		$this->__settings[$Model->alias] = Set::merge($this->_defaults, $settings);

		$Model->Metadatum = ClassRegistry::init('Metadata.Metadatum');

	}

	/**
	 * Mapped method to get validationErrors for a model
	 *
	 * @param   object  $Model
	 * @return  mixed
	 */
	public function validationErrorsMeta(&$Model) {

		return $this->_validationErrors[$Model->name];

	}

	/**
	 * Mapped method to set verify scoped tree for a model
	 *
	 * @param   object  $Model
	 * @return  mixed
	 */
	public function verifyMeta(&$Model) {

		$Model->Metadatum->setScope($Model->name, $Model->id);

		return $Model->Metadatum->verify();

	}

}

?>
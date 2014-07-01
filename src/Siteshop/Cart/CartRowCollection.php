<?php namespace Siteshop\Cart;

use Illuminate\Support\Collection;

class CartRowCollection extends Collection {

	/**
	 * The Eloquent model a cart is associated with
	 *
	 * @var string
	 */
	protected $associatedModel;

	/**
	 * An optional namespace for the associated model
	 *
	 * @var string
	 */
	protected $associatedModelNamespace;

	/**
	 * Constructor for the CartRowCollection
	 *
	 * @param array    $items
	 * @param string   $associatedModel
	 * @param string   $associatedModelNamespace
	 */
	public function __construct($items, $associatedModel, $associatedModelNamespace)
	{
		parent::__construct($items);

		$this->associatedModel = $associatedModel;
		$this->associatedModelNamespace = $associatedModelNamespace;
	}

	public function __get($arg)
	{
		if($this->has($arg))
		{
			return $this->get($arg);
		}

		if($arg == strtolower($this->associatedModel))
		{
			$modelInstance = $this->associatedModelNamespace ? $this->associatedModelNamespace . '\\' . $this->associatedModel : $this->associatedModel;
			$model = new $modelInstance;

			return $model->find($this->id);
		}

		return null;
	}

	public function search($search, $strict = false)
	{
		foreach($search as $key => $value)
		{
			if($key === 'options')
			{
				$found = $this->{$key}->search($value);
			}
			else
			{
				$found = ($this->{$key} === $value) ? true : false;
			}

			if( ! $found) return false;
		}

		return $found;
	}

	public function applyConditions()
	{
		$subtotal = 0;
		$discount = 0;

		dump_exit($this->get('conditions')->toArray());

		foreach($this->get('conditions') as $condition)
		{
			$subtotal = $condition->apply($this);

			$this->put($condition->target(), $subtotal);

			$discount += $condition->result();
		}

		$this->put('discount', $discount);
	}

}
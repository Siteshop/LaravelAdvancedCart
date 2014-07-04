<?php namespace Siteshop\Cart;

use Illuminate\Support\Collection;

class CartItemCollection extends Collection {

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
	 *   Order in which the conditions are applied
	 *
	 *   @var  array
	 */
	protected $conditionsOrder = ['discount', 'tax', 'shipping'];

	/**
	 * Constructor for the CartItemCollection
	 *
	 * @param array    $items
	 * @param string   $associatedModel
	 * @param string   $associatedModelNamespace
	 */
	public function __construct($items = [], $associatedModel, $associatedModelNamespace)
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

	public function disable($key)
	{
		$this->get('disable')->put($key, true);
	}

	public function enable($key)
	{
		$this->get('disable')->put($key, false);
	}

	public function getConditionsOrder()
	{
		return $this->get('conditionsOrder', $this->conditionsOrder);
	}

	public function setConditionsOrder($order)
	{
		$this->put('conditionsOrder', $order);
	}

	public function conditions($type = null)
	{
		$conditions = $this->get('conditions');

		if( ! $type )
			return $conditions;

		return $conditions->filter(function ($condition) use ($type) {
			return ($condition->get('type') === $type);
		});
	}

	public function removeConditionByName($name)
	{
		$conditions = $this->get('conditions')->filterBy(['name' => $name]);

		foreach($conditions->all() as $k => $v)
		{
			$this->get('conditions')->forget($k);
		}
	}

	public function removeConditionByType($type)
	{
		$conditions = $this->get('conditions')->filterBy(['type' => $type]);

		foreach($conditions->all() as $k => $v)
		{
			$this->get('conditions')->forget($k);
		}
	}

	public function conditionsTotal($type = null)
	{
		$conditions = array_only( $this->get('appliedConditions'), array_pluck($this->conditions($type)->toArray(), 'name') );

		return $conditions;
	}

	public function conditionsTotalSum($type = null)
	{
		return array_sum( $this->conditionsTotal($type) );
	}

	public function search($search)
	{
		foreach($search as $key => $value)
		{
			if($key === 'attributes')
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

	public function applyConditions($withDiscounts = true)
	{
		$appliedConditions = [];

		$subtotal = $this->original_price * $this->qty;

		$this->put('price', $this->original_price);
		$this->put('subtotal', $subtotal);

		$order = $this->conditionsOrder;

		$this->conditions()->sortBy( function ($condition) use ($order) {
			return ($condition->target() === 'price' ? array_search($condition->get('type'), $order) : array_search($condition->get('type'), $order) + count($order));
		});

		foreach($order as $type)
		{
			$results = 0;

			if( (($type == 'discount' && $withDiscounts) || $type != 'discount') && ! $this->dot('disable.' . $type) )
			{
				$results = 0;

				$conditions = $this->conditions($type);

				foreach($conditions as $k => $condition)
				{
					$result = 0;
					$subtotal = $condition->apply($this);

					if( ! $condition->isInclusive() )
					{
						$this->put($condition->target(), $subtotal);
						$result = $condition->result() * ($condition->target() == 'price' ?  $this->qty : 1);

						$appliedConditions[$condition->get('name')] = $result;
					}

					$results += $result;

					if( $condition->target() == 'price' )
					{
						$subtotal = $this->get('subtotal') + $result;

						$this->put('subtotal', $subtotal);
					}
				}
			}

			$this->put($type, $results);
		}

		$this->put('appliedConditions', $appliedConditions);

		return $this;
	}

	protected function dot($keys, $default = false)
	{
		$keys = explode('.', $keys);

		$collection = $this;

		foreach($keys as $key)
		{
			if($collection->has($key))
			{
				$collection = $collection->get($key);
			}
			else
			{
				return $default;
			}
		}

		return $collection;
	}

}
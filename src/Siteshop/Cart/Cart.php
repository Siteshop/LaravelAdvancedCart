<?php namespace Siteshop\Cart;

use Illuminate\Support\Collection;
use Siteshop\Conditions\Condition;

class Cart {

	/**
	 * Session class instance
	 *
	 * @var Session
	 */
	protected $session;

	/**
	 * Event class instance
	 *
	 * @var Event
	 */
	protected $event;

	/**
	 * Current cart instance
	 *
	 * @var string
	 */
	protected $instance;

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
	 *   Cart content
	 *
	 *   @var CartCollection
	 */
	protected $cart;

	/**
	 *   Order in which the conditions are applied
	 *
	 *   @var  array
	 */
	protected $conditionsOrder = ['discount', 'tax', 'shipping'];

	/**
	 * Constructor
	 *
	 * @param Session $session Session class instance
	 * @param Event   $event   Event class instance
	 */
	public function __construct($session, $event)
	{
		$this->session = $session;
		$this->event = $event;

		$this->instance = 'main';
	}

	/**
	 * Set the current cart instance
	 *
	 * @param  string $instance Cart instance name
	 * @return Cart
	 */
	public function instance($instance = null)
	{
		if(empty($instance)) throw new Exceptions\CartInstanceException;

		$this->instance = $instance;

		// Return self so the method is chainable
		return $this;
	}

	/**
	 * Set the associated model
	 *
	 * @param  string    $modelName        The name of the model
	 * @param  string    $modelNamespace   The namespace of the model
	 * @return void
	 */
	public function associate($modelName, $modelNamespace = null)
	{
		$this->associatedModel = $modelName;
		$this->associatedModelNamespace = $modelNamespace;

		if( ! class_exists($modelNamespace . '\\' . $modelName)) throw new Exceptions\CartUnknownModelException;

		// Return self so the method is chainable
		return $this;
	}

	/**
	 * Add a row to the cart
	 *
	 * @param string|Array $id      Unique ID of the item|Item formated as array|Array of items
	 * @param string 	   $name    Name of the item
	 * @param int    	   $qty     Item qty to add to the cart
	 * @param float  	   $price   Price of one item
	 * @param Array  	   $attributes Array of additional attributes, such as 'size' or 'color'
	 */
	public function add($id, $name = null, $qty = null, $price = null, $weight = null, $requires_shipping = null, Array $attributes = array(), Array $conditions = array(), Array $disable = array())
	{
		// If the first parameter is an array we need to call the add() function again
		if(is_array($id))
		{
			// And if it's not only an array, but a multidimensional array, we need to
			// recursively call the add function
			if($this->is_multi($id))
			{
				// Fire the cart.batch event
				$this->event->fire('cart.batch', $id);

				foreach($id as $item)
				{
					$attributes = array_get($item, 'attributes', array());
					$conditions = array_get($item, 'conditions', array());
					$disable = array_get($item, 'disable', array());
					$this->addRow($item['id'], $item['name'], $item['qty'], $item['price'], $item['weight'], $item['requires_shipping'], $attributes, $conditions, $disable);
				}

				return;
			}

			$attributes = array_get($id, 'attributes', array());
			$conditions = array_get($id, 'conditions', array());
			$disable = array_get($id, 'disable', array());

			// Fire the cart.add event
			$this->event->fire('cart.add', array_merge($id, array('attributes' => $attributes)));

			return $this->addRow($id['id'], $id['name'], $id['qty'], $id['price'], $id['weight'], $id['requires_shipping'], $attributes, $conditions, $disable);
		}

		// Fire the cart.add event
		$this->event->fire('cart.add', compact('id', 'name', 'qty', 'price', 'weight', 'requires_shipping', 'attributes', 'conditions', 'disable'));

		return $this->addRow($id, $name, $qty, $price, $weight, $requires_shipping, $attributes, $conditions, $disable);
	}

	/**
	 * Update the quantity of one row of the cart
	 *
	 * @param  string        $rowId       The rowid of the item you want to update
	 * @param  integer|Array $attribute   New quantity of the item|Array of attributes to update
	 * @return boolean
	 */
	public function update($rowId, $attribute)
	{
		$cart = $this->getContent();

		if( ! $this->hasRowId($rowId)) throw new Exceptions\CartItemNotFoundException;

		if(is_array($attribute))
		{
			// Fire the cart.update event
			$this->event->fire('cart.update', $rowId);

			$items = $this->updateAttribute($rowId, $attribute);
			$cart->put('items', $items);

			$this->updateCart($cart);

			return $items;
		}

		// Fire the cart.update event
		$this->event->fire('cart.update', $rowId);

		$items = $this->updateQty($rowId, $attribute);
		$cart->put('items', $items);

		$this->updateCart($cart);

		return $items;
	}

	/**
	 * Remove a row from the cart
	 *
	 * @param  string  $rowId The rowid of the item
	 * @return boolean
	 */
	public function remove($rowId)
	{
		if( ! $this->hasRowId($rowId)) throw new Exceptions\CartItemNotFoundException;

		$cart = $this->getContent();

		// Fire the cart.remove event
		$this->event->fire('cart.remove', $rowId);

		$cart->get('items')->forget($rowId);

		return $this->updateCart($cart);
	}

	/**
	 * Check if a row exists of the cart by its ID
	 *
	 * @param  string $rowId The ID of the row to fetch
	 * @return boolean
	 */
	public function exists($rowId)
	{
		$cart = $this->getContentItems();

		return $cart->has($rowId);
	}

	/**
	 * Get an item of the cart by its ID
	 *
	 * @param  string $rowId The ID of the row to fetch
	 * @return CartCollection
	 */
	public function item($rowId)
	{
		$cart = $this->getContentItems();

		return ($cart->has($rowId)) ? $cart->get($rowId) : NULL;
	}

	/**
	 * Get the cart items
	 *
	 * @return CartItemCollection
	 */
	public function items()
	{
		$cart = $this->getContentItems();

		return (empty($cart)) ? NULL : $cart;
	}

	/**
	 * Empty the cart
	 *
	 * @return boolean
	 */
	public function clear()
	{
		// Fire the cart.destroy event
		$this->event->fire('cart.destroy');

		return $this->updateCart(NULL);
	}

	/**
	 * Get the price total
	 *
	 * @return float
	 */
	public function subtotal($cart = null)
	{
		$total = 0;
		$cart = ($cart ? $cart : $this->getContent());

		if(empty($cart->get('items')))
		{
			return $total;
		}

		foreach($cart->get('items') AS $row)
		{
			$total += $row->get('subtotal', 0);
		}

		$total = max($total, 0);

		if( ! $cart->has('subtotal') )
			$cart->put('subtotal', $total);

		return $total;
	}

	public function total($cart = false)
	{
		$cart = $cart = ($cart ? $cart : $this->getContent());

		return max($cart->get('total', $cart->get('subtotal')), 0);
	}

	public function weight()
	{
		$total = 0;
		$cart = $this->getContentItems();

		if(empty($cart))
		{
			return $total;
		}

		foreach($cart AS $row)
		{
			$total += $row->total_weight;
		}

		return max($total, 0);
	}

	public function requiresShipping()
	{
		$results = $this->search(['requires_shipping' => 1]);

		if( ! $results && $this->weight() == 0 )
			return false;

		return true;
	}

	/**
	 * Get the number of items in the cart
	 *
	 * @param  boolean $totalItems Get all the items (when false, will return the number of rows)
	 * @return int
	 */
	public function count($totalItems = false)
	{
		$cart = $this->getContentItems();

		if( ! $cart )
			return 0;

		if( ! $totalItems)
		{
			return $cart->count();
		}

		$count = 0;

		foreach($cart AS $row)
		{
			$count += $row->qty;
		}

		return $count;
	}

	/**
	 * Get the total number of items in the cart
	 *
	 * @return int
	 */
	public function quantity()
	{
		return $this->count(true);
	}

	/**
	 * Search if the cart has a item
	 *
	 * @param  Array  $search An array with the item ID and optional attributes
	 * @return Array|boolean
	 */
	public function search(Array $search)
	{
		foreach($this->getContentItems() as $item)
		{
			$found = $item->search($search);

			if($found)
			{
				$rows[] = $item->rowid;
			}
		}

		return (empty($rows)) ? false : $rows;
	}

	public function condition(Condition $condition)
	{
		$cart = $this->getContent();

		$cart->get('conditions')->push($condition);

		$this->updateCart($cart);
	}

	public function getConditionsOrder()
	{
		return $this->getContent()->get('conditionsOrder', $this->conditionsOrder);
	}

	public function setConditionsOrder($order)
	{
		$cart = $this->getContent();

		$cart->put('conditionsOrder', $order);

		$this->updateCart($cart);
	}

	public function setItemsConditionsOrder($order)
	{
		$cart = $this->getContent();

		foreach($cart->get('items') as $item)
		{
			$item->setConditionsOrder($order);
		}

		$this->updateCart($cart);
	}

	public function conditions($type = null)
	{
		$conditions = $this->getContentConditions();

		if( ! $type )
			return $conditions;

		return $conditions->filter(function ($condition) use ($type) {
			return ($condition->get('type') === $type);
		});
	}

	public function removeConditionByName($name)
	{
		$cart = $this->getContent();

		$conditions = $cart->get('conditions')->filterBy(['name' => $name]);

		foreach($conditions->all() as $k => $v)
		{
			$cart->get('conditions')->forget($k);
		}

		$this->updateCart($cart);
	}

	public function removeConditionByType($type)
	{
		$cart = $this->getContent();

		$conditions = $cart->get('conditions')->filterBy(['type' => $type]);

		foreach($conditions->all() as $k => $v)
		{
			$cart->get('conditions')->forget($k);
		}

		$this->updateCart($cart);
	}

	public function conditionsTotal($type = null)
	{
		$conditions = array_only( $this->getContent()->get('appliedConditions'), array_pluck($this->conditions($type)->toArray(), 'name') );

		return $conditions;
	}

	public function conditionsTotalSum($type = null)
	{
		return array_sum( $this->conditionsTotal($type) );
	}

	public function addBilling($billing)
	{
		$cart = $this->getContent();

		$cart->put('meta_billing', $billing);

		$this->updateCart($cart);
	}

	public function getBilling()
	{
		return $this->getContent()->get('meta_billing', []);
	}

	public function addShipping($billing)
	{
		$cart = $this->getContent();

		$cart->put('meta_shipping', $billing);

		$this->updateCart($cart);
	}

	public function getShipping()
	{
		return $this->getContent()->get('meta_shipping', []);
	}

	/**
	 * Add row to the cart
	 *
	 * @param string $id      Unique ID of the item
	 * @param string $name    Name of the item
	 * @param int    $qty     Item qty to add to the cart
	 * @param float  $price   Price of one item
	 * @param Array  $attributes Array of additional attributes, such as 'size' or 'color'
	 */
	protected function addRow($id, $name, $qty, $price, $weight, $requires_shipping, Array $attributes = array(), Array $conditions = array(), Array $disable = array())
	{
		if(empty($id) || empty($name) || empty($qty) || ! isset($price) || ! isset($weight) || ! isset($requires_shipping))
		{
			throw new Exceptions\CartMissingRequiredIndexException;
		}

		if( ! is_numeric($qty))
		{
			throw new Exceptions\CartInvalidQantityException;
		}

		if( ! is_numeric($price))
		{
			throw new Exceptions\CartInvalidPriceException;
		}

		if( ! is_numeric($weight))
		{
			throw new Exceptions\CartInvalidWeightException;
		}

		if( ! is_array($attributes))
		{
			throw new Exceptions\CartInvalidAttributesException;
		}

		if( ! is_array($conditions))
		{
			throw new Exceptions\CartInvalidConditionsException;
		}

		$cart = $this->getContent();

		$rowId = $this->generateRowId($id, $attributes);

		if($cart->get('items', new CartItemCollection([], $this->associatedModel, $this->associatedModelNamespace))->has($rowId))
		{
			$row = $cart->get('items')->get($rowId);

			$cart->put('items', $this->updateRow($rowId, array('qty' => $row->qty + $qty)));
		}
		else
		{
			$cart->put('items', $this->createRow($rowId, $id, $name, $qty, $price, $weight, $requires_shipping, $attributes, $conditions, $disable));
		}

		return $this->updateCart($cart);
	}

	/**
	 * Generate a unique id for the new row
	 *
	 * @param  string  $id      Unique ID of the item
	 * @param  Array   $attributes Array of additional attributes, such as 'size' or 'color'
	 * @return boolean
	 */
	protected function generateRowId($id, $attributes)
	{
		ksort($attributes);

		return md5($id . serialize($attributes));
	}

	/**
	 * Check if a rowid exists in the current cart instance
	 *
	 * @param  string  $id  Unique ID of the item
	 * @return boolean
	 */
	protected function hasRowId($rowId)
	{
		return $this->getContentItems()->has($rowId);
	}

	/**
	 * Update the cart
	 *
	 * @param  CartCollection  $cart The new cart content
	 * @return void
	 */
	protected function updateCart($cart)
	{
		if( $cart )
		{
			$cart = $this->applyConditions($cart);
		}

		$this->cart = $cart;

		return $this->session->put($this->getInstance(), $this->cart);
	}

	/**
	 * Get the carts content, if there is no cart content set yet, return a new empty Collection
	 *
	 * @return Illuminate\Support\Collection
	 */
	public function getContent()
	{
		if( ! $this->cart ){
			$this->cart = $this->session->has( $this->getInstance() ) ? $this->session->get( $this->getInstance() ) : new CartCollection([
				'items' => new CartCollection,
				'conditions' => new CartItemConditionsCollection
			]);
		}

		return $this->cart;
	}

	/**
	 * Get the carts content, if there is no cart content set yet, return a new empty Collection
	 *
	 * @return Illuminate\Support\Collection
	 */
	protected function getContentItems()
	{
		$content = $this->getContent();

		return $content->get('items', new CartCollection([]));
	}

	/**
	 * Get the carts content, if there is no cart content set yet, return a new empty Collection
	 *
	 * @return Illuminate\Support\Collection
	 */
	protected function getContentConditions()
	{
		$content = $this->getContent();

		return $content->get('conditions', new CartItemConditionsCollection([]));
	}

	/**
	 * Get the current cart instance
	 *
	 * @return string
	 */
	protected function getInstance()
	{
		return 'cart.' . $this->instance;
	}

	/**
	 * Update a row if the rowId already exists
	 *
	 * @param  string  $rowId The ID of the row to update
	 * @param  integer $qty   The quantity to add to the row
	 * @return Collection
	 */
	protected function updateRow($rowId, $attributes)
	{
		$cart = $this->getContentItems();

		$row = $cart->get($rowId);

		foreach($attributes as $key => $value)
		{
			if($key == 'attributes')
			{
				$attributes = $row->attributes->merge($value);
				$row->put($key, $attributes);
			}
			else
			{
				$row->put($key, $value);
			}
		}

		if( ! is_null(array_keys($attributes, array('qty', 'price', 'weight'))))
		{
			$row->put('subtotal', $row->qty * $row->price);
			$row->put('total_weight', $row->qty * $row->weight);
		}

		$cart->put($rowId, $row);

		return $cart;
	}

	/**
	 * Create a new row Object
	 *
	 * @param  string $rowId      The ID of the new row
	 * @param  string $id         Unique ID of the item
	 * @param  string $name       Name of the item
	 * @param  int    $qty        Item qty to add to the cart
	 * @param  float  $price      Price of one item
	 * @param  Array  $attributes Array of additional attributes, such as 'size' or 'color'
	 * @return Collection
	 */
	protected function createRow($rowId, $id, $name, $qty, $price, $weight, $requires_shipping, $attributes, $conditions, $disable)
	{
		$cart = $this->getContentItems();

		$newRow = new CartItemCollection(array(
			'rowid' => $rowId,
			'id' => $id,
			'name' => $name,
			'qty' => $qty,
			'price' => floatval($price),
			'original_price' => floatval($price),
			'weight' => floatval($weight),
			'total_weight' => $qty * floatval($weight),
			'requires_shipping' => $requires_shipping,
			'attributes' => new CartItemAttributesCollection($attributes),
			'conditions' => new CartItemConditionsCollection($conditions),
			'disable' => new Collection($disable),
			'subtotal' => $qty * $price
		), $this->associatedModel, $this->associatedModelNamespace);

		$cart->put($rowId, $newRow);

		return $cart;
	}

	protected function applyConditions($cart, $withDiscounts = true)
	{
		if( !$cart->get('items')->isEmpty() )
		{
			$cart = $this->applyItemsConditions($cart, true);

			$appliedConditions = [];

			$subtotal = $this->subtotal($cart);

			$cart->put('subtotal', $subtotal);
			$cart->put('total', $subtotal);

			$order = $this->getConditionsOrder();

			$this->conditions()->sortBy( function ($condition) use ($order) {
				return array_search($condition->get('type'), $order) + count($order);
			});

			foreach($order as $type)
			{
				$results = 0;

				if( ! $cart->get('disable', new Collection)->get($type, false) )
				{
					$conditions = $this->conditions($type);

					foreach($conditions as $k => $condition)
					{
						$result = 0;
						$subtotal = $condition->apply($cart);

						if( ! $condition->isInclusive() )
						{
							$cart->put($condition->target(), $subtotal);
							$result = $condition->result();

							$appliedConditions[$condition->get('name')] = $result;
						}

						$results += $result;
					}
				}

				$cart->put($type, $results);
			}

			$cart->put('appliedConditions', $appliedConditions);
		}

		return $cart;
	}

	protected function applyItemsConditions($cart, $withDiscounts = true)
	{
		foreach($cart->get('items', []) as $key => $item)
		{
			$cart->get('items')->put($key, $item->applyConditions($withDiscounts));
		}

		return $cart;
	}

	/**
	 * Update the quantity of a row
	 *
	 * @param  string $rowId The ID of the row
	 * @param  int    $qty   The qty to add
	 * @return CartCollection
	 */
	protected function updateQty($rowId, $qty)
	{
		if($qty <= 0)
		{
			return $this->remove($rowId);
		}

		return $this->updateRow($rowId, array('qty' => $qty));
	}

	/**
	 * Update an attribute of the row
	 *
	 * @param  string $rowId      The ID of the row
	 * @param  Array  $attributes An array of attributes to update
	 * @return CartCollection
	 */
	protected function updateAttribute($rowId, $attributes)
	{
		return $this->updateRow($rowId, $attributes);
	}

	/**
	 * Check if the array is a multidimensional array
	 *
	 * @param  Array   $array The array to check
	 * @return boolean
	 */
	protected function is_multi(Array $array)
	{
		return is_array(head($array));
	}

}

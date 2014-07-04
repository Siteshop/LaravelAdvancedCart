<?php namespace Siteshop\Cart;

use Illuminate\Support\Collection;

class CartItemAttributesCollection extends Collection {

	public function __construct($items)
	{
		parent::__construct($items);
	}

	public function __get($arg)
	{
		if($this->has($arg))
		{
			return $this->get($arg);
		}

		return NULL;
	}

	public function search($search)
	{
		foreach($search as $key => $value)
		{
			$found = ($this->{$key} === $value) ? true : false;

			if( ! $found) return false;
		}

		return $found;
	}

}
<?php namespace Siteshop\Cart;

use Illuminate\Support\Collection;

class CartItemConditionsCollection extends Collection {

	public function filterBy($search)
	{
		$results = array();

		foreach($this->all() as $key => $condition)
		{
			$found = true;

			foreach($search as $field => $value)
			{
				if($condition->get($field) !== $value)
				{
					$found = false;
				}
			}

			if($found)
			{
				$results[$key] = $condition;
			}
		}

		return new Collection($results);
	}

}
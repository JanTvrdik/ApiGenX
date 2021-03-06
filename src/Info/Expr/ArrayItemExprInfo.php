<?php declare(strict_types = 1);

namespace ApiGenX\Info\Expr;

use ApiGenX\Info\ExprInfo;


class ArrayItemExprInfo
{
	public function __construct(
		public ?ExprInfo $key,
		public ExprInfo $value,
	) {
	}
}

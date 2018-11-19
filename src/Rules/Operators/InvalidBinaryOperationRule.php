<?php declare(strict_types = 1);

namespace PHPStan\Rules\Operators;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ErrorType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

class InvalidBinaryOperationRule implements \PHPStan\Rules\Rule
{

	/** @var \PhpParser\PrettyPrinter\Standard */
	private $printer;

	/** @var \PHPStan\Rules\RuleLevelHelper */
	private $ruleLevelHelper;

	public function __construct(
		\PhpParser\PrettyPrinter\Standard $printer,
		RuleLevelHelper $ruleLevelHelper
	)
	{
		$this->printer = $printer;
		$this->ruleLevelHelper = $ruleLevelHelper;
	}

	public function getNodeType(): string
	{
		return Node\Expr::class;
	}

	private function getTypeCallback(\PhpParser\Node $node, TrinaryLogic $bitwiseAscii): \Closure
	{
		if ($node instanceof Node\Expr\AssignOp\Concat || $node instanceof Node\Expr\BinaryOp\Concat) {
			return static function (Type $type): bool {
				return !$type->toString() instanceof ErrorType;
			};
		}

		if (
			$node instanceof Node\Expr\AssignOp\BitwiseAnd ||
			$node instanceof Node\Expr\AssignOp\BitwiseOr ||
			$node instanceof Node\Expr\AssignOp\BitwiseXor ||
			$node instanceof Node\Expr\BinaryOp\BitwiseAnd ||
			$node instanceof Node\Expr\BinaryOp\BitwiseOr ||
			$node instanceof Node\Expr\BinaryOp\BitwiseXor
		) {
			return static function (Type $type) use ($bitwiseAscii): bool {
				return (!$bitwiseAscii->no() && $type->isSuperTypeOf(new StringType()))
					|| (!$bitwiseAscii->yes() && !$type->toNumber() instanceof ErrorType);
			};
		}

		return static function (Type $type): bool {
			return !$type->toNumber() instanceof ErrorType;
		};
	}

	/**
	 * @param \PhpParser\Node\Expr $node
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return string[]
	 */
	public function processNode(\PhpParser\Node $node, Scope $scope): array
	{
		if (
			!$node instanceof Node\Expr\BinaryOp
			&& !$node instanceof Node\Expr\AssignOp
		) {
			return [];
		}

		if ($scope->getType($node) instanceof ErrorType) {
			$leftName = '__PHPSTAN__LEFT__';
			$rightName = '__PHPSTAN__RIGHT__';
			$leftVariable = new Node\Expr\Variable($leftName);
			$rightVariable = new Node\Expr\Variable($rightName);
			if ($node instanceof Node\Expr\AssignOp) {
				$newNode = clone $node;
				$left = $node->var;
				$right = $node->expr;
				$newNode->var = $leftVariable;
				$newNode->expr = $rightVariable;
			} else {
				$newNode = clone $node;
				$left = $node->left;
				$right = $node->right;
				$newNode->left = $leftVariable;
				$newNode->right = $rightVariable;
			}

			$leftType = $this->ruleLevelHelper->findTypeToCheck(
				$scope,
				$left,
				'',
				$this->getTypeCallback($node, TrinaryLogic::createMaybe())
			)->getType();
			if ($leftType instanceof ErrorType) {
				return [];
			}

			$bitwiseAscii = TrinaryLogic::extremeIdentity(
				$leftType->isSuperTypeOf(new StringType()),
				TrinaryLogic::createFromBoolean($leftType->toNumber() instanceof ErrorType)
			);

			$rightType = $this->ruleLevelHelper->findTypeToCheck(
				$scope,
				$right,
				'',
				$callback = $this->getTypeCallback($node, $bitwiseAscii)
			)->getType();
			if ($rightType instanceof ErrorType) {
				return [];
			}

			$scope = $scope
				->assignVariable($leftName, $leftType, \PHPStan\TrinaryLogic::createYes())
				->assignVariable($rightName, $rightType, \PHPStan\TrinaryLogic::createYes());

			if (!$scope->getType($newNode) instanceof ErrorType) {
				return [];
			}

			return [
				sprintf(
					'Binary operation "%s" between %s and %s results in an error.',
					substr(substr($this->printer->prettyPrintExpr($newNode), strlen($leftName) + 2), 0, -(strlen($rightName) + 2)),
					$scope->getType($left)->describe(VerbosityLevel::value()),
					$scope->getType($right)->describe(VerbosityLevel::value())
				),
			];
		}

		return [];
	}

}

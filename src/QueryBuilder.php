<?php

namespace Asparagus;

use InvalidArgumentException;
use RangeException;

/**
 * Abstraction layer to build SPARQL queries
 *
 * Nested filters not supported
 * Supports SPARQL v1.0 (v1.1 to come)
 *
 * @since 0.1
 *
 * @license GNU GPL v2+
 * @author Bene* < benestar.wikimedia@gmail.com >
 */
class QueryBuilder {

	/**
	 * @var ExpressionValidator
	 */
	private $expressionValidator;

	/**
	 * @var UsageValidator
	 */
	private $usageValidator;

	/**
	 * @var QueryPrefixBuilder
	 */
	private $prefixBuilder;

	/**
	 * @var string[] list of expressions to select
	 */
	private $selects = array();

	/**
	 * @var string uniqueness constraint, one of DISTINCT, REDUCED or empty
	 */
	private $uniqueness = '';

	/**
	 * @var GraphBuilder
	 */
	private $graphBuilder;

	/**
	 * @var QueryModifierBuilder
	 */
	private $modifierBuilder;

	/**
	 * @var string[] $prefixes
	 * @throws InvalidArgumentException
	 */
	public function __construct( array $prefixes = array() ) {
		$this->expressionValidator = new ExpressionValidator();
		$this->usageValidator = new UsageValidator();
		$this->prefixBuilder = new QueryPrefixBuilder( $prefixes, $this->usageValidator );
		$this->graphBuilder = new GraphBuilder( $this->usageValidator );
		$this->modifierBuilder = new QueryModifierBuilder( $this->usageValidator );
	}

	/**
	 * @since 0.3
	 *
	 * @return string[] list of expressions to select
	 */
	public function getSelects() {
		return $this->selects;
	}

	/**
	 * Specifies the expressions to select.
	 *
	 * @param string|string[] $expressions
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function select( $expressions /* expressions ... */ ) {
		$expressions = is_array( $expressions ) ? $expressions : func_get_args();

		foreach ( $expressions as $expression ) {
			$this->expressionValidator->validate( $expression,
				ExpressionValidator::VALIDATE_VARIABLE | ExpressionValidator::VALIDATE_FUNCTION_AS
			);

			// @todo temp hack to add AS definitions to defined variables
			$regexHelper = new RegexHelper();
			$matches = $regexHelper->getMatches( 'AS \{variable}', $expression );
			$this->usageValidator->trackDefinedVariables( $matches );

			// @todo detect functions and wrap with brackets automatically
			$this->usageValidator->trackUsedVariables( $expression );
			$this->selects[] = $expression;
		}

		return $this;
	}

	/**
	 * Specifies the variables to select with duplicates eliminated.
	 *
	 * @param string|string[] $variables
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function selectDistinct( /* variables ... */ ) {
		call_user_func_array( array( $this, 'select' ), func_get_args() );
		$this->uniqueness = 'DISTINCT ';
		return $this;
	}

	/**
	 * Specifies the variables to select with duplicates allowed.
	 *
	 * @param string|string[] $variables
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function selectReduced( /* variables ... */ ) {
		call_user_func_array( array( $this, 'select' ), func_get_args() );
		$this->uniqueness = 'REDUCED ';
		return $this;
	}

	/**
	 * Adds the given triple as a condition.
	 *
	 * @param string $subject
	 * @param string $predicate
	 * @param string $object
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function where( $subject, $predicate, $object ) {
		$this->graphBuilder->where( $subject, $predicate, $object );
		return $this;
	}

	/**
	 * Adds the given triple/double/single value as an additional condition
	 * to the previously added condition.
	 *
	 * @param string $subject
	 * @param string|null $predicate
	 * @param string|null $object
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function also( $subject, $predicate = null, $object = null ) {
		$this->graphBuilder->also( $subject, $predicate, $object );
		return $this;
	}

	/**
	 * Adds the given expression as a filter to this query.
	 *
	 * @since 0.3
	 *
	 * @param string $expression
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function filter( $expression ) {
		$this->graphBuilder->filter( $expression );
		return $this;
	}

	/**
	 * Adds a filter that the given condition builder exists.
	 *
	 * @since 0.3
	 *
	 * @param string|GraphBuilder $subject
	 * @param string|null $predicate
	 * @param string|null $object
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function filterExists( $subject, $predicate = null, $object = null ) {
		$this->graphBuilder->filterExists( $subject, $predicate, $object );
		return $this;
	}

	/**
	 * Adds a filter that the given condition builder does not exist.
	 *
	 * @since 0.3
	 *
	 * @param string|GraphBuilder $subject
	 * @param string|null $predicate
	 * @param string|null $object
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function filterNotExists( $subject, $predicate = null, $object = null ) {
		$this->graphBuilder->filterNotExists( $subject, $predicate, $object );
		return $this;
	}

	/**
	 * Adds the given graph or triple as an optional condition.
	 *
	 * @since 0.3
	 *
	 * @param string|GraphBuilder $subject
	 * @param string|null $predicate
	 * @param string|null $object
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function optional( $subject, $predicate = null, $object = null ) {
		$this->graphBuilder->optional( $subject, $predicate, $object );
		return $this;
	}

	/**
	 * Adds the given subquery.
	 *
	 * @param QueryBuilder $query
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function subquery( QueryBuilder $query ) {
		$this->graphBuilder->subquery( $query );
		return $this;
	}

	/**
	 * Creates a new subquery builder.
	 *
	 * @return QueryBuilder
	 */
	public function newSubquery() {
		return new QueryBuilder( $this->prefixBuilder->getPrefixes() );
	}

	/**
	 * Creates a new subgraph builder.
	 *
	 * @since 0.3
	 *
	 * @return GraphBuilder
	 */
	public function newSubgraph() {
		return new GraphBuilder( $this->usageValidator );
	}

	/**
	 * Sets the GROUP BY modifier.
	 *
	 * @param string|string[] $expressions
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function groupBy( $expressions /* expressions ... */ )  {
		$expressions = is_array( $expressions ) ? $expressions : func_get_args();

		$this->modifierBuilder->groupBy( $expressions );
		return $this;
	}

	/**
	 * Sets the HAVING modifier.
	 *
	 * @param string $expression
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function having( $expression ) {
		$this->modifierBuilder->having( $expression );
		return $this;
	}

	/**
	 * Sets the ORDER BY modifier.
	 *
	 * @param string $expression
	 * @param string $direction one of ASC or DESC
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function orderBy( $expression, $direction = 'ASC' ) {
		$this->modifierBuilder->orderBy( $expression, $direction );
		return $this;
	}

	/**
	 * Sets the LIMIT modifier.
	 *
	 * @param int $limit
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function limit( $limit ) {
		$this->modifierBuilder->limit( $limit );
		return $this;
	}

	/**
	 * Sets the OFFSET modifier.
	 *
	 * @param int $offset
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function offset( $offset ) {
		$this->modifierBuilder->offset( $offset );
		return $this;
	}

	/**
	 * Returns the plain SPARQL string of this query.
	 *
	 * @param bool $includePrefixes
	 * @return string
	 * @throws InvalidArgumentException
	 * @throws RangeException
	 */
	public function getSPARQL( $includePrefixes = true ) {
		if ( !is_bool( $includePrefixes ) ) {
			throw new InvalidArgumentException( '$includePrefixes has to be a bool' );
		}

		$this->usageValidator->validate();

		$sparql = $includePrefixes ? $this->prefixBuilder->getSPARQL() : '';
		$sparql .= 'SELECT ' . $this->uniqueness . $this->formatSelects() . ' WHERE';
		$sparql .= ' {' . $this->graphBuilder->getSPARQL() . ' }';
		$sparql .= $this->modifierBuilder->getSPARQL();

		return $sparql;
	}

	private function formatSelects() {
		return empty( $this->selects ) ? '*' : implode( ' ', $this->selects );
	}

	/**
	 * @see self::getSPARQL
	 *
	 * @return string
	 */
	public function __toString() {
		return $this->getSPARQL();
	}

	/**
	 * Returns the formatted SPARQL string of this query.
	 *
	 * @see QueryFormatter::format
	 *
	 * @return string
	 */
	public function format() {
		$formatter = new QueryFormatter();
		return $formatter->format( $this->getSPARQL() );
	}

}

<?php

/**
 * A token, representing a leaf in the parse tree.
 *
 * This class represents a token that is consumed and recognized by WP_Parser.
 * In a parse tree, a token represent a leaf, that is, a node without children.
 * It is a simple generic container for a token ID and value, that can be used
 * as a base class and extended for specific use cases.
 */
class WP_Parser_Token {
	/**
	 * Token ID represented as an integer constant.
	 *
	 * @var int $id
	 */
	public $id;

	/**
	 * Token value in its original raw form.
	 *
	 * @var string
	 */
	public $value;

	/**
	 * Byte offset in the input where the token begins.
	 *
	 * @var int
	 */
	public $start;

	/**
	 * Byte length of the token in the input.
	 *
	 * @var int
	 */
	public $length;

	/**
	 * Constructor.
	 *
	 * @param int    $id      Token type.
	 * @param string $value   Token value.
	 * @param int    $start   Byte offset in the input where the token begins.
	 * @param int    $length  Byte length of the token in the input.
	 */
	public function __construct(
		int $id,
		string $value,
		int $start,
		int $length
	) {
		$this->id     = $id;
		$this->value  = $value;
		$this->start  = $start;
		$this->length = $length;
	}
}

<?php

/**
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

class ManticoreAutocompleteAdvanced extends ManticoreAutocomplete {

	private $short_field_name = '';
	/**
	 * Name of search field
	 * like (tax:NAME search_string)
	 *
	 * @var string
	 */
	protected $field_name = '';

	/**
	 * list of system taxonomies | custom fields | tag
	 * @var array
	 */
	private $type_list = [];

	private $normalized_field_name = '';


	private $is_taxonomy = false;
	private $is_custom_field = false;
	private $is_tag = false;

	private $query_words_count = 0;


	protected function correct_query(): array {

		$this->is_taxonomy = false;

		if ( strpos( $this->request['q'], 'tax:' ) === 0 ) {
			$this->is_taxonomy      = true;
			$this->type_list        = $this->config->taxonomy_names;
			$this->short_field_name = 'tax:';
		}

		if ( strpos( $this->request['q'], 'field:' ) === 0 ) {
			$this->is_custom_field  = true;
			$this->type_list        = $this->config->custom_fields_names;
			$this->short_field_name = 'field:';
		}

		if ( strpos( $this->request['q'], 'tag:' ) === 0 ) {
			$this->is_tag           = true;
			$this->type_list        = $this->config->tags_names;
			$this->short_field_name = 'tag:';
		}

		foreach ( $this->type_list as $name ) {
			if ( strpos( $this->request["q"], $this->short_field_name . $name ) !== false ) {

				$this->field_name            = $name;
				$this->normalized_field_name = $this->normalize_key( $this->field_name );

				/* if you're searching by tag(taxonomy or field) - exclude name from search request */
				$this->request["q"] = str_replace( $this->short_field_name . $name, '', $this->request["q"] );

				$explodedRequest         = explode( ' ', trim( $this->request["q"] ) );
				$this->query_words_count = count( $explodedRequest );

				if ( ! empty( $explodedRequest[0] ) ) {

					if ( ! empty( $explodedRequest ) ) {
						foreach ( $explodedRequest as $k => $item ) {
							$explodedRequest[ $k ] = $this->normalized_field_name . $item;
						}
					}
					$this->request["q"] = implode( ' ', $explodedRequest );
				}


				break;
			}
		}


		$query            = $this->escapeString( strip_tags( $this->request["q"] ) );
		$input_query      = preg_split( "/[\s|:]+/", $this->request["q"] );
		$tokenized_query  = $this->getTokenizedText( $query, false );
		$corrected_query  = array();
		$query_to_correct = $tokenized_query;

		foreach ( $query_to_correct as $i => $item ) {
			$corrected_keyword = $this->getCorrectedKeyword( $item );

			/*
			If we have searching in taxonomies, we need first add taxonomy name
			for each of word, find them and then remove taxonomy name
			*/

			/*
			if ( ! empty( $this->normalized_field_name ) ) {
				$item                   = str_replace( $this->normalized_field_name, '', $item );
				$input_query[ $i ]      = str_replace( $this->normalized_field_name, '', $input_query[ $i ] );
				$query_to_correct[ $i ] = str_replace( $this->normalized_field_name, '', $query_to_correct[ $i ] );
			} */

			if ( ! preg_match( "%" . preg_quote( $item, null ) . "%", $corrected_keyword ) ) {
				$query_to_correct[ $i ] = $corrected_keyword;
			}

			if ( $item !== $query_to_correct[ $i ] ) {
				$input_query[ $i ] = str_replace( $this->normalized_field_name, '', $input_query[ $i ] );
				if ( empty( $corrected_query ) && ! empty( $this->field_name ) ) {
					$input_query[ $i ] = $this->short_field_name . $this->field_name . ' ' . $input_query[ $i ];
				}
				$corrected_query[] = "<span class='tt-corrected'>" . $input_query[ $i ] . "</span>";
			} else {
				$input_query[ $i ] = str_replace( $this->normalized_field_name, '', $input_query[ $i ] );
				if ( empty( $corrected_query ) && ! empty( $this->field_name ) ) {
					$input_query[ $i ] = $this->short_field_name . $this->field_name . ' ' . $input_query[ $i ];
				}

				$corrected_query[] = "<span>" . $input_query[ $i ] . "</span>";
			}
		}

		$original_query = implode( " ", $tokenized_query );
		$query          = implode( " ", $query_to_correct );

		return [
			[
				"result"    => implode( " ", $corrected_query ),
				"corrected" => $query !== $original_query ? 1 : 0
			],
			$query,
			$original_query
		];
	}

	protected function get_suggests( $query, $original_query ): array {

		$suggestions        = $this->suggest( $query, true );
		$ret                = [];
		$unique_suggestions = array();
		if ( ! empty( $suggestions ) ) {
			foreach ( $suggestions as $i => $term ) {
				$tokenized_term = $this->getTokenizedText( $term );
				if ( ! empty( $unique_suggestions[ $tokenized_term ] ) || $tokenized_term === $query ) {
					continue;
				}
				$unique_suggestions[ $tokenized_term ] = true;


				/* Ending of the suggest word ( if typed "wor" h_terms can be ["ld","k"] ) */
				$h_terms = preg_split( "%(" . preg_quote( $query, null ) . ")%", $tokenized_term );

				if ( ! empty( $h_terms ) ) {

					$tokenized_term = "";
					$j              = 0;
					do {
						$j ++;
						$t         = array_shift( $h_terms );
						$t_trimmed = trim( $t );


						if ( ! empty( $t_trimmed ) ) {
							if ( ! empty( $this->field_name ) ) {

								$t = str_replace( $this->normalized_field_name, '', $t );
								if ( empty( $tokenized_term ) ) {
									$t = $this->short_field_name . $this->field_name . ' ' . $t;
								}
							}

							$tokenized_term .= "<span class='tt-suggestion-rest'>{$t}</span>";
						}
						if ( ! empty( $h_terms ) ) {
							if ( ! empty( $this->normalized_field_name ) ) {
								$query = str_replace( $this->normalized_field_name, '', $query );

								if ( empty( $tokenized_term ) ) {
									$query = $this->short_field_name . $this->field_name . ' ' . $query;
								}
							}

							if ( ( $j === 1 && $this->config->suggest_on === "edge" ) || $this->config->suggest_on === "any" ) {
								$tokenized_term .= "<span class='tt-suggestion-match'>{$query}</span>";
							} else {
								$tokenized_term .= "<span class='tt-suggestion-rest'>{$query}</span>";
							}
						}
					} while ( ! empty( $h_terms ) );
				}

				$ret[] = $tokenized_term;
			}
		}


		if ( ( $this->is_taxonomy || $this->is_custom_field || $this->is_tag ) && $this->query_words_count === 0 ) {
			/* Clear suggests (Must show only type names (tax:name, field:name ...))*/
			$ret = [];

			if ( ! empty( $this->type_list ) ) {
				$suggestions = [];
				foreach ( $this->type_list as $tax_name ) {
					$suggestions[] = $this->short_field_name . $tax_name;
				}

				$words   = explode( ' ', $this->original_query );
				$taxWord = $words[0];

				foreach ( $suggestions as $suggestion ) {
					if ( strpos( $suggestion, $taxWord ) !== false ) {
						$tokenized_term = '';
						$tokenized_term .= "<span class='tt-suggestion-rest'>{$taxWord}</span>";
						$h_terms        = preg_split( "%(" . preg_quote( $taxWord, null ) . ")%", $suggestion );
						$tokenized_term .= "<span class='tt-suggestion-match'>{$h_terms[1]}</span>";
						$ret[]          = $tokenized_term;
					} else {
						continue;
					}
				}
			}
		}

		return $ret;
	}

	/**
	 * Remove all special characters from a key
	 *
	 * @param $string
	 *
	 * @return null|string
	 */
	private function normalize_key( $string ) {
		return preg_replace( '/[^A-Za-z0-9]/', '', $string );
	}
}

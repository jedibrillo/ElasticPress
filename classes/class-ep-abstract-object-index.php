<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // exit if accessed directly
}

/**
 * Class EP_Abstract_Object_Index
 *
 * @since 1.7
 */
abstract class EP_Abstract_Object_Index implements EP_Object_Index {

	/**
	 * @var EP_API
	 * @since 1.7
	 */
	protected $api = '';

	/**
	 * @var string
	 * @since 1.7
	 */
	protected $name = '';

	/**
	 * @since 1.7
	 *
	 * @param string $name
	 * @param EP_API $api
	 */
	public function __construct( $name, $api = null ) {
		$this->name = $name;
		$this->api  = $api ? $api : EP_API::factory();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * {@inheritdoc}
	 */
	public function set_name( $name ) {
		$this->name = $name;
	}

	/**
	 * {@inheritdoc}
	 */
	public function index_document( $object, $blocking = true ) {
		/**
		 * Filter the object prior to indexing
		 *
		 * Allows for last minute indexing of object information.
		 *
		 * @since 1.7
		 *
		 * @param         array Array of object information to index.
		 */
		$object = apply_filters( "ep_pre_index_{$this->name}", $object );

		$index = untrailingslashit( ep_get_index_name() );

		$path = implode( '/', array( $index, $this->name, $this->get_object_identifier( $object ) ) );

		$request_args = array(
			'body'     => function_exists( 'wp_json_encode' ) ? wp_json_encode( $object ) : json_encode( $object ),
			'method'   => 'PUT',
			'timeout'  => 15,
			'blocking' => $blocking,
		);

		$request = ep_remote_request(
			$path,
			/**
			 * Filter the Request arguments
			 *
			 * @since 1.7
			 *
			 * @param array $request_args The request args for the remote request
			 * @param array $object       The object to be indexed
			 */
			apply_filters( "ep_index_{$this->name}_request_args", $request_args, $object )
		);

		/**
		 * Action that runs after the remote request
		 *
		 * This gives plugins access to the raw response from elasticsearch
		 *
		 * @since 1.7
		 *
		 * @param array  $request The request object returned by wp_remote_request()
		 * @param array  $object  The object that was (hopefully) indexed
		 * @param string $path    The path that was attempted in the remote request
		 */
		do_action( "ep_index_{$this->name}_retrieve_raw_response", $request, $object, $path );

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			return json_decode( $response_body );
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_document( $object ) {
		$index = untrailingslashit( ep_get_index_name() );

		$path = implode( '/', array( $index, $this->name, $object ) );

		$request_args = array( 'method' => 'GET' );

		$request = ep_remote_request(
			$path,
			/**
			 * Filter the request args for the document lookup
			 *
			 * @since 1.7
			 *
			 * @param array $request_args The request args
			 * @param mixed $object       The object identifier
			 */
			apply_filters( "ep_get_{$this->name}_request_args", $request_args, $object )
		);

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			$response = json_decode( $response_body, true );

			if ( ! empty( $response['exists'] ) || ! empty( $response['found'] ) ) {
				return $response['_source'];
			}
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete_document( $object, $blocking = true ) {
		$index = untrailingslashit( ep_get_index_name() );

		$path = implode( '/', array( $index, $this->name, $object ) );

		$request_args = array( 'method' => 'DELETE', 'timeout' => 15, 'blocking' => $blocking );

		$request = ep_remote_request(
			$path,
			/**
			 * Filter the request args for the request to delete a document
			 *
			 * @since 1.7
			 *
			 * @param array $request_args The request args
			 * @param mixed $object       The object identifier
			 */
			apply_filters( "ep_delete_{$this->name}_request_args", $request_args, $object )
		);

		if ( ! is_wp_error( $request ) ) {
			$response_body = wp_remote_retrieve_body( $request );

			$response = json_decode( $response_body, true );

			if ( ! empty( $response['found'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
   /**
 	 * Search for posts under a specific site index or the global index ($site_id = 0).
 	 *
 	 * @param  array  $args
 	 * @param  array  $query_args Strictly for debugging
 	 * @param  string $scope
 	 * @since  0.1.0
 	 * @return array
 	 */
 	public function query( $args, $query_args, $scope = 'current' ) {
 		$index = null;

 		if ( 'all' === $scope ) {
 			$index = ep_get_network_alias();
 		} elseif ( is_numeric( $scope ) ) {
 			$index = ep_get_index_name( (int) $scope );
 		} elseif ( is_array( $scope ) ) {
 			$index = array();

 			foreach ( $scope as $site_id ) {
 				$index[] = ep_get_index_name( $site_id );
 			}

 			$index = implode( ',', $index );
 		} else {
 			$index = ep_get_index_name();
 		}

 		$path = $index . "/{$this->name}/_search";

 		if ( 'post' === $this->name ) {
 			/**
 			 * Backwards compatibility: when posts were the only type, these were the filters. This filter is deprecated
 			 * in favor of ep_post_search_request_path and ep_search_post_args
 			 */
 			$path = apply_filters( 'ep_search_request_path', $path, $args, $scope, $query_args );
 			$args = apply_filters( 'ep_search_args', $args, $scope, $query_args );
 		}
 		$path = apply_filters( "ep_{$this->name}_search_request_path", $path, $args, $scope, $query_args );
 		$request_args = array(
 			/**
 			 * Filter the body of the search request
 			 *
 			 * @since 1.7
 			 *
 			 * @param array      $args  The body of the search request
 			 * @param string|int $scope The site context within which to search
 			 */
 			'body'   => json_encode( apply_filters( "ep_search_{$this->name}_args", $args, $scope, $query_args ) ),
 			'method' => 'POST',
 		);

 		if ( 'post' === $this->name ) {
 			/**
 			 * Backwards compatibility: when posts were the only type, this was the filter. This filter is deprecated in
 			 * favor of ep_search_post_request_args
 			 */
 			$request_args = apply_filters( 'ep_search_request_args', $request_args, $args, $scope, $query_args );
 		}
    /**
     * Filter the request args for the search request
     *
     * @since 1.7
     *
     * @param array      $request_args The search request args
     * @param array      $args         The body of the search request
     * @param string|int $scope        The site context within which to search
     */
    $request_args = apply_filters( "ep_search_{$this->name}_request_args", $request_args, $args, $scope, $query_args );

    $request = ep_remote_request( $path, $request_args, $query_args );

 		$remote_req_res_code = intval( wp_remote_retrieve_response_code( $request ) );
 		$is_valid_res = $remote_req_res_code >= 200 && $remote_req_res_code <= 299 ? true : false;

 		if ( ! is_wp_error( $request ) && apply_filters( 'ep_remote_request_is_valid_res', $is_valid_res, $request ) ) {

 			// Allow for direct response retrieval
 			do_action( 'ep_retrieve_raw_response', $request, $args, $scope, $this->name, $query_args );

 			$response_body = wp_remote_retrieve_body( $request );

 			$response = json_decode( $response_body, true );

 			if ( $this->api->is_empty_query( $response ) ) {
 				return array( 'found_objects' => 0, 'objects' => array() );
 			}

 			$hits = $response['hits']['hits'];

 			// Check for and store aggregations
 			if ( ! empty( $response['aggregations'] ) ) {
 				do_action( 'ep_retrieve_aggregations', $response['aggregations'], $args, $scope, $this->name, $query_args );
 			}

 			$objects = array();

 			foreach ( $hits as $hit ) {
 				$object            = $hit['_source'];
 				$object['site_id'] = $this->api->parse_site_id( $hit['_index'] );
 				$objects[]         = apply_filters( "ep_retrieve_the_{$this->name}", $object, $hit );
 			}

 			$results = array( 'found_objects' => $response['hits']['total'], 'objects' => $objects );
 			if ( 'post' === $this->name ) {
 				/**
 				 * Filter search results.
 				 *
 				 * Allows more complete use of filtering request variables by allowing for filtering of results.
 				 *
 				 * @since 1.6.0
 				 *
 				 * @param array $results The unfiltered search results.
 				 * @param object $response The response body retrieved from ElasticSearch.
 				 */
 				$posts_results = apply_filters(
 					'ep_search_results_array',
 					array( 'found_posts' => $results['found_objects'], 'posts' => $results['objects'] ),
 					$response
 				);

 				$results['found_objects'] = $posts_results['found_posts'];
 				$results['objects']       = $posts_results['posts'];
 			}

 			/**
 			 * Filter the search results
 			 *
 			 * @since 1.7
 			 *
 			 * @param array $results The search results
 			 * @param array $response The raw response
 			 */
 			return apply_filters( "ep_search_{$this->name}_results_array", $results, $response, $args, $scope );
 		}

 		return false;
 	}

	/**
	 * {@inheritdoc}
	 */
	public function bulk_index( $body ) {
		// create the url with index name and type so that we don't have to repeat it over and over in the request (thereby reducing the request size)
		$path = trailingslashit( ep_get_index_name() ) . "{$this->name}/_bulk";

		$request_args = array(
			'method'  => 'POST',
			'body'    => $body,
			'timeout' => 30,
		);

		if ( 'post' === $this->name ) {
			$request_args = apply_filters( 'ep_bulk_index_posts_request_args', $request_args, $body );
		}
		$request = ep_remote_request(
			$path,
			/**
			 * Filter the request args for bulk indexing
			 *
			 * @since 1.7
			 *
			 * @param array $request_args The request args
			 * @param array $body         The request body
			 */
			apply_filters( "ep_bulk_index_{$this->name}_request_args", $request_args, $body )
		);

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$response = wp_remote_retrieve_response_code( $request );

		if ( 200 !== $response ) {
			return new WP_Error( $response, wp_remote_retrieve_response_message( $request ), $request );
		}

		return json_decode( wp_remote_retrieve_body( $request ), true );
	}

	/**
	 * Prepare terms for optional inclusion in the index
	 *
	 * @since 1.7
	 *
	 * @param $object
	 *
	 * @return array
	 */
	protected function prepare_terms( $object ) {
		$taxonomies          = $this->get_object_taxonomies( $object );
		$selected_taxonomies = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy->public ) {
				$selected_taxonomies[] = $taxonomy;
			}
		}

		if ( 'post' === $this->name ) {
			$selected_taxonomies = apply_filters( 'ep_sync_taxonomies', $selected_taxonomies, $object );
		}
		/**
		 * Filter the selected taxonomies for preparing terms
		 *
		 * @since 1.7
		 *
		 * @param array $selected_taxonomies The selected taxonomies
		 * @param mixed $object              The object being prepared
		 */
		$selected_taxonomies = apply_filters( "ep_sync_{$this->name}_taxonomies", $selected_taxonomies, $object );

		if ( empty( $selected_taxonomies ) ) {
			return array();
		}

		$terms = array();

		$allow_hierarchy = apply_filters( 'ep_sync_terms_allow_hierarchy', false );

		foreach ( $selected_taxonomies as $taxonomy ) {
			/**
			 * Allow plugins to override how terms are retrieved for a custom object type
			 *
			 * @since 1.7
			 *
			 * @param        null    The variable to set to override normal get_the_terms() run
			 * @param mixed  $object The object being prepared
			 * @param object $object The taxonomy being checked
			 */
			$object_terms = apply_filters( "ep_sync_get_terms_{$this->name}", null, $object, $taxonomy );
			if ( is_null( $object_terms ) ) {
				$object_terms = get_the_terms( $this->get_object_identifier( $object ), $taxonomy->name );
			}

			if ( ! $object_terms || is_wp_error( $object_terms ) ) {
				continue;
			}

			$terms_dic = array();

			foreach ( $object_terms as $term ) {
				if ( ! isset( $terms_dic[ $term->term_id ] ) ) {
					$terms_dic[ $term->term_id ] = array(
						'term_id' => $term->term_id,
						'slug'    => $term->slug,
						'name'    => $term->name,
						'parent'  => $term->parent
					);
					if ( $allow_hierarchy ) {
						$terms_dic = $this->get_parent_terms( $terms_dic, $term, $taxonomy->name );
					}
				}
			}
			$terms[ $taxonomy->name ] = array_values( $terms_dic );
		}

		return $terms;
	}

	/**
	 * Get taxonomies for the current object/object type
	 *
	 * @since 1.7
	 *
	 * @param $object
	 *
	 * @return array
	 */
	protected function get_object_taxonomies( $object ) {
		return array();
	}

	/**
	 * Optionally prepare metadata for this object
	 *
	 * @since 1.7
	 *
	 * @param $object
	 *
	 * @return array
	 */
	protected function prepare_meta( $object ) {
		$meta = (array) $this->get_object_meta( $object );

		if ( empty( $meta ) ) {
			return array();
		}

		$prepared_meta = array();

		$allowed_protected_keys = apply_filters( "ep_prepare_{$this->name}_meta_allowed_protected_keys", array(), $object );
		if ( 'post' === $this->name ) {
			/**
			 * Filter index-able private meta
			 *
			 * Allows for specifying private meta keys that may be indexed in the same manor as public meta keys.
			 *
			 * @since 1.7
			 *
			 * @param         array Array of index-able private meta keys.
			 * @param WP_Post $post The current post to be indexed.
			 */
			$allowed_protected_keys = apply_filters( 'ep_prepare_meta_allowed_protected_keys', $allowed_protected_keys, $object );
		}

		$excluded_public_keys = apply_filters( "ep_prepare_{$this->name}_meta_excluded_public_keys", array(), $object );
		if('post'===$this->name){
			/**
			 * Filter non-indexed public meta
			 *
			 * Allows for specifying public meta keys that should be excluded from the ElasticPress index.
			 *
			 * @since 1.7
			 *
			 * @param         array Array of public meta keys to exclude from index.
			 * @param WP_Post $post The current post to be indexed.
			 */
			$excluded_public_keys = apply_filters( 'ep_prepare_meta_excluded_public_keys', $excluded_public_keys, $object );
		}

		foreach ( $meta as $key => $value ) {
			$allow_index = false;
			if ( is_protected_meta( $key ) ) {
				if ( true === $allowed_protected_keys || in_array( $key, $allowed_protected_keys ) ) {
					$allow_index = true;
				}
			} else {
				if ( true !== $excluded_public_keys && ! in_array( $key, $excluded_public_keys )  ) {
					$allow_index = true;
				}
			}
			if ( true === $allow_index ) {
				$prepared_meta[ $key ] = maybe_unserialize( $value );
			}
		}

		return apply_filters( "ep_prepare_{$this->name}_meta", $prepared_meta, $object );
	}

	/**
	 * Get all the metadata for an object
	 *
	 * @since 1.7
	 *
	 * @param $object
	 *
	 * @return array
	 */
	protected function get_object_meta( $object ) {
		return array();
	}

	/**
	 * Get the primary identifier for an object
	 *
	 * This could be a slug, or an ID, or something else. It will be used as a canonical
	 * lookup for the document.
	 *
	 * @since 1.7
	 *
	 * @param mixed $object
	 *
	 * @return int|string
	 */
	abstract protected function get_object_identifier( $object );

	/**
	 * Recursively get all the ancestor terms of the given term
	 *
	 * @since 1.7
	 *
	 * @param $terms
	 * @param $term
	 * @param $tax_name
	 *
	 * @return array
	 */
	private function get_parent_terms( $terms, $term, $tax_name ) {
		$parent_term = get_term( $term->parent, $tax_name );
		if ( ! $parent_term || is_wp_error( $parent_term ) ) {
			return $terms;
		}
		if ( ! isset( $terms[ $parent_term->term_id ] ) ) {
			$terms[ $parent_term->term_id ] = array(
				'term_id' => $parent_term->term_id,
				'slug'    => $parent_term->slug,
				'name'    => $parent_term->name,
				'parent'  => $parent_term->parent
			);
		}

		return $this->get_parent_terms( $terms, $parent_term, $tax_name );
	}

}

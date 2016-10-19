<?php

/**
 * Manage meta values for an object.
 */
abstract class WP_REST_Meta_Fields {

	/**
	 * Get the object type for meta.
	 *
	 * @return string One of 'post', 'comment', 'term', 'user', or anything
	 *                else supported by `_get_meta_table()`.
	 */
	abstract protected function get_meta_type();

	/**
	 * Get the object type for `register_rest_field`.
	 *
	 * @return string Custom post type, 'taxonomy', 'comment', or `user`.
	 */
	abstract protected function get_rest_field_type();

	/**
	 * Register the meta field.
	 */
	public function register_field() {
		register_rest_field( $this->get_rest_field_type(), 'meta', array(
			'get_callback' => array( $this, 'get_value' ),
			'update_callback' => array( $this, 'update_value' ),
			'schema' => $this->get_field_schema(),
		));
	}

	/**
	 * Get the `meta` field value.
	 *
	 * @param int             $object_id Object ID to fetch meta for.
	 * @param WP_REST_Request $request   Full details about the request.
	 * @return WP_Error|array
	 */
	public function get_value( $object_id, $request ) {
		$fields   = $this->get_registered_fields();
		$response = array();

		foreach ( $fields as $name => $args ) {
			$all_values = get_metadata( $this->get_meta_type(), $object_id, $name, false );
			if ( $args['single'] ) {
				if ( empty( $all_values ) ) {
					$value = $args['schema']['default'];
				} else {
					$value = $all_values[0];
				}
				$value = $this->prepare_value_for_response( $value, $request, $args );
			} else {
				$value = array();
				foreach ( $all_values as $row ) {
					$value[] = $this->prepare_value_for_response( $row, $request, $args );
				}
			}

			$response[ $name ] = $value;
		}

		return (object) $response;
	}

	/**
	 * Prepare value for response.
	 *
	 * This is required because some native types cannot be stored correctly in
	 * the database, such as booleans. We need to cast back to the relevant
	 * type before passing back to JSON.
	 *
	 * @param mixed           $value   Value to prepare.
	 * @param WP_REST_Request $request Current request object.
	 * @param array           $args    Options for the field.
	 * @return mixed Prepared value.
	 */
	protected function prepare_value_for_response( $value, $request, $args ) {
		if ( ! empty( $args['prepare_callback'] ) ) {
			$value = call_user_func( $args['prepare_callback'], $value, $request, $args );
		}

		return $value;
	}

	/**
	 * Update meta values.
	 *
	 * @param WP_REST_Request $request    Full details about the request.
	 * @param int             $object_id  Object ID to fetch meta for.
	 * @return WP_Error|null Error if one occurs, null on success.
	 */
	public function update_value( $request, $object_id ) {
		$fields = $this->get_registered_fields();

		foreach ( $fields as $name => $args ) {
			if ( ! array_key_exists( $name, $request ) ) {
				continue;
			}

			// A null value means reset the field, which is essentially deleting it
			// from the database and then relying on the default value.
			if ( is_null( $request[ $name ] ) ) {
				$result = $this->delete_meta_value( $object_id, $name );
			} elseif ( $args['single'] ) {
				$result = $this->update_meta_value( $object_id, $name, $request[ $name ] );
			} else {
				$result = $this->update_multi_meta_value( $object_id, $name, $request[ $name ] );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Delete meta value for an object.
	 *
	 * @param int    $object_id Object ID the field belongs to.
	 * @param string $name      Key for the field.
	 * @return bool|WP_Error True if meta field is deleted, error otherwise.
	 */
	protected function delete_meta_value( $object_id, $name ) {
		if ( ! current_user_can( 'delete_post_meta', $object_id, $name ) ) {
			return new WP_Error(
				'rest_cannot_delete',
				sprintf( __( 'You do not have permission to edit the %s custom field.' ), $name ),
				array( 'key' => $name, 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! delete_metadata( $this->get_meta_type(), $object_id, wp_slash( $name ) ) ) {
			return new WP_Error(
				'rest_meta_database_error',
				__( 'Could not delete meta value from database.' ),
				array( 'key' => $name, 'status' => WP_HTTP::INTERNAL_SERVER_ERROR )
			);
		}

		return true;
	}

	/**
	 * Update multiple meta values for an object.
	 *
	 * Alters the list of values in the database to match the list of provided values.
	 *
	 * @param int    $object_id Object ID to update.
	 * @param string $name      Key for the custom field.
	 * @param array  $values    List of values to update to.
	 * @return bool|WP_Error True if meta fields are updated, error otherwise.
	 */
	protected function update_multi_meta_value( $object_id, $name, $values ) {
		if ( ! current_user_can( 'edit_post_meta', $object_id, $name ) ) {
			return new WP_Error(
				'rest_cannot_update',
				sprintf( __( 'You do not have permission to edit the %s custom field.' ), $name ),
				array( 'key' => $name, 'status' => rest_authorization_required_code() )
			);
		}

		$current = get_metadata( $this->get_meta_type(), $object_id, $name, false );

		$to_remove = $current;
		$to_add = $values;
		foreach ( $to_add as $add_key => $value ) {
			$remove_keys = array_keys( $to_remove, $value, true );
			if ( empty( $remove_keys ) ) {
				continue;
			}

			if ( count( $remove_keys ) > 1 ) {
				// To remove, we need to remove first, then add, so don't touch.
				continue;
			}

			$remove_key = $remove_keys[0];
			unset( $to_remove[ $remove_key ] );
			unset( $to_add[ $add_key ] );
		}

		// `delete_metadata` removes _all_ instances of the value, so only call
		// once.
		$to_remove = array_unique( $to_remove );
		foreach ( $to_remove as $value ) {
			if ( ! delete_metadata( $this->get_meta_type(), $object_id, wp_slash( $name ), wp_slash( $value ) ) ) {
				return new WP_Error(
					'rest_meta_database_error',
					__( 'Could not update meta value in database.' ),
					array( 'key' => $name, 'status' => WP_HTTP::INTERNAL_SERVER_ERROR )
				);
			}
		}
		foreach ( $to_add as $value ) {
			if ( ! add_metadata( $this->get_meta_type(), $object_id, wp_slash( $name ), wp_slash( $value ) ) ) {
				return new WP_Error(
					'rest_meta_database_error',
					__( 'Could not update meta value in database.' ),
					array( 'key' => $name, 'status' => WP_HTTP::INTERNAL_SERVER_ERROR )
				);
			}
		}

		return true;
	}

	/**
	 * Update meta value for an object.
	 *
	 * @param int    $object_id Object ID to update.
	 * @param string $name      Key for the custom field.
	 * @param mixed  $value     Updated value.
	 * @return bool|WP_Error True if meta field is updated, error otherwise.
	 */
	protected function update_meta_value( $object_id, $name, $value ) {
		if ( ! current_user_can( 'edit_post_meta', $object_id, $name ) ) {
			return new WP_Error(
				'rest_cannot_update',
				sprintf( __( 'You do not have permission to edit the %s custom field.' ), $name ),
				array( 'key' => $name, 'status' => rest_authorization_required_code() )
			);
		}

		$meta_type  = $this->get_meta_type();
		$meta_key   = wp_slash( $name );
		$meta_value = wp_slash( $value );

		// Do the exact same check for a duplicate value as in update_metadata() to avoid update_metadata() returning false.
		$old_value = get_metadata( $meta_type, $object_id, $meta_key );
		if ( 1 === count( $old_value ) ) {
			if ( $old_value[0] === $meta_value ) {
				return true;
			}
		}

		if ( ! update_metadata( $meta_type, $object_id, $meta_key, $meta_value ) ) {
			return new WP_Error(
				'rest_meta_database_error',
				__( 'Could not update meta value in database.' ),
				array( 'key' => $name, 'status' => WP_HTTP::INTERNAL_SERVER_ERROR )
			);
		}

		return true;
	}

	/**
	 * Get all the registered meta fields.
	 *
	 * @return array
	 */
	protected function get_registered_fields() {
		$registered = array();

		foreach ( get_registered_meta_keys( $this->get_meta_type() ) as $name => $args ) {
			if ( empty( $args['show_in_rest'] ) ) {
				continue;
			}

			$rest_args = array();
			if ( is_array( $args['show_in_rest'] ) ) {
				$rest_args = $args['show_in_rest'];
			}

			$default_args = array(
				'name'             => $name,
				'single'           => $args['single'],
				'schema'           => array(),
				'prepare_callback' => array( $this, 'prepare_value' ),
			);
			$default_schema = array(
				'type'        => null,
				'description' => empty( $args['description'] ) ? '' : $args['description'],
				'default'     => isset( $args['default'] ) ? $args['default'] : null,
				'items'		  => array()
			);

			$rest_args = array_merge( $default_args, $rest_args );
			$rest_args['schema'] = array_merge( $default_schema, $rest_args['schema'] );
			
			if ( empty( $rest_args['schema']['type'] ) ) {
				// Skip over meta fields that don't have a defined type.
				if ( empty( $args['type'] ) ) {
					continue;
				}

				$rest_args['schema']['type'] = $args['type'];
			}

			// if this is a meta key which is allowed to have multiple values, the type will be array and everything will move down one level
			if( empty( $rest_args['single'] ) ){

				if( !empty( $rest_args['schema']['type'] ) ){

					$new_items = $rest_args['schema'];
					unset( $new_items['description'] );
					unset( $new_items['default'] );
					$rest_args['schema']['items'] = $new_items;
					if( !empty( $rest_args['schema']['properties'] ) ){
						unset( $rest_args['schema']['properties'] );
					}
				}

				$rest_args['schema']['type'] = 'array';
			}

			$registered[ $rest_args['name'] ] = $rest_args;
		} // End foreach().

		return $registered;
	}

	/**
	 * Get the object's `meta` schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_field_schema() {
		$fields = $this->get_registered_fields();

		$schema = array(
			'description' => __( 'Meta fields.' ),
			'type'        => 'object',
			'context'     => array( 'view', 'edit' ),
			'properties'  => array(),
		);

		foreach ( $fields as $key => $args ) {
			$schema['properties'][ $key ] = $args['schema'];
		}
		return $schema;

	}

	/**
	 * Prepare a meta value for output.
	 *
	 * Default preparation for meta fields. Override by passing the
	 * `prepare_callback` in your `show_in_rest` options.
	 *
	 * @param mixed           $value   Meta value from the database.
	 * @param WP_REST_Request $request Request object.
	 * @param array           $args    REST-specific options for the meta key.
	 * @return mixed Value prepared for output.
	 */
	public function prepare_value( $value, $request, $args ) {
		
		// Don't allow non stdClass objects to be output.
		if ( is_object( $value ) && ! ( $value instanceof stdClass ) ) {
			return array( 'error' => 'Objects may only be stdClass objects.');
		}

		if( empty( $args['single'] ) ){
			$type =  'array';

		} elseif( !empty( $args['schema']['type'] ) ){
			$type = $args['schema']['type'];

		} elseif( is_array( $value ) && $this->is_json_array( $value ) ){
			$type = 'array';

		} elseif ( ( is_array( $value ) && ! $this->is_json_array( $value ) ) || (  is_object( $value ) && ( $value instanceof stdClass ) ) ) { // Is this an associative array or a stdClass object
			$type = 'object';

		} else {
			$type = 'string';

		};

		$value = $this->validate_value( $value, $type, $args['schema'] );

		return $value;
	}

	/**
	 * Validate a value for output
	 *
	 * This validates each meta value against its schema. 
	 *
	 * @param mixed 	$value   Meta value from the database.
	 * @param string 	$type    The json type (limited to number, bool, array, object, string)
	 * @param array 	$schema  The schema to which the value should conform
	 * @return mixed Value prepared for output.
	 */
	public function validate_value( $value, $type = null, $schema ){

		switch ( $type ) {
			case 'number':
				$retval = floatval( $value );
				break;
			case 'bool':
				$retval = (bool) $value;
				break;
			case 'array':
				$retval = $this->validate_array( $value, $schema );
				break;
			case 'object':
				$retval = $this->validate_object( $value, $schema );
				break;
			case 'string': 
				$retval = strval( $value );
				break;
			default: 
				// No custom types allowed.
				$retval = array( 'error' => 'incorrect type used in register_meta()' );
		}

		return $retval;

	}

	/**
	 * Validate an array for output
	 *
	 * This validates each array against its schema. 
	 *
	 * @param mixed 	$array   Array to be validated
	 * @param array 	$schema  The schema to which the array should conform
	 * @param array 	$retarray The array being recursively built
	 * @return array Array prepared for output.
	 */
	public function validate_array( $array, $schema, $retarray = array() ){

		// First let's make a new schema to use for the items
		$new_schema = ( !empty( $schema['items'] ) ) ? $schema['items'] : false;

		// If the items have no declared type, they will be strings
		$type = ( !empty( $schema['items']['type'] ) ) ? $schema['items']['type'] : 'string';

		if ( ! is_array( $array ) && ! is_object( $array ) ) {
			// If this is not an array and not an object then a scalar value has snuck its way in. Go to validate_value().
			return $this->validate_value( $array, $type, $new_schema );

		} elseif ( ! $this->is_json_array( $array ) ) {
			// If this isn't what json would consider an array, ie if it's an object or an associative array, go to validate_object().
			$retarray = $this->validate_object( $array, $new_schema );

		} else {
			// This should be an array with numerical keys. So let's iterate over it.
			foreach( $array as $value ){
				
				if ( $type === 'array' ) {
					// If it's another array, let's validate that.
					$retarray[] = $this->validate_array( $value, $new_schema, $retarray );

				}
				else{
					// If it's a value, let's validate that.
					$retarray[] = $this->validate_value( $value, $type, false );
				}
			}
		}
		// After all that, return the validated array
		return $retarray;
	}

	/**
	 * Validate an object for output
	 *
	 * This validates each object against its schema. The word "object" refers to the type in the json schema, not the php type. 
	 * So in this instance, both associative arrays and objects are objects.
	 *
	 * @param mixed 	$object   Meta object from the database.
	 * @param array 	$schema  The schema to which the object should conform
	 * @param object 	$retobject The object being recursively built, you will need to pass in an empty array or 
	 * @return array Array prepared for output.
	 */
	public function validate_object( $object, $schema = array(), $retobject = array() ){

		if( is_object( $object ) ){
			// if it's a php object, check if it's a stdClass object
			if( $object instanceof stdClass ){
				// if it's a stdClass object, the return type needs to be an object too
				$return_type = 'object';
			}
			else{
				// if it's an object but not a stdClass object, bail and return an error
				return array( 'error' => 'Objects must be stdClass.' );
			}
		} elseif ( is_array( $object ) && ! $this->is_json_array( $object ) ){
				// if it's an array with solely numberical keys, it's going to be a json array
				$return_type = 'array';
		}
		else{
			return array( 'error' => 'Cannot validate value as an object' );
		}

		// Ok. Now the validation begins.

		// The schema must have the object's properties.
		$properties = ( !empty( $schema['properties'] ) ) ? $schema['properties'] : false;

		if( empty( $properties ) ){
			return array( 'error' => 'Objects must have properties set to validate correctly.' ); // Could be super helpful if we wanted I guess
		}

		// Casting this might be bad but in the interest of making it work first, here goes.
		$object = (array)$object;

		foreach( $properties as $key => $value_schema ){
			if( !empty( $object[$key] ) ){

				// If a type wasn't in the schema it will be a string.
				$value_type = ( !empty( $value_schema['type'] ) ) ? $value_schema['type'] : 'string';

				// Validate the value
				$retobject[$key] = $this->validate_value( $object[$key], $value_type, $value_schema );

				// This one's validated, take it out of the array.
				unset( $object[$key] );
			}
			else{
				return array( 'error' => 'Not all keys in the schema validated in the object.' );
			}
		}

		// All of the keys should have been unset. If not, there are keys which were not validated.
		if( !empty( $object ) ){
			return array( 'error' => 'The object has keys which are not present in its schema and cannot be validated.' );
		}

		$retobject = ( $return_type === 'object' ) ? (object) $retobject : (array) $retobject;
		return $retobject;
	}

	/**
	 * Is a value an array with only numeric keys?
	 *
	 * This is used to differentiate between associative and numberic arrays. It also returns false if you pass in something that isn't an array.
	 *
	 * @param mixed 	$value   The value to check
	 * @return bool True if it will end up as a json array, false if not.
	 */
	public function is_json_array( $value ){
		if( !is_array( $value ) ){
			return false;
		}
		$is_json_array = ( count( array_filter( array_keys( $value ), 'is_string' ) ) === 0 ) ? true : false;
		return $is_json_array;
	}


}

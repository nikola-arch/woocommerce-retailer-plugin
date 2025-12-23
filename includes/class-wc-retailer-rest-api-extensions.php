<?php

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Prevent class redeclaration
if (!class_exists('WC_Retailer_REST_API_Extensions')) {

    class WC_Retailer_REST_API_Extensions extends WC_Retailer_Abstract_Loader
    {
        /**
         * @param WP_REST_Request $request
         * @return bool
         */
        private function is_sw_api_extension_enabled($request)
        {
            $flag = $request->get_param('enable_sw_api_extension');
            return !empty($flag) && filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        public function run()
        {
            add_action(
                'woocommerce_rest_insert_product_object',
                array($this, 'auto_create_brand'),
                8,
                2
            );

            add_action(
                'woocommerce_rest_insert_product_object',
                array($this, 'auto_create_attributes_and_terms'),
                10,
                3
            );

            add_filter(
                'woocommerce_rest_prepare_product_object',
                array($this, 'refresh_auto_created_data_in_response'),
                PHP_INT_MAX,
                3
            );

            add_filter(
                'woocommerce_rest_prepare_product_object',
                array($this, 'embed_variations_in_response'),
                PHP_INT_MAX,
                3
            );
        }

        /**
         * @param WC_Product $product
         * @param WP_REST_Request $request
         * @throws WC_REST_Exception
         */
        public function auto_create_brand($product, $request)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return;
            }

            if (!isset($request['sw_brands'])) {
                return;
            }

            try {
                $this->validate_sw_brands($request['sw_brands']);

                $term_ids = array();
                $taxonomy = 'product_brand';

                foreach ($request['sw_brands'] as $brand_name) {
                    $brand_name = trim($brand_name);
                    $slug = sanitize_title($brand_name);

                    $existing = get_term_by('slug', $slug, $taxonomy);
                    if (!$existing) {
                        $existing = get_term_by('name', $brand_name, $taxonomy);
                    }

                    if ($existing && !is_wp_error($existing)) {
                        $term_ids[] = (int)$existing->term_id;
                        continue;
                    }

                    $created = wp_insert_term($brand_name, $taxonomy);
                    if (!is_wp_error($created)) {
                        $term_ids[] = (int)$created['term_id'];
                    }
                }

                if (!empty($term_ids)) {
                    wp_set_object_terms($product->get_id(), $term_ids, $taxonomy, false);
                }
            } catch (Exception $e) {
                $this->handle_extension_error('auto_create_brand', $e, $product->get_id());
            }
        }

        /**
         * @param WC_Product $product
         * @param WP_REST_Request $request
         * @throws WC_REST_Exception
         */
        public function auto_create_attributes_and_terms($product, $request)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return;
            }

            if (!isset($request['sw_attributes'])) {
                return;
            }

            try {
                $this->validate_sw_attributes($request['sw_attributes']);

                $product_attributes = array();

                foreach ($request['sw_attributes'] as $attribute_data) {
                    $attribute_slug = wc_sanitize_taxonomy_name($attribute_data['slug']);
                    $taxonomy_name = wc_attribute_taxonomy_name($attribute_slug);

                    $attribute_id = wc_attribute_taxonomy_id_by_name($taxonomy_name);

                    if (!$attribute_id) {
                        $attribute_id = wc_create_attribute(array(
                            'name' => $attribute_data['name'],
                            'slug' => $attribute_slug,
                            'type' => 'select',
                            'order_by' => 'menu_order',
                            'has_archives' => false,
                        ));

                        if (is_wp_error($attribute_id)) {
                            continue;
                        }

                        register_taxonomy(
                            $taxonomy_name,
                            array('product'),
                            array(
                                'labels' => array(
                                    'name' => $attribute_data['name'],
                                ),
                                'hierarchical' => true,
                                'show_ui' => false,
                                'query_var' => true,
                                'rewrite' => false,
                            )
                        );

                        delete_transient('wc_attribute_taxonomies');
                        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
                    }

                    $term_ids = array();
                    foreach ($attribute_data['options'] as $option_data) {
                        $option_name = trim($option_data['name']);
                        $option_slug = sanitize_title($option_data['slug']);

                        $existing = get_term_by('slug', $option_slug, $taxonomy_name);

                        if (!$existing) {
                            $existing = get_term_by('name', $option_name, $taxonomy_name);
                        }

                        if ($existing && !is_wp_error($existing)) {
                            if ($existing->name !== $option_name) {
                                wp_update_term($existing->term_id, $taxonomy_name, array(
                                    'name' => $option_name,
                                    'slug' => $option_slug
                                ));
                            }
                            $term_ids[] = (int)$existing->term_id;
                            continue;
                        }

                        $created = wp_insert_term($option_name, $taxonomy_name, array(
                            'slug' => $option_slug
                        ));

                        if (!is_wp_error($created)) {
                            $term_ids[] = (int)$created['term_id'];
                        }
                    }

                    $product_attribute = new WC_Product_Attribute();
                    $product_attribute->set_id($attribute_id);
                    $product_attribute->set_name($taxonomy_name);
                    $product_attribute->set_options($term_ids);
                    $product_attribute->set_position(count($product_attributes));
                    $product_attribute->set_visible(isset($attribute_data['visible']) ? (bool)$attribute_data['visible'] : true);
                    $product_attribute->set_variation(isset($attribute_data['variation']) ? (bool)$attribute_data['variation'] : true);

                    $product_attributes[] = $product_attribute;
                }

                if (!empty($product_attributes)) {
                    $product->set_attributes($product_attributes);
                }

                $product->save();
            } catch (Exception $e) {
                $this->handle_extension_error('auto_create_attributes_and_terms', $e, $product->get_id());
            }
        }

        /**
         * @param WP_REST_Response $response
         * @param WC_Product $product
         * @param WP_REST_Request $request
         * @return WP_REST_Response
         */
        public function refresh_auto_created_data_in_response($response, $product, $request)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return $response;
            }

            $needs_refresh = isset($request['sw_brands']) || isset($request['sw_attributes']);

            if (!$needs_refresh) {
                return $response;
            }

            $fresh_product = wc_get_product($product->get_id());

            if (!$fresh_product) {
                return $response;
            }

            if (isset($request['sw_brands'])) {
                $brand_taxonomy = 'product_brand';
                $brand_terms = wp_get_post_terms($fresh_product->get_id(), $brand_taxonomy);

                if (!is_wp_error($brand_terms) && !empty($brand_terms)) {
                    $response->data['brands'] = array();
                    foreach ($brand_terms as $term) {
                        $response->data['brands'][] = array(
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                        );
                    }
                }
            }

            if (isset($request['sw_attributes'])) {
                $attributes = $fresh_product->get_attributes();

                if (!empty($attributes)) {
                    $response->data['attributes'] = array();

                    foreach ($attributes as $attribute) {
                        $attribute_name = $attribute->get_name();
                        $label = wc_attribute_label($attribute_name, $fresh_product);
                        $slug = $attribute->is_taxonomy() ? $attribute_name : sanitize_title($attribute_name);

                        if ($label === $attribute_name && $attribute->is_taxonomy()) {
                            $taxonomy_obj = get_taxonomy($attribute_name);
                            if ($taxonomy_obj && isset($taxonomy_obj->labels->singular_name)) {
                                $label = $taxonomy_obj->labels->singular_name;
                            }
                        }

                        $attribute_data = array(
                            'id' => $attribute->get_id(),
                            'name' => $label,
                            'slug' => $slug,
                            'position' => $attribute->get_position(),
                            'visible' => $attribute->get_visible(),
                            'variation' => $attribute->get_variation(),
                            'options' => array(),
                        );

                        if ($attribute->is_taxonomy()) {
                            $terms = wp_get_post_terms($fresh_product->get_id(), $attribute->get_name());
                            if (!is_wp_error($terms)) {
                                foreach ($terms as $term) {
                                    $attribute_data['options'][] = $term->name;
                                }
                            }
                        } else {
                            $attribute_data['options'] = $attribute->get_options();
                        }

                        $response->data['attributes'][] = $attribute_data;
                    }
                }
            }

            return $response;
        }

        /**
         * @param WP_REST_Response $response
         * @param WC_Product $product
         * @param WP_REST_Request $request
         * @return WP_REST_Response
         */
        public function embed_variations_in_response($response, $product, $request)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return $response;
            }

            if ($product->get_type() !== 'variable') {
                return $response;
            }

            $response->data['sw_variations'] = array();
            $variation_ids = $product->get_children();

            if (empty($variation_ids)) {
                return $response;
            }

            $attr_id_map = wp_list_pluck($response->data['attributes'] ?? array(), 'id', 'slug');
            $attr_name_map = wp_list_pluck($response->data['attributes'] ?? array(), 'name', 'slug');

            foreach ($variation_ids as $variation_id) {
                $variation = new WC_Product_Variation($variation_id);

                if (!$variation || !$variation->exists()) {
                    continue;
                }

                $variation_data = array(
                    'id' => $variation_id,
                    'sku' => $variation->get_sku(),
                    'global_unique_id' => '',
                    'description' => $variation->get_description(),
                    'price' => $variation->get_price(),
                    'regular_price' => $variation->get_regular_price(),
                    'sale_price' => $variation->get_sale_price(),
                    'manage_stock' => $variation->get_manage_stock(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'stock_status' => $variation->get_stock_status(),
                    'backorders' => $variation->get_backorders(),
                    'backorders_allowed' => $variation->backorders_allowed(),
                    'weight' => $variation->get_weight(),
                    'dimensions' => array(
                        'length' => $variation->get_length(),
                        'width' => $variation->get_width(),
                        'height' => $variation->get_height(),
                    ),
                    'image' => null,
                    'attributes' => array(),
                    'meta_data' => array()
                );

                if ($image_id = $variation->get_image_id()) {
                    $variation_data['image'] = array(
                        'id' => $image_id,
                        'src' => wp_get_attachment_image_url($image_id, 'full'),
                        'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                    );
                }

                foreach ($variation->get_meta_data() as $meta) {
                    $variation_data['meta_data'][] = array(
                        'id' => $meta->id,
                        'key' => $meta->key,
                        'value' => $meta->value,
                    );
                }

                if (method_exists($variation, 'get_global_unique_id')) {
                    $variation_data['global_unique_id'] = $variation->get_global_unique_id();
                }

                foreach ($variation->get_attributes() as $attribute_slug => $attribute_value) {
                    $attribute_id = $attr_id_map[$attribute_slug] ?? 0;
                    $attribute_name = $attr_name_map[$attribute_slug] ?? wc_attribute_label($attribute_slug, $variation);
                    $option = $attribute_value;

                    if (taxonomy_exists($attribute_slug)) {
                        $term = get_term_by('slug', $attribute_value, $attribute_slug);
                        if (!is_wp_error($term) && $term && !empty($term->name)) {
                            $option = $term->name;
                        }
                    }

                    $variation_data['attributes'][] = array(
                        'id' => $attribute_id,
                        'name' => $attribute_name,
                        'slug' => $attribute_slug,
                        'option' => $option,
                    );
                }

                $response->data['sw_variations'][] = $variation_data;
            }

            return $response;
        }

        /**
         * Expected: ['Nike', 'Adidas'] (array of non-empty strings)
         *
         * @param mixed $brands
         * @throws Exception
         */
        private function validate_sw_brands($brands)
        {
            if (!is_array($brands)) {
                throw new Exception('sw_brands must be an array of brand names');
            }

            if (empty($brands)) {
                throw new Exception('sw_brands cannot be empty');
            }

            foreach ($brands as $index => $brand) {
                if (!is_string($brand)) {
                    throw new Exception("sw_brands[$index] must be a string, " . gettype($brand) . ' given');
                }

                if (trim($brand) === '') {
                    throw new Exception("sw_brands[$index] cannot be empty");
                }
            }
        }

        /**
         * Expected: [{'name': 'Color', 'slug': 'color', 'options': [{'name': 'Red', 'slug': 'red'}]}]
         *
         * @param mixed $attributes
         * @throws Exception
         */
        private function validate_sw_attributes($attributes)
        {
            if (!is_array($attributes)) {
                throw new Exception('sw_attributes must be an array');
            }

            if (empty($attributes)) {
                throw new Exception('sw_attributes cannot be empty');
            }

            foreach ($attributes as $index => $attr) {
                if (!is_array($attr)) {
                    throw new Exception("sw_attributes[$index] must be an object, " . gettype($attr) . ' given');
                }

                $required = array('name', 'slug', 'options');
                foreach ($required as $field) {
                    if (!isset($attr[$field])) {
                        throw new Exception("sw_attributes[$index] missing required field: $field");
                    }
                }

                if (empty($attr['name']) || !is_string($attr['name'])) {
                    throw new Exception("sw_attributes[$index].name must be a non-empty string");
                }

                if (empty($attr['slug']) || !is_string($attr['slug'])) {
                    throw new Exception("sw_attributes[$index].slug must be a non-empty string");
                }

                if (!is_array($attr['options'])) {
                    throw new Exception("sw_attributes[$index].options must be an array");
                }

                if (empty($attr['options'])) {
                    throw new Exception("sw_attributes[$index].options cannot be empty");
                }

                foreach ($attr['options'] as $optIndex => $option) {
                    if (!is_array($option)) {
                        throw new Exception("sw_attributes[$index].options[$optIndex] must be an object");
                    }

                    if (!isset($option['name']) || !isset($option['slug'])) {
                        throw new Exception("sw_attributes[$index].options[$optIndex] must have 'name' and 'slug' fields");
                    }

                    if (empty($option['name']) || !is_string($option['name'])) {
                        throw new Exception("sw_attributes[$index].options[$optIndex].name must be a non-empty string");
                    }

                    if (empty($option['slug']) || !is_string($option['slug'])) {
                        throw new Exception("sw_attributes[$index].options[$optIndex].slug must be a non-empty string");
                    }
                }
            }
        }

        /**
         * @param string $method 
         * @param Exception $e
         * @param int|null $product_id
         * @throws WC_REST_Exception
         */
        private function handle_extension_error($method, $e, $product_id = null)
        {
            $context = $product_id ? " (product_id: $product_id)" : '';
            $message = "Invalid data in $method$context: " . $e->getMessage();
            
            throw new WC_REST_Exception(
                'wc_retailer_invalid_extension_data',
                $message,
                400
            );
        }
    }
}
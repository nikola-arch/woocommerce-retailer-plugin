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
                array($this, 'handle_duplicate_sku_and_global_unique_id'), 5,
                3
            );

            add_action(
                'woocommerce_rest_insert_product_object',
                array($this, 'auto_create_brand'),
                8,
                3
            );

            add_action(
                'woocommerce_rest_insert_product_object',
                array($this, 'auto_create_attributes_and_terms'),
                10,
                3
            );

            add_action(
                'woocommerce_rest_insert_product_object',
                array($this, 'process_product_variations_after_insert'),
                15,
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
         * @param bool $creating
         */
        public function handle_duplicate_sku_and_global_unique_id($product, $request, $creating)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return;
            }

            $current_product_id = $product->get_id();

            if (isset($request['sku']) && !empty($request['sku'])) {
                $sku = wc_clean($request['sku']);

                if ($creating || $product->get_sku() !== $sku) {
                    $duplicate_id = wc_get_product_id_by_sku($sku);

                    if ($duplicate_id && $duplicate_id !== $current_product_id) {
                        $duplicate_product = wc_get_product($duplicate_id);
                        if ($duplicate_product) {
                            $duplicate_product->set_sku('');
                            $duplicate_product->save();
                        }
                    }
                }
            }

            if (isset($request['global_unique_id']) && !empty($request['global_unique_id'])) {
                $global_unique_id = wc_clean($request['global_unique_id']);

                if ($creating || $product->get_meta('global_unique_id') !== $global_unique_id) {
                    $duplicates = get_posts(array(
                        'post_type' => array('product', 'product_variation'),
                        'posts_per_page' => 1,
                        'post__not_in' => array($current_product_id),
                        'fields' => 'ids',
                        'meta_query' => array(
                            array(
                                'key' => 'global_unique_id',
                                'value' => $global_unique_id,
                                'compare' => '=',
                            ),
                        ),
                    ));

                    if (!empty($duplicates)) {
                        delete_post_meta($duplicates[0], 'global_unique_id');
                    }
                }
            }

            if (isset($request['sw_variations']) && is_array($request['sw_variations'])) {
                foreach ($request['sw_variations'] as $variation_data) {
                    if (isset($variation_data['sku']) && !empty($variation_data['sku'])) {
                        $sku = wc_clean($variation_data['sku']);
                        $duplicate_id = wc_get_product_id_by_sku($sku);

                        if ($duplicate_id) {
                            $duplicate_variation = wc_get_product($duplicate_id);
                            if ($duplicate_variation && $duplicate_variation->get_parent_id() !== $current_product_id) {
                                $duplicate_variation->set_sku('');
                                $duplicate_variation->save();
                            }
                        }
                    }

                    if (isset($variation_data['global_unique_id']) && !empty($variation_data['global_unique_id'])) {
                        $global_unique_id = wc_clean($variation_data['global_unique_id']);

                        $duplicates = get_posts(array(
                            'post_type' => array('product', 'product_variation'),
                            'posts_per_page' => 1,
                            'fields' => 'ids',
                            'meta_query' => array(
                                array(
                                    'key' => 'global_unique_id',
                                    'value' => $global_unique_id,
                                    'compare' => '=',
                                ),
                            ),
                        ));

                        if (!empty($duplicates)) {
                            $duplicate_product = wc_get_product($duplicates[0]);
                            if ($duplicate_product) {
                                $should_delete = false;

                                if ($duplicate_product->get_type() === 'variation') {
                                    if ($duplicate_product->get_parent_id() !== $current_product_id) {
                                        $should_delete = true;
                                    }
                                } else {
                                    $should_delete = true;
                                }

                                if ($should_delete) {
                                    delete_post_meta($duplicates[0], 'global_unique_id');
                                }
                            }
                        }
                    }
                }
            }
        }

        /**
         * @param WC_Product $product
         * @param WP_REST_Request $request
         * @param bool $creating
         * @throws WC_REST_Exception
         */
        public function auto_create_brand($product, $request, $creating)
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
         * @param bool $creating
         * @throws WC_REST_Exception
         */
        public function auto_create_attributes_and_terms($product, $request, $creating)
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
            } catch (Exception $e) {
                $this->handle_extension_error('auto_create_attributes_and_terms', $e, $product->get_id());
            }
        }

        /**
         * @param WC_Product $product
         * @param WP_REST_Request $request
         * @param bool $creating
         * @throws WC_REST_Exception
         */
        public function process_product_variations_after_insert($product, $request, $creating)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return;
            }

            if ($product->get_type() !== 'variable' || !isset($request['sw_variations'])) {
                return;
            }

            try {
                $this->validate_sw_variations($request['sw_variations']);

                $existing_variation_ids = $this->get_existing_variation_ids($product->get_id());

                $operations = $this->separate_variation_operations(
                    $request['sw_variations'],
                    $existing_variation_ids,
                    $product->get_id()
                );

                foreach ($operations['create'] as $variation_data) {
                    $variation = new WC_Product_Variation();
                    $this->process_product_variation(
                        $product,
                        $variation,
                        $variation_data,
                        true
                    );
                }

                foreach ($operations['update'] as $variation_data) {
                    $variation_id = $variation_data['id'];
                    $variation = new WC_Product_Variation($variation_id);

                    if ($variation->get_parent_id() === $product->get_id()) {
                        $this->process_product_variation(
                            $product,
                            $variation,
                            $variation_data,
                            false
                        );
                    }
                }

                foreach ($operations['delete'] as $variation_id) {
                    $variation = new WC_Product_Variation($variation_id);

                    if ($variation->get_parent_id() === $product->get_id()) {
                        $variation->delete(true);
                    }
                }

                if (!$creating) {
                    $history = get_post_meta($product->get_id(), 'updates_timelog', true);

                    if (!is_array($history)) {
                        $history = $history ? array($history) : array();
                    }

                    $history[] = time();
                    $product->update_meta_data('updates_timelog', $history);
                }
            } catch (Exception $e) {
                $this->handle_extension_error('process_product_variations_after_insert', $e, $product->get_id());
            }
        }

        /**
         * @param array $request_variations
         * @param array $existing_variation_ids
         * @param int $product_id
         * @return array
         */
        private function separate_variation_operations($request_variations, $existing_variation_ids, $product_id)
        {
            $operations = array(
                'create' => array(),
                'update' => array(),
                'delete' => array(),
            );

            $processed_variation_ids = array();

            $retailer_to_wc_map = array();
            foreach ($existing_variation_ids as $wc_variation_id) {
                $retailer_variation_id = get_post_meta($wc_variation_id, '_sw_retailer_variation_id', true);
                if (!empty($retailer_variation_id)) {
                    $retailer_to_wc_map[$retailer_variation_id] = $wc_variation_id;
                }
            }

            foreach ($request_variations as $variation_data) {
                $wc_variation_id = null;

                $retailer_variation_id = $this->get_retailer_variation_id_from_meta($variation_data);

                if (!empty($retailer_variation_id) && isset($retailer_to_wc_map[$retailer_variation_id])) {
                    $wc_variation_id = $retailer_to_wc_map[$retailer_variation_id];
                }

                if ($wc_variation_id !== null) {
                    $variation_data['id'] = $wc_variation_id;
                    $operations['update'][] = $variation_data;
                    $processed_variation_ids[] = $wc_variation_id;
                } else {
                    $operations['create'][] = $variation_data;
                }
            }

            $operations['delete'] = array_diff($existing_variation_ids, $processed_variation_ids);

            return $operations;
        }

        /**
         * @param int $product_id
         * @return array
         */
        private function get_existing_variation_ids($product_id)
        {
            $variation_ids = get_posts(array(
                'post_parent' => $product_id,
                'post_type' => 'product_variation',
                'fields' => 'ids',
                'post_status' => 'publish',
                'numberposts' => -1,
            ));

            return $variation_ids ? array_map('intval', $variation_ids) : array();
        }

        /**
         * @param WC_Product $product
         * @param WC_Product_Variation $variation
         * @param array $request_variation
         * @param bool $creating
         * @return int
         * @throws Exception
         */
        private function process_product_variation($product, $variation, $request_variation, $creating)
        {
            $variation->set_parent_id($product->get_id());

            if (!empty($request_variation['attributes']) && is_array($request_variation['attributes'])) {
                $wc_attributes = $this->convert_structured_attributes_to_wc_format($request_variation['attributes']);
                if (!empty($wc_attributes)) {
                    $variation->set_attributes($wc_attributes);
                }
            }

            if (isset($request_variation['regular_price'])) {
                $variation->set_regular_price($request_variation['regular_price']);
            }

            if (isset($request_variation['sale_price'])) {
                $variation->set_sale_price($request_variation['sale_price']);
                $variation->set_price($request_variation['sale_price']);
            } elseif (isset($request_variation['regular_price'])) {
                $variation->set_price($request_variation['regular_price']);
            }

            if (isset($request_variation['stock_quantity'])) {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($request_variation['stock_quantity']);
            }

            if (isset($request_variation['weight'])) {
                $variation->set_weight($request_variation['weight']);
            }

            if (isset($request_variation['sku'])) {
                $variation->set_sku($request_variation['sku']);
            }

            if (isset($request_variation['dimensions'])) {
                if (isset($request_variation['dimensions']['length'])) {
                    $variation->set_length($request_variation['dimensions']['length']);
                }
                if (isset($request_variation['dimensions']['width'])) {
                    $variation->set_width($request_variation['dimensions']['width']);
                }
                if (isset($request_variation['dimensions']['height'])) {
                    $variation->set_height($request_variation['dimensions']['height']);
                }
            }

            if (isset($request_variation['meta_data']) && is_array($request_variation['meta_data'])) {
                foreach ($request_variation['meta_data'] as $meta) {
                    if (isset($meta['key']) && isset($meta['value'])) {
                        $variation->update_meta_data($meta['key'], $meta['value']);
                    }
                }
            }

            if (isset($request_variation['global_unique_id'])) {
                $current_global_id = $variation->get_meta('global_unique_id');
                if ($request_variation['global_unique_id'] !== $current_global_id) {
                    $variation->update_meta_data('global_unique_id', $request_variation['global_unique_id']);
                }
            }

            if (isset($request_variation['image_id'])) {
                $variation->set_image_id($request_variation['image_id']);
            }

            $variation->save();

            return $variation->get_id();
        }

        /**
         * @param array $variation_data
         * @return string|null
         */
        private function get_retailer_variation_id_from_meta($variation_data)
        {
            if (!isset($variation_data['meta_data']) || !is_array($variation_data['meta_data'])) {
                return null;
            }

            foreach ($variation_data['meta_data'] as $meta) {
                if (isset($meta['key']) && $meta['key'] === '_sw_retailer_variation_id' && isset($meta['value'])) {
                    return (string)$meta['value'];
                }
            }

            return null;
        }

        /**
         * Convert new structured attributes format to WooCommerce format
         * Input: [{'attribute_slug': 'color', 'option_slug': 'red'}, ...]
         * Output: ['pa_color' => 'red', ...]
         *
         * @param array $attributes
         * @return array
         * @throws Exception
         */
        private function convert_structured_attributes_to_wc_format($attributes)
        {
            if (empty($attributes) || !is_array($attributes)) {
                return array();
            }

            $result = array();

            foreach ($attributes as $attribute) {
                if (!isset($attribute['attribute_slug']) || !isset($attribute['option_slug'])) {
                    continue;
                }

                $attribute_slug = wc_sanitize_taxonomy_name($attribute['attribute_slug']);
                $option_slug = sanitize_title($attribute['option_slug']);

                $taxonomy_name = wc_attribute_taxonomy_name($attribute_slug);

                if (!taxonomy_exists($taxonomy_name)) {
                    throw new Exception(
                        "Attribute taxonomy '{$attribute_slug}' does not exist. " .
                        "Ensure sw_attributes includes this attribute before variations."
                    );
                }

                $term = get_term_by('slug', $option_slug, $taxonomy_name);

                if (!$term || is_wp_error($term)) {
                    throw new Exception(
                        "Option term '{$option_slug}' not found in taxonomy '{$attribute_slug}'. " .
                        "Ensure sw_attributes includes this option before variations."
                    );
                }

                $result[$taxonomy_name] = $term->slug;
            }

            return $result;
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
                        $attribute_data = array(
                            'id' => $attribute->get_id(),
                            'name' => $attribute->get_name(),
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

            $variations_data = array();

            if (!empty($response->data['variations']) && is_array($response->data['variations'])) {
                $product_attribute_ids = !empty($response->data['attributes'])
                    ? wp_list_pluck($response->data['attributes'], 'id', 'name')
                    : array();

                foreach ($response->data['variations'] as $variation_id) {
                    $variation = new WC_Product_Variation($variation_id);

                    if (!$variation || !$variation->exists()) {
                        continue;
                    }

                    $variation_data = array(
                        'id' => $variation_id,
                        'sku' => $variation->get_sku(),
                        'description' => $variation->get_description(),
                        'price' => (float)$variation->get_price(),
                        'regular_price' => (float)$variation->get_regular_price(),
                        'sale_price' => (float)$variation->get_sale_price(),
                        'manage_stock' => $variation->get_manage_stock(),
                        'stock_quantity' => $variation->get_stock_quantity() ?? '',
                        'stock_status' => $variation->get_stock_status(),
                        'backorders' => $variation->get_backorders(),
                        'backorders_allowed' => $variation->backorders_allowed(),
                        'weight' => $variation->get_weight(),
                        'dimensions' => array(
                            'length' => $variation->get_length(),
                            'width' => $variation->get_width(),
                            'height' => $variation->get_height(),
                        ),
                    );

                    $image_id = $variation->get_image_id();
                    if ($image_id) {
                        $variation_data['image'] = array(
                            'id' => $image_id,
                            'src' => wp_get_attachment_image_url($image_id, 'full'),
                            'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                        );
                    } else {
                        $variation_data['image'] = null;
                    }

                    $meta_data = $variation->get_meta_data();
                    $variation_data['meta_data'] = array();

                    foreach ($meta_data as $meta) {
                        $variation_data['meta_data'][] = array(
                            'id' => $meta->id,
                            'key' => $meta->key,
                            'value' => $meta->value,
                        );
                    }

                    $global_unique_id = $variation->get_meta('global_unique_id');
                    if ($global_unique_id) {
                        $variation_data['global_unique_id'] = $global_unique_id;
                    }

                    $attributes = array();
                    foreach ($variation->get_attributes() as $attribute_name => $attribute_value) {
                        $name = wc_attribute_label($attribute_name, $variation);
                        $id = isset($product_attribute_ids[$name]) ? $product_attribute_ids[$name] : 0;
                        $option = $attribute_value;

                        if (taxonomy_exists($attribute_name)) {
                            $term = get_term_by('slug', $attribute_value, $attribute_name);
                            if (!is_wp_error($term) && $term && !empty($term->name)) {
                                $option = $term->name;
                            }
                        }

                        $attributes[] = array(
                            'id' => $id,
                            'name' => $name,
                            'option' => $option,
                        );
                    }

                    $variation_data['attributes'] = $attributes;
                    $variations_data[] = $variation_data;
                }
            }

            $response->data['sw_variations'] = $variations_data;

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
         * @param mixed $variations
         * @throws Exception
         */
        private function validate_sw_variations($variations)
        {
            if (!is_array($variations)) {
                throw new Exception('sw_variations must be an array');
            }

            if (empty($variations)) {
                throw new Exception('sw_variations cannot be empty');
            }

            foreach ($variations as $index => $variation) {
                if (!is_array($variation)) {
                    throw new Exception("sw_variations[$index] must be an object, " . gettype($variation) . ' given');
                }

                if (isset($variation['meta_data'])) {
                    if (!is_array($variation['meta_data'])) {
                        throw new Exception("sw_variations[$index].meta_data must be an array");
                    }

                    foreach ($variation['meta_data'] as $metaIndex => $meta) {
                        if (!is_array($meta)) {
                            throw new Exception("sw_variations[$index].meta_data[$metaIndex] must be an object");
                        }

                        if (!isset($meta['key'])) {
                            throw new Exception("sw_variations[$index].meta_data[$metaIndex] missing required field: key");
                        }

                        if (!isset($meta['value'])) {
                            throw new Exception("sw_variations[$index].meta_data[$metaIndex] missing required field: value");
                        }
                    }
                }

                if (isset($variation['dimensions'])) {
                    if (!is_array($variation['dimensions'])) {
                        throw new Exception("sw_variations[$index].dimensions must be an object");
                    }
                }

                if (isset($variation['attributes'])) {
                    if (!is_array($variation['attributes'])) {
                        throw new Exception("sw_variations[$index].attributes must be an array");
                    }

                    foreach ($variation['attributes'] as $attrIndex => $attr) {
                        if (!is_array($attr)) {
                            throw new Exception("sw_variations[$index].attributes[$attrIndex] must be an array");
                        }

                        if (!isset($attr['attribute_slug']) || !isset($attr['option_slug'])) {
                            throw new Exception("sw_variations[$index].attributes[$attrIndex] must have 'attribute_slug' and 'option_slug' fields");
                        }

                        if (empty($attr['attribute_slug']) || !is_string($attr['attribute_slug'])) {
                            throw new Exception("sw_variations[$index].attributes[$attrIndex].attribute_slug must be a non-empty string");
                        }

                        if (empty($attr['option_slug']) || !is_string($attr['option_slug'])) {
                            throw new Exception("sw_variations[$index].attributes[$attrIndex].option_slug must be a non-empty string");
                        }
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
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
            ini_set('memory_limit', '-1');

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
                            'post_type' => 'product_variation',
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
                            $duplicate_variation = wc_get_product($duplicates[0]);
                            if ($duplicate_variation && $duplicate_variation->get_parent_id() !== $current_product_id) {
                                delete_post_meta($duplicates[0], 'global_unique_id');
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
         */
        public function auto_create_brand($product, $request, $creating)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return;
            }

            if (!isset($request['sw_brands'])) {
                return;
            }

            $brand_data = $request['sw_brands'];

            $brands = is_array($brand_data) ? $brand_data : array($brand_data);

            $term_ids = array();
            $taxonomy = 'product_brand';

            foreach ($brands as $brand) {

                $brand_name = is_array($brand) && isset($brand['name'])
                    ? trim($brand['name'])
                    : trim((string)$brand);

                if ($brand_name === '') {
                    continue;
                }

                $taxonomy = isset($brand['taxonomy']) ? sanitize_key($brand['taxonomy']) : 'product_brand';

                if (!taxonomy_exists($taxonomy)) {
                    $this->register_brand_taxonomy($taxonomy);
                }

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
                wp_set_object_terms($product->get_id(), array_map('intval', $term_ids), $taxonomy, false);
            }
        }

        /**
         * @param string $taxonomy
         */
        private function register_brand_taxonomy($taxonomy)
        {
            $labels = array(
                'name' => 'Brands',
                'singular_name' => 'Brand',
                'search_items' => 'Search Brands',
                'all_items' => 'All Brands',
                'parent_item' => 'Parent Brand',
                'parent_item_colon' => 'Parent Brand:',
                'edit_item' => 'Edit Brand',
                'update_item' => 'Update Brand',
                'add_new_item' => 'Add New Brand',
                'new_item_name' => 'New Brand Name',
                'menu_name' => 'Brands',
            );

            $args = array(
                'labels' => $labels,
                'hierarchical' => true,
                'public' => true,
                'show_ui' => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => true,
                'show_tagcloud' => true,
                'show_in_rest' => true,
                'rewrite' => array('slug' => 'brand'),
            );

            register_taxonomy($taxonomy, array('product'), $args);
        }

        /**
         * @param WC_Product $product
         * @param WP_REST_Request $request
         * @param bool $creating
         */
        public function auto_create_attributes_and_terms($product, $request, $creating)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return;
            }

            if (!isset($request['sw_attributes']) || !is_array($request['sw_attributes'])) {
                return;
            }

            $product_attributes = array();

            foreach ($request['sw_attributes'] as $attribute_data) {
                if (!isset($attribute_data['name']) || !isset($attribute_data['options'])) {
                    continue;
                }

                $attribute_name = wc_sanitize_taxonomy_name($attribute_data['name']);
                $taxonomy_name = wc_attribute_taxonomy_name($attribute_name);

                $attribute_id = wc_attribute_taxonomy_id_by_name($taxonomy_name);

                if (!$attribute_id) {
                    $attribute_id = wc_create_attribute(array(
                        'name' => $attribute_data['name'],
                        'slug' => $attribute_name,
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
                foreach ($attribute_data['options'] as $option_name) {
                    $option_name = trim($option_name);
                    if ($option_name === '') {
                        continue;
                    }

                    $slug = sanitize_title($option_name);
                    $existing = get_term_by('slug', $slug, $taxonomy_name);

                    if (!$existing) {
                        $existing = get_term_by('name', $option_name, $taxonomy_name);
                    }

                    if ($existing && !is_wp_error($existing)) {
                        $term_ids[] = (int)$existing->term_id;
                        continue;
                    }

                    $created = wp_insert_term($option_name, $taxonomy_name);
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
        }

        /**
         * @param WC_Product $product
         * @param WP_REST_Request $request
         * @param bool $creating
         */
        public function process_product_variations_after_insert($product, $request, $creating)
        {
            if (!$this->is_sw_api_extension_enabled($request)) {
                return;
            }

            if ($product->get_type() !== 'variable' || !isset($request['sw_variations'])) {
                return;
            }

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
         */
        private function process_product_variation($product, $variation, $request_variation, $creating)
        {
            $variation->set_parent_id($product->get_id());

            if (!empty($request_variation['attributes_by_id_and_term_name'])) {
                $variation->set_attributes(
                    $this->get_attributes_term_slug_by_term_name($request_variation['attributes_by_id_and_term_name'])
                );
            } elseif (!empty($request_variation['attributes'])) {
                $variation->set_attributes($request_variation['attributes']);
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
                if (!$creating) {
                    $filtered_meta = array();
                    foreach ($request_variation['meta_data'] as $meta) {
                        if (isset($meta['key']) && $meta['key'] !== '_ean13') {
                            $filtered_meta[] = $meta;
                        }
                    }
                    $request_variation['meta_data'] = $filtered_meta;
                }

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
         * @param array $attributes
         * @return array
         */
        private function get_attributes_term_slug_by_term_name($attributes)
        {
            if (empty($attributes) || !is_array($attributes)) {
                return array();
            }

            $result = array();

            foreach ($attributes as $attribute) {
                if (isset($attribute['id']) && isset($attribute['option'])) {
                    $attribute_name = wc_attribute_taxonomy_name_by_id($attribute['id']);

                    if ($attribute_name) {
                        $terms = get_terms(array(
                            'taxonomy' => $attribute_name,
                            'hide_empty' => false,
                        ));

                        if (!is_wp_error($terms)) {
                            foreach ($terms as $term) {
                                if ($term->name === $attribute['option']) {
                                    $result[$attribute_name] = $term->slug;
                                    break;
                                }
                            }
                        }
                    }
                }
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
    }
}
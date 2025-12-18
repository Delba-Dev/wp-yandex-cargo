<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class YCWC_Admin {
    public function __construct(){
        add_filter('woocommerce_admin_order_actions', array($this, 'add_order_action'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_ycwc_create_delivery', array($this, 'ajax_create_delivery'));
        add_action('admin_notices', array($this, 'maybe_show_admin_notice'));
        
        // Автоматическое создание заявки при оформлении заказа (опционально)
        add_action('woocommerce_checkout_order_processed', array($this, 'auto_create_delivery'), 10, 1);
    }

    /**
     * Показ сообщения после редиректа (например, после ручного создания заявки).
     */
    public function maybe_show_admin_notice() {
        if ( empty($_GET['ycwc_notice']) ) {
            return;
        }

        if ( ! current_user_can('manage_woocommerce') ) {
            return;
        }

        $msg = sanitize_text_field( wp_unslash( $_GET['ycwc_notice'] ) );
        if ( $msg === '' ) {
            return;
        }

        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }
    
    /**
     * Общее логирование
     */
    private function log($msg, $data = null, $level = 'info') {
        $settings = YCWC_Settings::instance()->get_options();
        if ( ! isset($settings['debug']) || $settings['debug'] !== 'yes' ) {
            return;
        }
        
        if ( function_exists('wc_get_logger') ) {
            $log_msg = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
            if ($data !== null) { 
                $log_msg .= PHP_EOL . 'DATA: ' . wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            
            $allowed_levels = array('debug', 'info', 'notice', 'warning', 'error');
            $level = in_array($level, $allowed_levels) ? $level : 'info';
            wc_get_logger()->$level($log_msg, array('source'=>'ycwc'));
        }
    }
    
    /**
     * Детальное логирование POST запросов к Яндекс.Доставке
     */
    private function log_yandex_request($url, $body, $oauth_token) {
        $this->log('=== YANDEX API REQUEST ===', array(
            'method' => 'POST',
            'url' => $url,
            'timestamp' => time(),
            'request_id' => $body['request_id'] ?? 'N/A',
            'items_count' => count($body['items'] ?? []),
            'route_points' => array(
                'source' => isset($body['route_points'][0]) ? array(
                    'point_id' => $body['route_points'][0]['point_id'] ?? $body['route_points'][0]['id'] ?? null,
                    'address' => $body['route_points'][0]['address']['fullname'] ?? $body['route_points'][0]['fullname'] ?? 'N/A',
                    'coordinates' => $body['route_points'][0]['address']['coordinates'] ?? $body['route_points'][0]['coordinates'] ?? 'N/A',
                    'contact' => $body['route_points'][0]['contact'] ?? null
                ) : 'N/A',
                'destination' => isset($body['route_points'][1]) ? array(
                    'point_id' => $body['route_points'][1]['point_id'] ?? $body['route_points'][1]['id'] ?? null,
                    'address' => $body['route_points'][1]['address']['fullname'] ?? $body['route_points'][1]['fullname'] ?? 'N/A',
                    'coordinates' => $body['route_points'][1]['address']['coordinates'] ?? $body['route_points'][1]['coordinates'] ?? 'N/A',
                    'contact' => $body['route_points'][1]['contact'] ?? null
                ) : 'N/A'
            ),
            'client_requirements' => $body['client_requirements'] ?? $body['requirements'] ?? null,
            'items' => $body['items'] ?? array(),
            'offer_payload' => isset($body['offer_payload']) ? 'PRESENT (' . strlen($body['offer_payload']) . ' chars)' : 'NOT PRESENT',
            'full_body' => $body,
            'body_size_bytes' => strlen(wp_json_encode($body)),
            'oauth_token_length' => strlen($oauth_token),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ), 'info');
    }
    
    /**
     * Детальное логирование ответов от Яндекс.Доставки
     */
    private function log_yandex_response($response, $http_code, $status_code) {
        $is_error = is_string($response) || (is_array($response) && (isset($response['code']) || isset($response['message'])));
        
        $this->log('=== YANDEX API RESPONSE ===', array(
            'timestamp' => time(),
            'http_code' => $http_code,
            'status_code' => $status_code,
            'is_error' => $is_error,
            'claim_id' => isset($response['id']) ? $response['id'] : (is_string($response) ? 'ERROR_STRING' : 'N/A'),
            'status' => isset($response['status']) ? $response['status'] : 'N/A',
            'error_message' => isset($response['message']) ? $response['message'] : (is_string($response) ? $response : 'N/A'),
            'error_code' => isset($response['code']) ? $response['code'] : 'N/A',
            'full_response' => is_array($response) ? $response : array('raw_response' => $response),
            'response_size_bytes' => is_string($response) ? strlen($response) : (is_array($response) ? strlen(wp_json_encode($response)) : 0)
        ), $is_error || $http_code !== 200 ? 'error' : 'info');
    }
    
    /**
     * Очистка заголовков для логирования
     */
    private function sanitize_log_headers($headers) {
        if (!is_array($headers)) {
            return $headers;
        }
        
        $sanitized = array();
        foreach ($headers as $key => $value) {
            if (stripos($key, 'authorization') !== false || stripos($key, 'token') !== false) {
                $sanitized[$key] = is_string($value) ? substr($value, 0, 20) . '...[hidden]' : '[hidden]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
    
    /**
     * Автоматическое создание заявки при оформлении заказа
     */
    public function auto_create_delivery($order_id) {
        $this->log('=== AUTO-CREATE DELIVERY TRIGGERED ===', array(
            'order_id' => $order_id,
            'timestamp' => time()
        ), 'info');
        
        $settings = YCWC_Settings::instance()->get_options();
        
        // Проверяем, включено ли автоматическое создание заявок
        if ( ! isset($settings['auto_create_delivery']) || $settings['auto_create_delivery'] !== 'yes' ) {
            $this->log('Auto-create delivery disabled in settings', array(
                'auto_create_delivery_setting' => $settings['auto_create_delivery'] ?? 'not set'
            ));
            return;
        }
        
        $order = wc_get_order($order_id);
        if ( ! $order ) {
            $this->log('ERROR: Order not found', array('order_id' => $order_id), 'error');
            return;
        }
        
        $this->log('Order loaded', array(
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'order_status' => $order->get_status(),
            'order_total' => $order->get_total(),
            'shipping_methods' => array_map(function($item) {
                return array(
                    'method_id' => $item->get_method_id(),
                    'method_title' => $item->get_method_title(),
                    'total' => $item->get_total()
                );
            }, $order->get_items('shipping'))
        ));
        
        // Проверяем, есть ли наш метод доставки
        $has_yandex_delivery = false;
        $shipping_methods = array();
        foreach ( $order->get_items('shipping') as $item ) {
            $method_id = $item->get_method_id();
            $shipping_methods[] = $method_id;
            if ( strpos( $method_id, 'yandex_delivery_cargo' ) !== false ) {
                $has_yandex_delivery = true;
                break;
            }
        }
        
        $this->log('Shipping methods check', array(
            'has_yandex_delivery' => $has_yandex_delivery,
            'all_shipping_methods' => $shipping_methods
        ));
        
        if ( ! $has_yandex_delivery ) {
            $this->log('No Yandex delivery method found in order', array('order_id' => $order_id));
            return;
        }
        
        $this->log('Starting delivery creation for order', array('order_id' => $order_id), 'info');
        
        // Создаем заявку автоматически
        $this->create_delivery_for_order($order);
    }
    
    /**
     * Конвертация единиц измерения в метры
     */
    private function convert_to_meters($value, $unit) {
        if (empty($value) || $value <= 0) return 0.01; // минимум 1 см
        
        switch ($unit) {
            case 'mm':
                return $value / 1000;
            case 'cm':
                return $value / 100;
            case 'm':
                return $value;
            case 'in':
                return $value * 0.0254;
            case 'yd':
                return $value * 0.9144;
            default:
                return $value / 100; // предполагаем см по умолчанию
        }
    }

    /**
     * Конвертация единиц веса в килограммы
     */
    private function convert_to_kg($value, $unit) {
        if (empty($value) || $value <= 0) return 0.1; // минимум 100г
        
        switch ($unit) {
            case 'g':
                return $value / 1000;
            case 'kg':
                return $value;
            case 'lbs':
                return $value * 0.453592;
            case 'oz':
                return $value * 0.0283495;
            default:
                return $value; // предполагаем кг по умолчанию
        }
    }

    /**
     * Автоматический выбор типа кузова на основе веса и габаритов
     */
    private function calculate_cargo_type($items) {
        if (empty($items)) return null;
        
        $total_weight = 0;
        $max_length = 0;
        $max_width = 0;
        $max_height = 0;
        
        foreach ($items as $item) {
            $total_weight += $item['weight'];
            $max_length = max($max_length, $item['size']['length']);
            $max_width = max($max_width, $item['size']['width']);
            $max_height = max($max_height, $item['size']['height']);
        }
        
        // Конвертируем из метров в см для сравнения
        $max_length_cm = $max_length * 100;
        $max_width_cm = $max_width * 100;
        $max_height_cm = $max_height * 100;
        
        // Таблица ограничений (в см и кг)
        $cargo_types = array(
            'van' => array('weight' => 300, 'length' => 170, 'width' => 100, 'height' => 90),
            'lcv_m' => array('weight' => 700, 'length' => 260, 'width' => 130, 'height' => 150),
            'lcv_l' => array('weight' => 1400, 'length' => 380, 'width' => 180, 'height' => 180),
            'lcv_xl' => array('weight' => 2000, 'length' => 400, 'width' => 190, 'height' => 200),
            'lcv_xxl' => array('weight' => 4000, 'length' => 500, 'width' => 200, 'height' => 200)
        );
        
        foreach ($cargo_types as $type => $limits) {
            if ($total_weight <= $limits['weight'] && 
                $max_length_cm <= $limits['length'] && 
                $max_width_cm <= $limits['width'] && 
                $max_height_cm <= $limits['height']) {
                return $type;
            }
        }
        
        return null; // Заказ не помещается ни в один тип кузова
    }
    
    /**
     * Создание заявки для заказа (общая функция)
     */
    private function create_delivery_for_order($order) {
        $order_id = $order->get_id();
        
        $this->log('=== CREATE DELIVERY FOR ORDER START ===', array(
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'order_status' => $order->get_status(),
            'timestamp' => time()
        ), 'info');
        
        try {
            $settings = YCWC_Settings::instance()->get_options();
            $oauth = $settings['oauth_token'];
            
            $this->log('Settings loaded', array(
                'has_oauth' => !empty($oauth),
                'oauth_length' => strlen($oauth),
                'has_maps_key' => !empty($settings['maps_api_key']),
                'warehouse_address' => $settings['warehouse_address'],
                'warehouse_coords' => array($settings['warehouse_lon'], $settings['warehouse_lat']),
                'sender_phone' => $settings['sender_phone']
            ));
            
            if (empty($oauth)) {
                $error_msg = 'Ошибка: не настроен OAuth токен Яндекс.Доставки';
                $this->log('ERROR: OAuth token missing', null, 'error');
                $order->add_order_note($error_msg);
                return;
            }

            // Получаем данные заказа
            $wh_lon = floatval($settings['warehouse_lon']);
            $wh_lat = floatval($settings['warehouse_lat']);
            $wh_address = $settings['warehouse_address'];
            $sender_phone = $settings['sender_phone'];
            
            $this->log('Warehouse data', array(
                'address' => $wh_address,
                'coordinates' => array('lon' => $wh_lon, 'lat' => $wh_lat),
                'sender_phone' => $sender_phone
            ));

            // Адрес получателя (shipping, fallback на billing)
            $dest_country = $order->get_shipping_country() ?: $order->get_billing_country();
            $dest_state = $order->get_shipping_state() ?: $order->get_billing_state();
            $dest_city = $order->get_shipping_city() ?: $order->get_billing_city();
            $dest_addr1 = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
            $dest_addr2 = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
            $dest_postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();

            $dest_address = trim( implode(', ', array_filter([
                $dest_country === 'RU' ? 'Россия' : $dest_country,
                $dest_state,
                $dest_city,
                $dest_addr1,
                $dest_addr2,
                $dest_postcode
            ])));
            
            $this->log('=== GEOCODING DESTINATION ===', array(
                'destination_address' => $dest_address,
                'shipping_data' => array(
                    'country' => $order->get_shipping_country(),
                    'state' => $order->get_shipping_state(),
                    'city' => $order->get_shipping_city(),
                    'address_1' => $order->get_shipping_address_1(),
                    'address_2' => $order->get_shipping_address_2(),
                    'postcode' => $order->get_shipping_postcode()
                )
            ), 'info');

            // Геокодирование адреса получателя
            $dest_lon = null; $dest_lat = null;
            if ( ! empty( $settings['maps_api_key'] ) ) {
                $geo_url = 'https://geocode-maps.yandex.ru/1.x/?format=json&apikey=' . urlencode($settings['maps_api_key']) . '&geocode=' . urlencode($dest_address);
                
                $this->log('Geocoding request', array(
                    'url' => $geo_url,
                    'address' => $dest_address
                ));
                
                $geo_start = microtime(true);
                $resp = wp_remote_get($geo_url, array('timeout'=>15));
                $geo_time = round((microtime(true) - $geo_start) * 1000, 2);
                
                if ( ! is_wp_error($resp) ) {
                    $code = wp_remote_retrieve_response_code($resp);
                    $body = wp_remote_retrieve_body($resp);
                    $data = json_decode($body, true);
                    
                    $this->log('Geocoding response', array(
                        'http_code' => $code,
                        'response_time_ms' => $geo_time,
                        'response_data' => $data
                    ));
                    
                    if ( $code == 200 ) {
                        if ( ! empty($data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos']) ) {
                            $pos = explode(' ', $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos']);
                            if (count($pos)===2) { 
                                $dest_lon = floatval($pos[0]); 
                                $dest_lat = floatval($pos[1]); 
                                $this->log('Geocoding SUCCESS', array(
                                    'lon' => $dest_lon,
                                    'lat' => $dest_lat,
                                    'address' => $dest_address
                                ), 'info');
                            }
                        } else {
                            $this->log('Geocoding failed - no coordinates in response', array('response' => $data), 'warning');
                        }
                    } else {
                        $this->log('Geocoding failed - HTTP error', array(
                            'code' => $code,
                            'body' => $body
                        ), 'error');
                    }
                } else {
                    $this->log('Geocoding failed - WP_Error', array(
                        'error_code' => $resp->get_error_code(),
                        'error_message' => $resp->get_error_message()
                    ), 'error');
                }
            } else {
                $this->log('Geocoding skipped - no maps_api_key');
            }
            
            if ( $dest_lon === null || $dest_lat === null ) {
                // Fallback: используем координаты центра Москвы для адресов в Москве
                if (stripos($dest_address, 'москва') !== false || stripos($dest_address, 'moscow') !== false) {
                    $dest_lon = 37.617635; // Центр Москвы
                    $dest_lat = 55.755814;
                    $this->log('Using FALLBACK coordinates for Moscow', array(
                        'lon' => $dest_lon,
                        'lat' => $dest_lat,
                        'warning' => 'Using approximate coordinates!'
                    ), 'warning');
                } else {
                    $error_msg = 'Ошибка: не удалось определить координаты адреса получателя';
                    $this->log('ERROR: Geocoding failed - not Moscow', array('address' => $dest_address), 'error');
                    $order->add_order_note($error_msg);
                    return;
                }
            }
            
            $this->log('Geocoding complete', array(
                'final_coordinates' => array('lon' => $dest_lon, 'lat' => $dest_lat)
            ), 'info');

            // Собираем товары
            $this->log('=== PROCESSING ORDER ITEMS ===', null, 'info');
            $items = array();
            $weight_unit = get_option('woocommerce_weight_unit', 'kg');
            $dimension_unit = get_option('woocommerce_dimension_unit', 'cm');
            
            $this->log('Units configuration', array(
                'weight_unit' => $weight_unit,
                'dimension_unit' => $dimension_unit
            ));
            
            foreach ( $order->get_items() as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) continue;
                $product = $item->get_product();
                if ( ! $product ) continue;
                
                $qty = (int)$item->get_quantity();
                $raw_weight = floatval($product->get_weight() ?: 0);
                $raw_length = floatval($product->get_length() ?: 0);
                $raw_width = floatval($product->get_width() ?: 0);
                $raw_height = floatval($product->get_height() ?: 0);
                
                $weight = $this->convert_to_kg($raw_weight, $weight_unit) * $qty;
                $length = $this->convert_to_meters($raw_length, $dimension_unit);
                $width  = $this->convert_to_meters($raw_width, $dimension_unit);
                $height = $this->convert_to_meters($raw_height, $dimension_unit);

                $item_data = array(
                    'extra_id' => (string)$product->get_id(),
                    'pickup_point' => 1,
                    'dropoff_point' => 2,
                    'title' => $product->get_name(),
                    'size' => array(
                        'length' => max(0.01, $length),
                        'width' => max(0.01, $width),
                        'height' => max(0.01, $height),
                    ),
                    'weight' => max(0.1, floatval($weight)),
                    'cost_value' => number_format($product->get_price(), 2, '.', ''),
                    'cost_currency' => 'RUB',
                    'quantity' => max(1, $qty),
                    'age_restricted' => false
                );
                
                $items[] = $item_data;
                
                $this->log('Order item processed', array(
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name(),
                    'quantity' => $qty,
                    'raw_weight' => $raw_weight . ' ' . $weight_unit,
                    'raw_dimensions' => array(
                        'length' => $raw_length . ' ' . $dimension_unit,
                        'width' => $raw_width . ' ' . $dimension_unit,
                        'height' => $raw_height . ' ' . $dimension_unit
                    ),
                    'converted_weight_kg' => $weight,
                    'converted_dimensions_m' => array(
                        'length' => $length,
                        'width' => $width,
                        'height' => $height
                    ),
                    'item_data' => $item_data
                ));
            }
            
            $this->log('Order items summary', array(
                'items_count' => count($items),
                'items' => $items
            ), 'info');

            if (empty($items)) {
                $error_msg = 'Ошибка: нет товаров для отправки';
                $this->log('ERROR: No items in order', null, 'error');
                $order->add_order_note($error_msg);
                return;
            }

            // Вычисляем тип кузова
            $this->log('=== CALCULATING CARGO TYPE ===', array('items_count' => count($items)), 'info');
            $cargo_type = $this->calculate_cargo_type($items);
            
            if ($cargo_type === null) {
                $error_msg = 'Ошибка: товары не помещаются ни в один тип кузова';
                $this->log('ERROR: Cargo too large', array('items' => $items), 'error');
                $order->add_order_note($error_msg);
                return;
            }
            
            $this->log('Cargo type determined', array('cargo_type' => $cargo_type), 'info');

            // Получаем payload из мета-данных заказа
            $payload = $order->get_meta('_ycwc_payload');
            if (empty($payload)) {
                foreach ( $order->get_items('shipping') as $item ) {
                    $payload = $item->get_meta('ycwc_payload');
                    if ($payload) break;
                }
            }
            
            $this->log('Payload check', array(
                'has_payload' => !empty($payload),
                'payload_length' => !empty($payload) ? strlen($payload) : 0,
                'payload_source' => !empty($payload) ? (empty($order->get_meta('_ycwc_payload')) ? 'shipping_item_meta' : 'order_meta') : 'none'
            ));

            // Формируем данные для API
            $this->log('=== PREPARING API REQUEST ===', null, 'info');
            $body = array(
                'request_id' => uniqid('ycwc_', true),
                'items' => $items,
                'route_points' => array(
                    array(
                        'point_id' => 1,
                        'visit_order' => 1,
                        'type' => 'source',
                        'contact' => array(
                            'name' => get_bloginfo('name'),
                            'phone' => $sender_phone
                        ),
                        'address' => array(
                            'fullname' => $wh_address,
                            'coordinates' => array($wh_lon, $wh_lat)
                        ),
                        'skip_confirmation' => true,
                        'leave_under_door' => false,
                        'meet_outside' => false,
                        'no_door_call' => false
                    ),
                    array(
                        'point_id' => 2,
                        'visit_order' => 2,
                        'type' => 'destination',
                        'contact' => array(
                            'name' => $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name(),
                            'phone' => preg_replace('/[^0-9\+]/','', $order->get_billing_phone() )
                        ),
                        'address' => array(
                            'fullname' => $dest_address,
                            'coordinates' => array($dest_lon, $dest_lat)
                        ),
                        'external_order_id' => (string)$order_id,
                        'skip_confirmation' => true,
                        'leave_under_door' => false,
                        'meet_outside' => false,
                        'no_door_call' => false
                    )
                ),
                'client_requirements' => array(
                    'taxi_class' => 'cargo',
                    'cargo_type' => $cargo_type,
                    'cargo_loaders' => 0
                ),
                'skip_client_notify' => false,
                'comment' => 'Заказ #' . $order->get_order_number()
            );

            if ($payload) {
                $body['offer_payload'] = $payload;
            }

            $this->log('Request body prepared', array('body' => $body, 'body_size_bytes' => strlen(wp_json_encode($body))), 'info');
            
            // Формируем URL с request_id в query параметрах
            $create_url = 'https://b2b.taxi.yandex.net/b2b/cargo/integration/v2/claims/create?request_id=' . urlencode($body['request_id']);
            
            // Логируем запрос
            $this->log_yandex_request($create_url, $body, $oauth);

            // Отправляем запрос на создание заявки
            $headers = array(
                'Authorization' => 'Bearer ' . $oauth,
                'Content-Type' => 'application/json',
                'Accept-Language' => 'ru-RU'
            );
            
            $this->log('Sending create claim request', array(
                'url' => $create_url,
                'headers' => $this->sanitize_log_headers($headers)
            ), 'info');
            
            $create_start = microtime(true);
            $resp = wp_remote_post( $create_url, array(
                'timeout' => 30,
                'headers' => $headers,
                'body' => wp_json_encode($body)
            ));
            $create_time = round((microtime(true) - $create_start) * 1000, 2);

            if ( is_wp_error($resp) ) { 
                $error_msg = $resp->get_error_message();
                $this->log('ERROR: WP_Error in create request', array(
                    'error_code' => $resp->get_error_code(),
                    'error_message' => $error_msg,
                    'error_data' => $resp->get_error_data(),
                    'response_time_ms' => $create_time
                ), 'error');
                $this->log_yandex_response($error_msg, null, 0);
                $order->add_order_note('Ошибка создания заявки: ' . $error_msg);
                return;
            }
            
            $code = wp_remote_retrieve_response_code($resp);
            $response_body = wp_remote_retrieve_body($resp);
            $response_headers = wp_remote_retrieve_headers($resp);
            $json = json_decode($response_body, true);
            
            $this->log('Create claim response received', array(
                'http_code' => $code,
                'response_time_ms' => $create_time,
                'response_size_bytes' => strlen($response_body)
            ));
            
            // Логируем ответ
            $this->log_yandex_response($json, $code, $code);

            if ( $code !== 200 ) {
                $error_msg = isset($json['message']) ? $json['message'] : 'Неизвестная ошибка API';
                $this->log('ERROR: API returned non-200', array(
                    'http_code' => $code,
                    'error_message' => $error_msg,
                    'full_response' => $json
                ), 'error');
                $order->add_order_note('Ошибка API Яндекс.Доставки: ' . $error_msg);
                return;
            }

            $claim_id = $json['id'] ?? '';
            if ( empty($claim_id) ) {
                $this->log('ERROR: No claim_id in response', array('response' => $json), 'error');
                $order->add_order_note('Ошибка: не удалось получить ID заявки');
                return;
            }
            
            $this->log('Claim created successfully', array(
                'claim_id' => $claim_id,
                'claim_status' => $json['status'] ?? 'unknown'
            ), 'info');

            // Сохраняем claim_id в мета-данные заказа
            $order->update_meta_data('_ycwc_claim_id', $claim_id);
            if ( $payload ) $order->update_meta_data('_ycwc_payload', $payload);
            $order->save();
            
            $this->log('Order meta data saved', array(
                'claim_id' => $claim_id,
                'has_payload' => !empty($payload)
            ));

            // Подтверждаем заявку
            $this->log('=== ACCEPTING CLAIM ===', array('claim_id' => $claim_id), 'info');
            $accept_url = 'https://b2b.taxi.yandex.net/b2b/cargo/integration/v2/claims/accept';
            $accept_body = array('claim_id' => $claim_id);
            
            $this->log('Accept request prepared', array(
                'url' => $accept_url,
                'body' => $accept_body
            ));
            
            $accept_start = microtime(true);
            $acc_resp = wp_remote_post( $accept_url, array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $oauth,
                    'Content-Type' => 'application/json',
                    'Accept-Language' => 'ru-RU'
                ),
                'body' => wp_json_encode($accept_body)
            ));
            $accept_time = round((microtime(true) - $accept_start) * 1000, 2);
            
            $acc_code = wp_remote_retrieve_response_code($acc_resp);
            $acc_body = wp_remote_retrieve_body($acc_resp);
            $acc_json = json_decode($acc_body, true);
            
            $this->log('Accept claim response', array(
                'http_code' => $acc_code,
                'response_time_ms' => $accept_time,
                'response' => $acc_json
            ), $acc_code === 200 ? 'info' : 'error');

            if ($acc_code === 200) {
                $this->log('=== DELIVERY CREATION SUCCESS ===', array(
                    'order_id' => $order_id,
                    'claim_id' => $claim_id,
                    'status' => $acc_json['status'] ?? 'unknown'
                ), 'info');
                $order->add_order_note('Заявка создана и подтверждена. Claim ID: ' . $claim_id);
            } else {
                $this->log('WARNING: Claim created but accept failed', array(
                    'claim_id' => $claim_id,
                    'accept_code' => $acc_code,
                    'accept_response' => $acc_json
                ), 'warning');
                $order->add_order_note('Заявка создана, но подтверждение не удалось. Claim ID: ' . $claim_id);
            }

        } catch (Exception $e) {
            $this->log('EXCEPTION in create_delivery_for_order', array(
                'order_id' => $order_id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ), 'error');
            $order->add_order_note('Ошибка при создании заявки: ' . $e->getMessage());
        } catch (Error $e) {
            $this->log('FATAL ERROR in create_delivery_for_order', array(
                'order_id' => $order_id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ), 'error');
            $order->add_order_note('Критическая ошибка при создании заявки: ' . $e->getMessage());
        }
    }

    public function enqueue_admin_assets(){
        wp_enqueue_style( 'ycwc-admin', YCWC_URL . 'assets/css/admin.css', array(), YCWC_VERSION );
    }

    public function add_order_action( $actions, $order ){
        if ( ! $order instanceof WC_Order ) return $actions;
        $has_method = false;
        foreach ( $order->get_items('shipping') as $item ) {
            if ( strpos( $item->get_method_id(), 'yandex_delivery_cargo' ) !== false ) { $has_method = true; break; }
        }
        if ( $has_method ) {
            $url = wp_nonce_url(
                admin_url('admin-ajax.php?action=ycwc_create_delivery&order_id=' . $order->get_id()),
                'ycwc_create_delivery_' . $order->get_id()
            );
            $actions['ycwc_send'] = array(
                'url' => $url,
                'name' => __('Создать заявку Яндекс.Доставка', 'ycwc'),
                'action' => 'ycwc-send'
            );
        }
        return $actions;
    }

    public function ajax_create_delivery(){
        $order_id = absint( $_GET['order_id'] ?? 0 );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ycwc_create_delivery_' . $order_id ) ) {
            wp_die( __('Security check failed', 'ycwc') );
        }

        $order = wc_get_order($order_id);
        if ( ! $order ) {
            wp_die('Order not found');
        }

        $old_claim_id = (string) $order->get_meta('_ycwc_claim_id');

        $this->create_delivery_for_order($order);

        // Обновим данные заказа после возможных изменений
        $order = wc_get_order($order_id);
        $new_claim_id = (string) $order->get_meta('_ycwc_claim_id');

        $url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

        if ( $new_claim_id !== '' && $new_claim_id !== $old_claim_id ) {
            $msg = 'Заявка создана. Claim ID: ' . $new_claim_id;
        } elseif ( $new_claim_id !== '' ) {
            $msg = 'Заявка уже существует. Claim ID: ' . $new_claim_id;
        } else {
            $msg = 'Не удалось создать заявку. Подробности см. в примечаниях заказа и/или логах WooCommerce (source: ycwc).';
        }

        wp_safe_redirect( add_query_arg( array('ycwc_notice' => rawurlencode($msg)), $url ) );
        exit;
    }
}

new YCWC_Admin();

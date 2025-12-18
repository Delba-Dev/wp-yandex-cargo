<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class YCWC_Shipping_Method extends WC_Shipping_Method {

    /**
     * Подробное логирование в журнал WooCommerce
     */
    private function log($msg, $data = null, $level = 'debug'){
        // Проверяем, что заголовки еще не отправлены
        if ( headers_sent() ) {
            return;
        }
        
        if ( function_exists('wc_get_logger') ) {
            $opts = YCWC_Settings::instance()->get_options();
            if ( isset($opts['debug']) && $opts['debug'] === 'yes' ) {
                $log_msg = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
                if ($data !== null) { 
                    $log_msg .= PHP_EOL . 'DATA: ' . wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                
                $allowed_levels = array('debug', 'info', 'notice', 'warning', 'error');
                $level = in_array($level, $allowed_levels) ? $level : 'debug';
                wc_get_logger()->$level($log_msg, array('source'=>'ycwc'));
            }
        }
    }
    
    /**
     * Логирование запросов к API Яндекса
     */
    private function log_api_request($url, $headers, $body, $method = 'POST') {
        $this->log('=== API REQUEST ===', array(
            'method' => $method,
            'url' => $url,
            'headers' => $this->sanitize_log_headers($headers),
            'body' => is_string($body) ? json_decode($body, true) : $body,
            'body_raw' => is_string($body) ? $body : wp_json_encode($body),
            'timestamp' => time(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ), 'info');
    }
    
    /**
     * Логирование ответов от API Яндекса
     */
    private function log_api_response($url, $response, $response_code, $response_headers = null) {
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = null;
        
        if (!is_wp_error($response)) {
            $decoded_body = json_decode($response_body, true);
        }
        
        $this->log('=== API RESPONSE ===', array(
            'url' => $url,
            'http_code' => $response_code,
            'is_error' => is_wp_error($response),
            'error_message' => is_wp_error($response) ? $response->get_error_message() : null,
            'headers' => $response_headers ? $this->sanitize_log_headers($response_headers->getAll()) : null,
            'body' => $decoded_body,
            'body_raw' => strlen($response_body) > 10000 ? substr($response_body, 0, 10000) . '...[truncated]' : $response_body,
            'body_length' => strlen($response_body),
            'timestamp' => time()
        ), is_wp_error($response) || $response_code !== 200 ? 'error' : 'info');
    }
    
    /**
     * Очистка заголовков для логирования (убираем токены)
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
     * Улучшение форматирования адреса для геокодирования
     */
    private function improve_address_format($address_str) {
        // Убираем лишние пробелы и запятые
        $address = trim(preg_replace('/\s+/', ' ', $address_str));
        
        // Убираем лишние запятые
        $address = preg_replace('/,+/', ',', $address);
        $address = trim($address, ', ');
        
        // Если адрес начинается с "Россия", убираем это для лучшего распознавания
        // Используем mb_substr для корректной работы с UTF-8
        if (mb_strpos($address, 'Россия,') === 0) {
            $address = trim(mb_substr($address, mb_strlen('Россия,')));
        }
        
        // Если есть только город и улица, добавляем "Москва" если город не указан
        if (mb_strpos($address, ',') === false && !empty($address)) {
            $address = 'Москва, ' . $address;
        }
        
        return $address;
    }

    /**
     * Попытка геокодирования с разными вариантами адреса
     */
    private function try_geocoding_variants($address_str, $api_key) {
        $variants = array();
        
        // Оригинальный адрес (если он не испорчен)
        if (mb_strpos($address_str, '?') === false) {
            $variants[] = $address_str;
        }
        
        // Восстанавливаем адрес, если он был испорчен
        $clean_address = $address_str;
        if (mb_strpos($clean_address, '?ия,') === 0) {
            // Это значит было "Россия," но обрезалось неправильно
            // Восстанавливаем оригинальный адрес
            $clean_address = str_replace('?ия,', 'Москва,', $clean_address);
            $variants[] = $clean_address;
        }
        
        // Убираем "Россия," если есть (используем mb функции для UTF-8)
        if (mb_strpos($address_str, 'Россия,') === 0) {
            $variants[] = trim(mb_substr($address_str, mb_strlen('Россия,')));
        }
        
        // Пробуем только город и адрес без "Россия"
        if (mb_strpos($address_str, 'Москва') !== false) {
            // Извлекаем часть после "Москва"
            $parts = explode('Москва', $address_str);
            if (count($parts) > 1) {
                $after_moscow = trim($parts[1], ', ');
                if (!empty($after_moscow)) {
                    $variants[] = 'Москва, ' . $after_moscow;
                }
            }
        }
        
        // Пробуем только улицу и дом
        if (preg_match('/([^,]+,\s*\d+)/u', $address_str, $matches)) {
            $variants[] = 'Москва, ' . trim($matches[1]);
        }
        
        // Пробуем только улицу без номера дома
        if (preg_match('/([^,]+,\s*[^,]+)/u', $address_str, $matches)) {
            $street = trim($matches[1]);
            if (preg_match('/^(.+?)\s+\d+$/u', $street, $street_matches)) {
                $variants[] = 'Москва, ' . trim($street_matches[1]);
            }
        }
        
        // Если адрес содержит только название улицы
        if (mb_strpos($address_str, ',') === false && !empty($address_str)) {
            $variants[] = 'Москва, ' . $address_str;
        }
        
        // Убираем дубликаты
        $variants = array_unique($variants);
        
        $this->log('Geocoding variants prepared', array(
            'original' => $address_str,
            'variants_count' => count($variants),
            'variants' => $variants
        ));
        
        foreach ($variants as $index => $variant) {
            $this->log('Trying geocoding variant #' . $index, array('variant' => $variant));
            
            $geo_url = 'https://geocode-maps.yandex.ru/1.x/?format=json&apikey=' . urlencode($api_key) . '&geocode=' . urlencode($variant);
            $resp = wp_remote_get($geo_url, array('timeout'=>15));
            
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) == 200) {
                $data = json_decode(wp_remote_retrieve_body($resp), true);
                $found = isset($data['response']['GeoObjectCollection']['metaDataProperty']['GeocoderResponseMetaData']['found']) 
                    ? intval($data['response']['GeoObjectCollection']['metaDataProperty']['GeocoderResponseMetaData']['found']) 
                    : 0;
                
                $this->log('Geocoding variant result', array(
                    'variant' => $variant, 
                    'found' => $found,
                    'response_time_ms' => isset($resp['response_time']) ? $resp['response_time'] : null
                ));
                
                if ($found > 0 && !empty($data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'])) {
                    $pos = explode(' ', $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos']);
                    if (count($pos) === 2) {
                        $this->log('Geocoding SUCCESS with variant', array(
                            'variant' => $variant, 
                            'lon' => floatval($pos[0]), 
                            'lat' => floatval($pos[1])
                        ), 'info');
                        return array(floatval($pos[0]), floatval($pos[1]));
                    }
                }
            } else {
                $this->log('Geocoding variant request failed', array(
                    'variant' => $variant,
                    'is_wp_error' => is_wp_error($resp),
                    'error' => is_wp_error($resp) ? $resp->get_error_message() : null,
                    'http_code' => is_wp_error($resp) ? null : wp_remote_retrieve_response_code($resp)
                ));
            }
        }
        
        $this->log('All geocoding variants failed', array('variants_tried' => count($variants)), 'warning');
        return null;
    }


    /**
     * Приблизительный расчет расстояния между координатами (в км)
     */
    private function calculate_distance_approx($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 6371; // Радиус Земли в км
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return round($earth_radius * $c, 2);
    }
    
    /**
     * Проверка соответствия габаритов лимитам типа кузова
     */
    private function check_dimensions_limits($items, $cargo_type) {
        if (empty($items) || empty($cargo_type)) {
            return 'Cannot check - missing data';
        }
        
        $total_volume = 0;
        $max_length = 0;
        $max_width = 0;
        $max_height = 0;
        
        foreach ($items as $item) {
            $total_volume += $item['size']['length'] * $item['size']['width'] * $item['size']['height'];
            $max_length = max($max_length, $item['size']['length']);
            $max_width = max($max_width, $item['size']['width']);
            $max_height = max($max_height, $item['size']['height']);
        }
        
        $limits = array(
            'van' => array('length' => 1.70, 'width' => 1.00, 'height' => 0.90),
            'lcv_m' => array('length' => 2.60, 'width' => 1.30, 'height' => 1.50),
            'lcv_l' => array('length' => 3.80, 'width' => 1.80, 'height' => 1.80),
            'lcv_xl' => array('length' => 4.00, 'width' => 1.90, 'height' => 2.00),
            'lcv_xxl' => array('length' => 5.00, 'width' => 2.00, 'height' => 2.00)
        );
        
        if (!isset($limits[$cargo_type])) {
            return 'Unknown cargo type limits';
        }
        
        $type_limits = $limits[$cargo_type];
        $issues = array();
        
        if ($max_length > $type_limits['length']) {
            $issues[] = "Length {$max_length}m exceeds limit {$type_limits['length']}m";
        }
        if ($max_width > $type_limits['width']) {
            $issues[] = "Width {$max_width}m exceeds limit {$type_limits['width']}m";
        }
        if ($max_height > $type_limits['height']) {
            $issues[] = "Height {$max_height}m exceeds limit {$type_limits['height']}m";
        }
        
        return empty($issues) ? 'Dimensions OK' : implode('; ', $issues);
    }
    
    /**
     * Получение списка типов кузова для попытки
     */
    private function get_cargo_types_to_try($primary_cargo_type, $items, $total_weight) {
        $cargo_types = array('van', 'lcv_m', 'lcv_l', 'lcv_xl', 'lcv_xxl');
        
        // Находим индекс основного типа
        $primary_index = array_search($primary_cargo_type, $cargo_types);
        
        if ($primary_index === false) {
            // Если основной тип не найден, пробуем все от минимального подходящего
            $to_try = array();
            foreach ($cargo_types as $type) {
                if ($this->check_if_cargo_fits($items, $total_weight, $type)) {
                    $to_try[] = $type;
                }
            }
            return !empty($to_try) ? $to_try : array($primary_cargo_type); // Fallback на основной тип
        }
        
        // Возвращаем основной тип и все большие типы
        return array_slice($cargo_types, $primary_index);
    }
    
    /**
     * Проверка, помещается ли груз в указанный тип кузова
     */
    private function check_if_cargo_fits($items, $total_weight, $cargo_type) {
        $limits = array(
            'van' => array('weight' => 300, 'length' => 1.70, 'width' => 1.00, 'height' => 0.90),
            'lcv_m' => array('weight' => 700, 'length' => 2.60, 'width' => 1.30, 'height' => 1.50),
            'lcv_l' => array('weight' => 1400, 'length' => 3.80, 'width' => 1.80, 'height' => 1.80),
            'lcv_xl' => array('weight' => 2000, 'length' => 4.00, 'width' => 1.90, 'height' => 2.00),
            'lcv_xxl' => array('weight' => 4000, 'length' => 5.00, 'width' => 2.00, 'height' => 2.00)
        );
        
        if (!isset($limits[$cargo_type])) {
            return false;
        }
        
        $type_limits = $limits[$cargo_type];
        
        // Проверка веса
        if ($total_weight > $type_limits['weight']) {
            return false;
        }
        
        // Проверка габаритов
        $max_length = 0;
        $max_width = 0;
        $max_height = 0;
        
        foreach ($items as $item) {
            $max_length = max($max_length, $item['size']['length']);
            $max_width = max($max_width, $item['size']['width']);
            $max_height = max($max_height, $item['size']['height']);
        }
        
        return $max_length <= $type_limits['length'] && 
               $max_width <= $type_limits['width'] && 
               $max_height <= $type_limits['height'];
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

    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'yandex_delivery_cargo';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __('Яндекс Доставка (грузовая)', 'ycwc');
        $this->method_description = __('Расчет тарифа через Яндекс.Доставку (до 4 тонн).', 'ycwc');
        $this->supports           = array('shipping-zones', 'instance-settings');
        $this->enabled            = 'yes';
        $this->title              = __('Яндекс Доставка (грузовая)', 'ycwc');
        $this->init();
    }

    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', $this->title);
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'title' => array(
                'title'       => __('Название', 'ycwc'),
                'type'        => 'text',
                'description' => __('Отображаемое имя метода доставки.', 'ycwc'),
                'default'     => __('Яндекс Доставка (грузовая)', 'ycwc'),
            ),
            'base_markup' => array(
                'title'       => __('Наценка, ₽', 'ycwc'),
                'type'        => 'number',
                'description' => __('Дополнительная наценка к тарифу Яндекса.', 'ycwc'),
                'default'     => '0',
            ),
        );
    }

    public function calculate_shipping( $package = array() ) {
        $this->log('=== CALCULATE SHIPPING START ===', array(
            'package' => $package,
            'cart_total' => function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_total() : null,
            'cart_items_count' => function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : null
        ), 'info');
        
        try {
            $settings = YCWC_Settings::instance()->get_options();
            $oauth = $settings['oauth_token'];

            $this->log('Settings loaded', array(
                'has_oauth' => !empty($oauth),
                'oauth_length' => strlen($oauth),
                'has_maps_key' => !empty($settings['maps_api_key']),
                'maps_key_length' => strlen($settings['maps_api_key'] ?? ''),
                'warehouse_address' => $settings['warehouse_address'],
                'warehouse_coords' => array($settings['warehouse_lon'], $settings['warehouse_lat'])
            ));

            if ( empty( $oauth ) ) { 
                $this->log('ERROR: Empty OAuth token', null, 'error'); 
                return; 
            }
            
            // Проверяем формат OAuth токена
            if (strlen($oauth) < 20) {
                $this->log('ERROR: Invalid OAuth token format', array('length' => strlen($oauth)), 'error');
                return;
            }

        $dest = $package['destination'];
        $address_str = trim( implode(', ', array_filter([
            $dest['country'] === 'RU' ? 'Россия' : $dest['country'],
            $dest['state'],
            $dest['city'],
            $dest['address'],
            $dest['address_2'],
            $dest['postcode']
        ])));
        
        $this->log('Destination parsed', array(
            'raw_destination' => $dest,
            'formatted_address' => $address_str
        ));
        
        if ( empty($address_str) ) { 
            $this->log('ERROR: Empty address', null, 'error'); 
            return; 
        }

        // Улучшаем формат адреса для лучшего геокодирования
        $original_address = $address_str;
        $address_str = $this->improve_address_format($address_str);
        
        if ($original_address !== $address_str) {
            $this->log('Address format improved', array(
                'original' => $original_address,
                'improved' => $address_str
            ));
        }

        $wh_lon = floatval($settings['warehouse_lon']);
        $wh_lat = floatval($settings['warehouse_lat']);
        $wh_address = $settings['warehouse_address'];
        
        $this->log('Warehouse data', array(
            'address' => $wh_address,
            'lon' => $wh_lon,
            'lat' => $wh_lat,
            'valid' => !empty($wh_lon) && !empty($wh_lat)
        ));
        
        if ( empty($wh_lon) || empty($wh_lat) ) { 
            $this->log('ERROR: Empty warehouse coordinates', null, 'error'); 
            return; 
        }

        // Геокод адреса назначения
        $dest_lon = null; $dest_lat = null;
        $this->log('=== GEOCODING START ===', array('address' => $address_str), 'info');
        
        if ( ! empty( $settings['maps_api_key'] ) ) {
            // Проверяем формат API ключа
            if (strlen($settings['maps_api_key']) < 10) {
                $this->log('ERROR: Invalid Maps API key format', array('length' => strlen($settings['maps_api_key'])), 'error');
            } else {
                // Сначала пробуем оригинальный адрес
                $geo_url = 'https://geocode-maps.yandex.ru/1.x/?format=json&apikey=' . urlencode($settings['maps_api_key']) . '&geocode=' . urlencode($address_str);
                
                $this->log('Geocoding request', array(
                    'url' => $geo_url,
                    'address' => $address_str,
                    'api_key_length' => strlen($settings['maps_api_key'])
                ));
                
                $geo_start_time = microtime(true);
                $resp = wp_remote_get($geo_url, array('timeout'=>15));
                $geo_time = round((microtime(true) - $geo_start_time) * 1000, 2);
                
                if ( ! is_wp_error($resp) && wp_remote_retrieve_response_code($resp) == 200 ) {
                    $response_body = wp_remote_retrieve_body($resp);
                    $data = json_decode($response_body, true);
                    $found = isset($data['response']['GeoObjectCollection']['metaDataProperty']['GeocoderResponseMetaData']['found']) 
                        ? intval($data['response']['GeoObjectCollection']['metaDataProperty']['GeocoderResponseMetaData']['found']) 
                        : 0;
                    
                    $this->log('Geocoding response', array(
                        'http_code' => 200,
                        'found_results' => $found,
                        'response_time_ms' => $geo_time,
                        'full_response' => $data
                    ));
                    
                    if ($found > 0 && !empty($data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'])) {
                        $pos = explode(' ', $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos']);
                        if (count($pos)===2) { 
                            $dest_lon = floatval($pos[0]); 
                            $dest_lat = floatval($pos[1]); 
                            $this->log('Geocoding SUCCESS', array(
                                'lon' => $dest_lon, 
                                'lat' => $dest_lat,
                                'address' => $address_str
                            ), 'info');
                        }
                    } else {
                        $this->log('Geocoding failed: no results, trying variants', array('address' => $address_str));
                        // Пробуем варианты адреса
                        $coords = $this->try_geocoding_variants($address_str, $settings['maps_api_key']);
                        if ($coords) {
                            $dest_lon = $coords[0];
                            $dest_lat = $coords[1];
                            $this->log('Geocoding SUCCESS via variant', array(
                                'lon' => $dest_lon,
                                'lat' => $dest_lat
                            ), 'info');
                        }
                    }
                } else {
                    $this->log('Geocoding ERROR: HTTP error', array(
                        'is_wp_error' => is_wp_error($resp),
                        'wp_error_code' => is_wp_error($resp) ? $resp->get_error_code() : null,
                        'wp_error_message' => is_wp_error($resp) ? $resp->get_error_message() : null,
                        'http_code' => is_wp_error($resp) ? null : wp_remote_retrieve_response_code($resp),
                        'response_time_ms' => $geo_time
                    ), 'error');
                }
            }
        } else {
            $this->log('Geocoding skipped: no maps_api_key');
        }
        
        if ( $dest_lon === null || $dest_lat === null ) { 
            $this->log('Geocoding FAILED: using fallback or aborting', array('address' => $address_str), 'warning');
            
            // Проверяем наличие Москвы в оригинальном адресе (до улучшения)
            $has_moscow = false;
            $check_addresses = array(
                $dest['city'] ?? '',
                $dest['address'] ?? '',
                $dest['address_1'] ?? '',
                $address_str,
                $original_address ?? $address_str
            );
            
            foreach ($check_addresses as $check_addr) {
                if (!empty($check_addr) && (mb_stripos($check_addr, 'москва') !== false || mb_stripos($check_addr, 'moscow') !== false || mb_stripos($check_addr, 'мск') !== false)) {
                    $has_moscow = true;
                    break;
                }
            }
            
            // Fallback: используем координаты центра Москвы для адресов в Москве
            if ($has_moscow) {
                $dest_lon = 37.617635; // Центр Москвы
                $dest_lat = 55.755814;
                $this->log('Using FALLBACK coordinates for Moscow', array(
                    'lon' => $dest_lon, 
                    'lat' => $dest_lat,
                    'original_destination' => $dest,
                    'improved_address' => $address_str,
                    'warning' => 'Using approximate coordinates!'
                ), 'warning');
            } else {
                $this->log('ERROR: Geocoding failed - not Moscow address, aborting', array(
                    'address' => $address_str,
                    'original_destination' => $dest
                ), 'error');
                return;
            }
        }
        
        $this->log('=== GEOCODING END ===', array(
            'final_coords' => array('lon' => $dest_lon, 'lat' => $dest_lat)
        ), 'info');

        // Items из корзины
        $this->log('=== PROCESSING CART ITEMS ===', null, 'info');
        $items = array();
        $total_weight = 0;
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');
        $dimension_unit = get_option('woocommerce_dimension_unit', 'cm');
        
        $this->log('Units configuration', array(
            'weight_unit' => $weight_unit,
            'dimension_unit' => $dimension_unit
        ));
        
        if ( function_exists('WC') && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                $product = $cart_item['data'];
                $qty = (int)$cart_item['quantity'];
                
                $raw_weight = floatval($product->get_weight() ?: 0);
                $raw_length = floatval($product->get_length() ?: 0);
                $raw_width = floatval($product->get_width() ?: 0);
                $raw_height = floatval($product->get_height() ?: 0);
                
                $weight = $this->convert_to_kg($raw_weight, $weight_unit) * $qty;
                $length = $this->convert_to_meters($raw_length, $dimension_unit);
                $width  = $this->convert_to_meters($raw_width, $dimension_unit);
                $height = $this->convert_to_meters($raw_height, $dimension_unit);
                $total_weight += $weight;

                $item_data = array(
                    'quantity' => max(1,$qty),
                    'weight' => max(0.1, floatval($weight)),
                    'size' => array(
                        'length' => max(0.01, $length),
                        'width' => max(0.01, $width),
                        'height' => max(0.01, $height),
                    ),
                    'pickup_point' => 1,
                    'dropoff_point' => 2,
                );
                
                $items[] = $item_data;
                
                $this->log('Cart item processed', array(
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
        }
        
        $this->log('Cart items summary', array(
            'items_count' => count($items),
            'total_weight_kg' => $total_weight,
            'items' => $items
        ), 'info');
        
        if ($total_weight > 4000) { 
            $this->log('ERROR: Overweight - exceeds 4000kg limit', array(
                'total_weight_kg' => $total_weight,
                'limit_kg' => 4000
            ), 'error'); 
            return; 
        }
        
        if (empty($items)) {
            $this->log('ERROR: Empty cart - no items to ship', null, 'error');
            return;
        }

        // Вычисляем тип кузова автоматически
        $this->log('=== CALCULATING CARGO TYPE ===', array('items_count' => count($items)), 'info');
        $cargo_type = $this->calculate_cargo_type($items);
        
        if ($cargo_type === null) {
            $this->log('ERROR: Cargo too large for any vehicle type', array(
                'total_weight_kg' => $total_weight,
                'items' => $items
            ), 'error');
            return;
        }
        
        $this->log('Cargo type determined', array(
            'cargo_type' => $cargo_type,
            'total_weight_kg' => $total_weight
        ), 'info');

        // Пробуем разные типы кузова, если первый не дал результата
        $cargo_types_to_try = $this->get_cargo_types_to_try($cargo_type, $items, $total_weight);
        
        $this->log('Cargo types to try', array(
            'primary' => $cargo_type,
            'all_to_try' => $cargo_types_to_try
        ), 'info');

        // Пробуем каждый тип кузова
        $price = null;
        $offer_payload = null;
        $successful_cargo_type = null;
        
        foreach ($cargo_types_to_try as $try_cargo_type) {
            $this->log('=== TRYING CARGO TYPE ===', array('cargo_type' => $try_cargo_type), 'info');
            
            $body = array(
            'route_points' => array(
                array(
                    'id' => 1,
                    'coordinates' => array( floatval($wh_lon), floatval($wh_lat) ),
                    'fullname' => $wh_address,
                ),
                array(
                    'id' => 2,
                    'coordinates' => array( $dest_lon, $dest_lat ),
                    'fullname' => $address_str,
                ),
            ),
            'items' => $items,
            'requirements' => array(
                'taxi_classes' => array('cargo'),
                'cargo_type' => $try_cargo_type,
                'cargo_loaders' => 0,
                'skip_door_to_door' => false,
            ),
        );

            $url = 'https://b2b.taxi.yandex.net/b2b/cargo/integration/v2/offers/calculate';
            
            $this->log('=== YANDEX API REQUEST PREPARATION ===', array(
                'url' => $url,
                'cargo_type_trying' => $try_cargo_type,
                'request_body' => $body,
                'body_size_bytes' => strlen(wp_json_encode($body))
            ), 'info');
            
            $headers = array(
                'Authorization' => 'Bearer ' . $oauth,
                'Content-Type'  => 'application/json',
                'Accept-Language' => 'ru-RU'
            );
            
            $this->log_api_request($url, $headers, $body);
            
            $api_start_time = microtime(true);
            $response = wp_remote_post( $url, array(
                'timeout' => 25,
                'headers' => $headers,
                'body' => wp_json_encode($body)
            ));
            $api_time = round((microtime(true) - $api_start_time) * 1000, 2);
            
            if ( is_wp_error($response) ) { 
                $this->log('ERROR: WP_Error in API request', array(
                    'cargo_type' => $try_cargo_type,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                    'error_data' => $response->get_error_data(),
                    'response_time_ms' => $api_time
                ), 'error'); 
                continue; // Пробуем следующий тип
            }
            
            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $response_headers = wp_remote_retrieve_headers($response);
            
            $this->log_api_response($url, $response, $code, $response_headers);
            
            $this->log('API response details', array(
                'cargo_type' => $try_cargo_type,
                'http_code' => $code,
                'response_time_ms' => $api_time,
                'body_length' => strlen($response_body)
            ));
            
            if ( $code !== 200 ) { 
                $decoded_response = json_decode($response_body, true);
                $this->log('ERROR: API returned non-200 status', array(
                    'cargo_type' => $try_cargo_type,
                    'http_code' => $code,
                    'response_body' => $decoded_response
                ), 'error');
                continue; // Пробуем следующий тип
            }

            $this->log('=== PROCESSING API RESPONSE ===', array('cargo_type' => $try_cargo_type), 'info');
            $data = json_decode($response_body, true);
        
            $this->log('Response parsed', array(
                'cargo_type' => $try_cargo_type,
                'has_data' => !empty($data),
                'data_structure' => array_keys($data ?? array()),
                'full_response' => $data
            ));
            
            // Проверяем наличие ошибок в ответе
            if (isset($data['code']) || isset($data['message'])) {
                $this->log('ERROR: API returned error in response', array(
                    'cargo_type' => $try_cargo_type,
                    'error_code' => $data['code'] ?? null,
                    'error_message' => $data['message'] ?? null,
                    'full_response' => $data
                ), 'error');
                continue; // Пробуем следующий тип
            }
            
            $try_offers_processed = 0;
            
            if ( ! empty($data['offers']) ) {
                $this->log('Processing offers for cargo_type ' . $try_cargo_type, array(
                    'offers_count' => count($data['offers'])
                ));
                
                foreach( $data['offers'] as $index => $offer ){
                    $try_offers_processed++;
                    $this->log('Processing offer #' . $index . ' for ' . $try_cargo_type, array(
                        'offer' => $offer,
                        'has_price' => isset($offer['price']['total_price']),
                        'price' => isset($offer['price']['total_price']) ? floatval($offer['price']['total_price']) : null
                    ));
                    
                    if ( isset($offer['price']['total_price']) ) {
                        $p = floatval($offer['price']['total_price']);
                        if ($price === null || $p < $price) {
                            $old_price = $price;
                            $price = $p;
                            $offer_payload = isset($offer['offer']['payload']) ? $offer['offer']['payload'] : null;
                            $successful_cargo_type = $try_cargo_type;
                            
                            $this->log('Best offer updated', array(
                                'cargo_type' => $try_cargo_type,
                                'old_price' => $old_price,
                                'new_price' => $price,
                                'has_payload' => !empty($offer_payload),
                                'payload_length' => !empty($offer_payload) ? strlen($offer_payload) : 0
                            ), 'info');
                        }
                    }
                }
                
                // Если нашли offers, можем остановиться или продолжить для поиска лучшей цены
                if ($price !== null && $try_cargo_type === $cargo_type) {
                    // Если это основной тип и есть offers, можно остановиться
                    $this->log('Found offers for primary cargo type, stopping search', array(
                        'cargo_type' => $try_cargo_type,
                        'price' => $price
                    ), 'info');
                    break;
                }
            } else {
                // Детальный анализ почему нет offers для этого типа
                $this->log('WARNING: No offers for cargo_type ' . $try_cargo_type, array(
                    'cargo_type' => $try_cargo_type,
                    'response_data' => $data,
                    'check_result' => $this->check_dimensions_limits($items, $try_cargo_type)
                ), 'warning');
                // Продолжаем пробовать другие типы
            }
        }
        
        // Если не нашли ни одного предложения
        if ( $price === null ) { 
            $this->log('ERROR: No valid offers found for any cargo type', array(
                'cargo_types_tried' => $cargo_types_to_try,
                'request_info' => array(
                    'total_weight' => $total_weight,
                    'items_count' => count($items),
                    'warehouse' => array('lon' => $wh_lon, 'lat' => $wh_lat, 'address' => $wh_address),
                    'destination' => array('lon' => $dest_lon, 'lat' => $dest_lat, 'address' => $address_str),
                    'route_distance_approx_km' => $this->calculate_distance_approx($wh_lat, $wh_lon, $dest_lat, $dest_lon)
                ),
                'items_details' => array_map(function($item) {
                    return array(
                        'weight' => $item['weight'],
                        'dimensions' => $item['size'],
                        'volume_m3' => $item['size']['length'] * $item['size']['width'] * $item['size']['height']
                    );
                }, $items)
            ), 'error'); 
            return; 
        }
        
        $this->log('=== SUCCESSFUL CARGO TYPE FOUND ===', array(
            'cargo_type' => $successful_cargo_type,
            'price' => $price
        ), 'info');

        $markup = floatval( $this->get_option('base_markup', 0) );
        $final = max( 0, $price + $markup );
        
        $this->log('Price calculation', array(
            'yandex_price' => $price,
            'markup' => $markup,
            'final_price' => $final
        ), 'info');

        if ( $offer_payload ) {
            if ( function_exists('WC') && WC()->session ) {
                WC()->session->set('ycwc_offer_payload', $offer_payload);
                WC()->session->set('ycwc_dest_coords', array('lon'=>$dest_lon, 'lat'=>$dest_lat, 'address'=>$address_str));
                
                $this->log('Session data saved', array(
                    'has_payload' => !empty($offer_payload),
                    'coords_saved' => true
                ));
            }
        }

        $rate = array(
            'id'    => $this->id . ':' . md5('base'),
            'label' => $this->title,
            'cost'  => $final,
            'meta_data' => array(
                'ycwc_price' => $price,
                'ycwc_payload' => $offer_payload
            )
        );
        
        $this->log('=== ADDING SHIPPING RATE ===', array(
            'rate_id' => $rate['id'],
            'rate_label' => $rate['label'],
            'rate_cost' => $rate['cost'],
            'meta_data' => $rate['meta_data']
        ), 'info');
        
        $this->add_rate($rate);
        
        $this->log('=== CALCULATE SHIPPING END - SUCCESS ===', array(
            'final_price' => $final,
            'yandex_price' => $price,
            'markup' => $markup,
            'cargo_type_used' => $successful_cargo_type,
            'original_cargo_type' => $cargo_type
        ), 'info');
        
        } catch (Exception $e) {
            $this->log('EXCEPTION in calculate_shipping', array(
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ), 'error');
        } catch (Error $e) {
            $this->log('FATAL ERROR in calculate_shipping', array(
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ), 'error');
        }
    }
}

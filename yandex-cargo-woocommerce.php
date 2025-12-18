<?php
/**
 * Plugin Name: Yandex Cargo for WooCommerce
 * Description: Грузовая доставка Яндекс.Доставкой до 4 тонн: расчет тарифа и отправка заявки из заказа WooCommerce.
 * Author: Delba
 * Author URI: https://delba.ru
 * Plugin URI: https://github.com/Delba-Dev/wp-yandex-cargo
 * Version: 1.0.6
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: ycwc
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Отладочное логирование отключено для избежания ошибок заголовков

define( 'YCWC_VERSION', '1.0.6' );
define( 'YCWC_PATH', plugin_dir_path( __FILE__ ) );
define( 'YCWC_URL', plugin_dir_url( __FILE__ ) );

require_once YCWC_PATH . 'includes/class-ycwc-settings.php';
require_once YCWC_PATH . 'includes/class-ycwc-admin.php';

// Инициализируем настройки сразу, чтобы страница настроек существовала
YCWC_Settings::instance();

/**
 * Bootstrap
 */
add_action('plugins_loaded', function() {
    // error_log('YCWC: Plugin loaded, checking WooCommerce...');
    if ( class_exists( 'WooCommerce' ) ) {
        // error_log('YCWC: WooCommerce found, registering shipping method...');
        // Отложенная загрузка класса метода доставки
        add_action('woocommerce_shipping_init', function(){
            // error_log('YCWC: Loading shipping method class...');
            require_once YCWC_PATH . 'includes/class-ycwc-shipping-method.php';
        });
        add_filter('woocommerce_shipping_methods', function($methods){
            $methods['yandex_delivery_cargo'] = 'YCWC_Shipping_Method';
            // error_log('YCWC: Shipping method registered: yandex_delivery_cargo');
            return $methods;
        });
        // Переводы (languages/) не включены в минимальную публичную версию
        // load_plugin_textdomain('ycwc', false, dirname(plugin_basename(__FILE__)) . '/languages');
    } else {
        // error_log('YCWC: WooCommerce not found!');
    }
});

/**
 * Скрипты чекаута (карта)
 */
add_action('wp_enqueue_scripts', function(){
    if ( function_exists('is_checkout') && is_checkout() ) {
        $opts = YCWC_Settings::instance()->get_options();
        
        // Загружаем скрипт карты только если есть API ключ
        if ( ! empty( $opts['maps_api_key'] ) ) {
            wp_enqueue_script(
                'ycwc-ymaps',
                'https://api-maps.yandex.ru/2.1/?apikey=' . urlencode($opts['maps_api_key']) . '&lang=ru_RU',
                array(), YCWC_VERSION, true
            );
        }
        
        // Всегда загружаем наш скрипт, но он сам проверит наличие API ключа
        wp_enqueue_script(
            'ycwc-checkout-map',
            YCWC_URL . 'assets/js/checkout-map.js',
            array('jquery'), YCWC_VERSION, true
        );
        
        // Передаем данные в JavaScript
        wp_localize_script('ycwc-checkout-map', 'YCWC_DATA', array(
            'warehouse_address' => $opts['warehouse_address'],
            'warehouse_lon' => $opts['warehouse_lon'],
            'warehouse_lat' => $opts['warehouse_lat'],
            'has_maps_key' => ! empty( $opts['maps_api_key'] ),
        ));

        wp_enqueue_style( 'ycwc-frontend', YCWC_URL . 'assets/css/frontend.css', array(), YCWC_VERSION );
    }
});

/**
 * Ссылка "Настройки" в списке плагинов
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
    $url = admin_url('admin.php?page=ycwc-settings');
    $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Настройки', 'ycwc') . '</a>';
    return $links;
});

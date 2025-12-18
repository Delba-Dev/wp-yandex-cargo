<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class YCWC_Settings {
    private static $instance = null;
    private $option_key = 'ycwc_options';
    private $defaults = array(
        'oauth_token' => '',
        'maps_api_key' => '',
        'warehouse_address' => '',
        'warehouse_lon' => '',
        'warehouse_lat' => '',
        'sender_phone' => '+7',
        'test_mode' => 'yes',
        'debug' => 'no',
        'auto_create_delivery' => 'no',
    );

    public static function instance(){
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    public function get_options(){
        $opts = get_option($this->option_key, array());
        return wp_parse_args($opts, $this->defaults);
    }

    public function __construct(){
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_menu(){
        add_menu_page(
            __('Yandex Cargo', 'ycwc'),
            __('Yandex Cargo', 'ycwc'),
            'manage_options',
            'ycwc-settings',
            array($this, 'render_settings_page'),
            'dashicons-admin-site',
            56
        );
    }

    public function register_settings(){
        register_setting('ycwc_settings_group', $this->option_key, array($this, 'sanitize'));

        add_settings_section('ycwc_main', __('Основные настройки', 'ycwc'), function(){
            echo '<p>' . esc_html__('Введите токены и адрес склада.', 'ycwc') . '</p>';
        }, 'ycwc-settings');

        add_settings_field('oauth_token', __('OAuth токен Яндекс.Доставки', 'ycwc'), array($this, 'field_oauth'), 'ycwc-settings', 'ycwc_main');
        add_settings_field('maps_api_key', __('API ключ Яндекс.Карт', 'ycwc'), array($this, 'field_maps'), 'ycwc-settings', 'ycwc_main');
        add_settings_field('warehouse_address', __('Адрес склада (Москва)', 'ycwc'), array($this, 'field_address'), 'ycwc-settings', 'ycwc_main');
        add_settings_field('warehouse_coords', __('Координаты склада (lon, lat)', 'ycwc'), array($this, 'field_coords'), 'ycwc-settings', 'ycwc_main');
        add_settings_field('sender_phone', __('Телефон отправителя', 'ycwc'), array($this, 'field_sender_phone'), 'ycwc-settings', 'ycwc_main');
        add_settings_field('debug', __('Логирование (debug)', 'ycwc'), array($this, 'field_debug'), 'ycwc-settings', 'ycwc_main');
        add_settings_field('auto_create_delivery', __('Автосоздание заявок', 'ycwc'), array($this, 'field_auto_create'), 'ycwc-settings', 'ycwc_main');
    }

    public function sanitize($input){
        $input['oauth_token'] = sanitize_text_field($input['oauth_token'] ?? '');
        $input['maps_api_key'] = sanitize_text_field($input['maps_api_key'] ?? '');
        $input['warehouse_address'] = sanitize_text_field($input['warehouse_address'] ?? '');
        $input['warehouse_lon'] = sanitize_text_field($input['warehouse_lon'] ?? '');
        $input['warehouse_lat'] = sanitize_text_field($input['warehouse_lat'] ?? '');
        $input['sender_phone'] = sanitize_text_field($input['sender_phone'] ?? '+7');
        $input['debug'] = ! empty($input['debug']) ? 'yes' : 'no';
        $input['auto_create_delivery'] = ! empty($input['auto_create_delivery']) ? 'yes' : 'no';
        return $input;
    }

    public function render_settings_page(){
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Yandex Cargo — настройки', 'ycwc'); ?></h1>
            
            <!-- Статус плагина -->
            <div class="notice notice-info">
                <h3>Статус плагина:</h3>
                <ul>
                    <li><strong>WooCommerce:</strong> <?php echo class_exists('WooCommerce') ? '✅ Активен' : '❌ Не найден'; ?></li>
                    <li><strong>Метод доставки:</strong> <?php echo class_exists('YCWC_Shipping_Method') ? '✅ Загружен' : '❌ Не загружен'; ?></li>
                    <li><strong>Версия плагина:</strong> <?php echo YCWC_VERSION; ?></li>
                </ul>
            </div>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('ycwc_settings_group');
                do_settings_sections('ycwc-settings');
                submit_button();
                ?>
            </form>
            <hr/>
            <p><strong><?php esc_html_e('Подсказка:', 'ycwc'); ?></strong>
                <?php esc_html_e('Укажите адрес и координаты склада. Формат: долгота, широта (пример: 37.617635, 55.755814).', 'ycwc'); ?>
            </p>
        </div>
        <?php
    }

    public function field_oauth(){
        $v = esc_attr($this->get_options()['oauth_token']);
        echo '<input type="text" style="width:480px" name="'.$this->option_key.'[oauth_token]" value="'.$v.'" placeholder="Bearer OAuth token">';
    }
    public function field_maps(){
        $v = esc_attr($this->get_options()['maps_api_key']);
        echo '<input type="text" style="width:480px" name="'.$this->option_key.'[maps_api_key]" value="'.$v.'" placeholder="Yandex Maps API key">';
    }
    public function field_address(){
        $v = esc_attr($this->get_options()['warehouse_address']);
        echo '<input type="text" style="width:480px" name="'.$this->option_key.'[warehouse_address]" value="'.$v.'" placeholder="Москва, ...">';
    }
    public function field_coords(){
        $lon = esc_attr($this->get_options()['warehouse_lon']);
        $lat = esc_attr($this->get_options()['warehouse_lat']);
        echo '<input type="text" style="width:220px" name="'.$this->option_key.'[warehouse_lon]" value="'.$lon.'" placeholder="долгота">';
        echo ' ';
        echo '<input type="text" style="width:220px" name="'.$this->option_key.'[warehouse_lat]" value="'.$lat.'" placeholder="широта">';
    }
    public function field_debug(){
        $v = $this->get_options()['debug'];
        echo '<label><input type="checkbox" name="'.$this->option_key.'[debug]" '.checked($v,'yes',false).'> ' . esc_html__('Писать логи в WooCommerce → Статус → Логи (ycwc)', 'ycwc') . '</label>';
    }
    public function field_sender_phone(){
        $v = esc_attr($this->get_options()['sender_phone']);
        echo '<input type="text" style="width:480px" name="'.$this->option_key.'[sender_phone]" value="'.$v.'" placeholder="+7 (977) 785 44 58">';
        echo '<p class="description">' . esc_html__('Телефон отправителя для заявок в Яндекс.Доставке', 'ycwc') . '</p>';
    }
    public function field_auto_create(){
        $v = $this->get_options()['auto_create_delivery'];
        echo '<label><input type="checkbox" name="'.$this->option_key.'[auto_create_delivery]" '.checked($v,'yes',false).'> ' . esc_html__('Автоматически создавать заявку при оформлении заказа', 'ycwc') . '</label>';
        echo '<p class="description">' . esc_html__('Заявка будет создана сразу после оформления заказа с Яндекс.Доставкой', 'ycwc') . '</p>';
    }
}

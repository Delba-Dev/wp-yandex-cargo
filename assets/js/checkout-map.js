(function($){
    'use strict';
    
    // Функция для добавления ~ к цене и пояснительного текста
    function addTildeToShippingPrice() {
        $('input[value*="yandex_delivery_cargo"]').each(function() {
            var $input = $(this);
            var $label = $input.closest('li').find('label');
            var labelText = $label.html();
            
            // Проверяем, есть ли уже символ "~"
            if (labelText.indexOf('~') === -1) {
                // Добавляем символ "~" перед ценой
                labelText = labelText.replace(/(\d+.*₽)/, '~$1');
                $label.html(labelText);
            }
            
            // Добавляем пояснительный текст, если его еще нет
            if (labelText.indexOf('Цена может изменяться') === -1) {
                var $li = $input.closest('li');
                if ($li.find('.ycwc-price-note').length === 0) {
                    $li.append('<div class="ycwc-price-note">Цена может изменяться в зависимости от загруженности и других факторов Яндекса.</div>');
                }
            }
        });
    }
    
    function initMapIfReady(){
        // Проверяем наличие данных
        if (!window.YCWC_DATA) {
            return;
        }
        
        var hasKey = !!YCWC_DATA.has_maps_key;
        
        // Если нет API ключа, не загружаем карту
        if (!hasKey) {
            return;
        }
        
        // Проверяем наличие ymaps
        if (typeof ymaps === 'undefined') {
            return;
        }

        // Проверяем, не инициализирована ли карта уже
        if ($('#ycwc-map').length > 0) {
            return;
        }

        try {
            var $container = $('<div id="ycwc-map"></div>');
            var $title = $('<h4 style="margin-top:10px;">Проверка адреса на карте (Яндекс)</h4>');
            var $wrap = $('<div id="ycwc-map-wrap"></div>');
            $wrap.append($title).append($container);
            var target = $('#order_review, .woocommerce-billing-fields').first();
            if (target.length) {
                target.append($wrap);
            }

            ymaps.ready(function(){
                try {
                    var center = [YCWC_DATA.warehouse_lat || 55.751244, YCWC_DATA.warehouse_lon || 37.618423];
                    var map = new ymaps.Map('ycwc-map', { 
                        center: center, 
                        zoom: 10, 
                        controls: ['zoomControl'] 
                    });
                    var pin = new ymaps.Placemark(center, { 
                        balloonContent: 'Склад' 
                    }, { 
                        preset: 'islands#dotIcon' 
                    });
                    map.geoObjects.add(pin);

                    var geocodeAddr = function(){
                        try {
                            var addrFull = [
                                $('#shipping_country').val() || $('#billing_country').val(),
                                $('#shipping_state').val() || $('#billing_state').val(),
                                $('#shipping_city').val() || $('#billing_city').val(),
                                $('#shipping_address_1').val() || $('#billing_address_1').val(),
                                $('#shipping_address_2').val() || $('#billing_address_2').val(),
                                $('#shipping_postcode').val() || $('#billing_postcode').val()
                            ].filter(Boolean).join(', ');
                            
                            if (!addrFull) return;
                            
                            // Очищаем карту от старых меток (кроме базовой метки склада)
                            map.geoObjects.removeAll();
                            
                            // Добавляем обратно базовую метку склада
                            var wh = [YCWC_DATA.warehouse_lat || 55.751244, YCWC_DATA.warehouse_lon || 37.618423];
                            var pin = new ymaps.Placemark(wh, { 
                                balloonContent: 'Склад' 
                            }, { 
                                preset: 'islands#dotIcon' 
                            });
                            map.geoObjects.add(pin);
                            
                            ymaps.geocode(addrFull).then(function(res){
                                try {
                                    var obj = res.geoObjects.get(0);
                                    if (!obj) return;
                                    var coords = obj.geometry.getCoordinates();
                                    map.setCenter(coords, 12);
                                    var mark = new ymaps.Placemark(coords, { 
                                        balloonContent: 'Адрес доставки' 
                                    }, { 
                                        preset: 'islands#blueStretchyIcon' 
                                    });
                                    map.geoObjects.add(mark);
                                } catch (e) {
                                    // Ошибка геокодирования
                                }
                            });
                        } catch (e) {
                            // Ошибка функции геокодирования
                        }
                    };

                    $('#shipping_city, #billing_city, #shipping_address_1, #billing_address_1, #shipping_postcode, #billing_postcode').on('change blur', geocodeAddr);
                    geocodeAddr();
                } catch (e) {
                    // Ошибка инициализации карты
                }
            });
        } catch (e) {
            // Ошибка настройки карты
        }
    }

    // Инициализация с задержкой для избежания конфликтов
    $(document).ready(function(){
        setTimeout(initMapIfReady, 1000);
        
        // Добавляем символ "~" при загрузке страницы
        setTimeout(addTildeToShippingPrice, 1000);
    });
    
    // Добавляем символ "~" после обновления чекаута
    $(document).on('updated_checkout', function() {
        setTimeout(addTildeToShippingPrice, 500);
    });
    
    // Добавляем символ "~" при изменении метода доставки
    $(document).on('change', 'input[name*="shipping_method"]', function() {
        setTimeout(addTildeToShippingPrice, 100);
    });
})(jQuery);


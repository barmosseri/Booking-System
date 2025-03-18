function create_woocommerce_order_from_elementor_form($record, $handler) {
    error_log('Elementor form submission detected.');
    $form_id = $record->get_form_settings('id');
    error_log('Submitted Form ID: ' . $form_id);
    if ('XXX' !== $form_id) {
        error_log('Form ID does not match.');
        return;
    }
    error_log('Form ID matched.');

    $raw_fields = $record->get('fields');
    error_log(print_r($raw_fields, true));
    $fields = [];
    foreach ($raw_fields as $key => $field) {
        $fields[$key] = isset($field['value']) ? sanitize_text_field($field['value']) : '';
    }
	
    if (
        !isset($fields['email']) || trim($fields['email']) === '' ||
        !isset($fields['full_name']) || trim($fields['full_name']) === '' ||
        !isset($fields['phone']) || trim($fields['phone']) === ''
    ) {
        error_log('Missing required fields: email, full_name, or phone.');
        return;
    }

    $full_name = $fields['full_name'];
    $name_parts = explode(' ', $full_name, 2);
    $first_name = $name_parts[0];
    $last_name  = isset($name_parts[1]) ? $name_parts[1] : '';
    $customer_id = email_exists($fields['email']);
    if ($customer_id) {
        $customer = new WC_Customer($customer_id);
    } else {
        $customer = new WC_Customer();
        $customer->set_email($fields['email']);
        $customer->set_first_name($first_name);
        $customer->set_last_name($last_name);
        $customer->set_billing_phone($fields['phone']);
        $customer->save();
    }

    $order = wc_create_order(['customer_id' => $customer->get_id()]);
    $product_id = XXX;
    $product = wc_get_product($product_id);
    if ($product) {
        $order->add_product($product, 1);
    } else {
        error_log('Product not found.');
    }

    $booking_cost = floatval($fields['my_hidden_cost_field']);
    if ($booking_cost > 0) {
        $fee = new WC_Order_Item_Fee();
        $fee->set_name('עלות הזמנה');
        $fee->set_amount($booking_cost);
        $fee->set_total($booking_cost);
        $order->add_item($fee);
    }

    if (!empty($fields['my_hidden_date_field'])) {
        $order->update_meta_data('booking_dates', $fields['my_hidden_date_field']);
		$dates_array = [];
    $dates_range = explode(' עד ', $fields['my_hidden_date_field']);
    
    if (count($dates_range) === 2) {
        $start_date = new DateTime(trim($dates_range[0]));
        $end_date = new DateTime(trim($dates_range[1]));

        while ($start_date <= $end_date) {
            $dates_array[] = $start_date->format('Y-m-d');
            $start_date->modify('+1 day');
        }
    } else {
        $dates_array[] = trim($fields['my_hidden_date_field']);
    }

    error_log('Parsed booking dates: ' . print_r($dates_array, true));

    $response = wp_remote_post(
        home_url('/wp-json/booking/v1/update-blocked-dates'),
        [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => json_encode(['blocked_dates' => $dates_array]),
            'timeout'   => 10,
        ]
    );

    if (is_wp_error($response)) {
        error_log('Failed to update blocked dates: ' . $response->get_error_message());
    } else {
        error_log('Blocked dates updated successfully.');
    }
    }
    if (!empty($fields['guests_number'])) {
        $order->update_meta_data('guests_number', $fields['guests_number']);
    }
    if (!empty($fields['about_you'])) {
        $order->update_meta_data('booking_notes', $fields['about_you']);
    }

    $billing_address = [
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'email'      => $fields['email'],
        'phone'      => $fields['phone'],
    ];
    $order->set_address($billing_address, 'billing');
    $order->calculate_totals();
    $order->update_status('on-hold', 'Order created from Elementor form submission.', true);
    $order_note = "פרטי הזמנה:\nתאריכים: {$fields['my_hidden_date_field']}\nמספר אורחים: {$fields['guests_number']}\nקצת על מטרת הנסיעה: {$fields['about_you']}\nעלות: {$fields['my_hidden_cost_field']}";
    $order->add_order_note($order_note);
    if (isset(WC()->mailer()->emails['WC_Email_New_Order'])) {
        WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id());
    }

    error_log('Order created successfully: ' . $order->get_id());
}
add_action('elementor_pro/forms/new_record', 'create_woocommerce_order_from_elementor_form', 10, 2);

add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status) {
    if (in_array($new_status, ['cancelled', 'refunded'])) {
        $order = wc_get_order($order_id);
        $booking_dates = $order->get_meta('booking_dates');

        if (!empty($booking_dates)) {
            $dates_array = explode(',', $booking_dates);
            error_log('Releasing blocked dates: ' . print_r($dates_array, true));

            $response = wp_remote_post(
                home_url('/wp-json/booking/v1/update-blocked-dates'),
                [
                    'method'    => 'POST',
                    'headers'   => ['Content-Type' => 'application/json'],
                    'body'      => json_encode(['blocked_dates' => $dates_array, 'release' => true]),
                    'timeout'   => 10,
                ]
            );

            if (is_wp_error($response)) {
                error_log('Failed to release blocked dates: ' . $response->get_error_message());
            } else {
                error_log('Blocked dates released successfully.');
            }
        }
    }
}, 10, 3);

function load_easepick_admin_assets() {
    wp_enqueue_style('easepick-css', 'https://cdn.jsdelivr.net/npm/@easepick/bundle@1.2.0/dist/index.css');
    wp_enqueue_script('easepick-js', 'https://cdn.jsdelivr.net/npm/@easepick/bundle@1.2.0/dist/index.umd.min.js', array('jquery'), null, true);
}
add_action('admin_enqueue_scripts', 'load_easepick_admin_assets');

add_action('rest_api_init', function () {
    register_rest_route('booking/v1', '/blocked-dates', [
        'methods'  => 'GET',
        'callback' => 'get_blocked_dates_api',
        'permission_callback' => '__return_true'
    ]);
    register_rest_route('booking/v1', '/update-blocked-dates', [
        'methods'  => ['POST', 'GET'],
        'callback' => 'update_blocked_dates_api'
    ]);
});

function get_blocked_dates_api(WP_REST_Request $request) {
    $blocked_dates = get_option('blocked_dates', []);
    if (!is_array($blocked_dates)) {
        $blocked_dates = [];
    }
    $blocked_dates = array_values($blocked_dates);
    return rest_ensure_response(['blocked_dates' => $blocked_dates]);
}

function update_blocked_dates_api(WP_REST_Request $request) {
    $new_blocked = $request->get_param('blocked_dates');
    if (!is_array($new_blocked)) {
        return new WP_Error('invalid_data', 'Blocked dates must be an array', ['status' => 400]);
    }
    $today = date('Y-m-d');
    $filtered = array_filter($new_blocked, function($d) use ($today) {
        return $d >= $today;
    });
    update_option('blocked_dates', $filtered);
    if (defined('LSCWP_V')) {
        do_action('litespeed_purge_all');
    }

    return rest_ensure_response(['blocked_dates' => array_values($filtered)]);
}

add_action('admin_menu', 'register_booking_calendar_admin_page');
function register_booking_calendar_admin_page() {
    add_menu_page(
        'ניהול יומן פאפוס', 
        'ניהול יומן פאפוס', 
        'manage_woocommerce', 
        'booking-calendar-management', 
        'render_booking_calendar_admin_page',
        'dashicons-calendar-alt',
        56
    );
}
function render_booking_calendar_admin_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized user');
    }
    ?>
    <div class="wrap">
        <h1>ניהול יומן פאפוס</h1>
        <div id="admin-easepick-calendar" style="max-width: 350px;"></div>
        <p id="selected-dates-display" style="margin-top:10px; font-weight:bold;"></p>
        <button id="lock-dates-btn" class="button button-primary">נעילת תאריכים</button>
        <button id="unlock-dates-btn" class="button button-secondary">שחרור תאריכים</button>
    </div>
    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function () {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const DateTime = easepick.DateTime;
    function processBlockedDates(dates) {
        return dates.map(d => new DateTime(d, 'YYYY-MM-DD'));
    }

    async function fetchBlockedDates() {
        try {
            const response = await fetch('/wp-json/booking/v1/blocked-dates');
            const data = await response.json();
            return data.blocked_dates || [];
        } catch (error) {
            console.error("Error fetching blocked dates:", error);
            return [];
        }
    }

    async function updateBlockedDates(selectedDates) {
    if (!Array.isArray(selectedDates)) {
        console.error("Error: selectedDates is not an array!", selectedDates);
        return;
    }
    console.log("📤 Sending blocked dates:", selectedDates);
    const response = await fetch('/wp-json/booking/v1/update-blocked-dates', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ blocked_dates: selectedDates })
    });
    const data = await response.json();
    console.log("✅ Blocked dates updated:", data);
	setTimeout(() => {
            location.reload();
        }, 4000);
}

    let adminCalendar = new easepick.create({
    element: document.getElementById('admin-easepick-calendar'),
    inline: true,
    css: ['https://cdn.jsdelivr.net/npm/@easepick/bundle@1.2.0/dist/index.css'],
    plugins: ['RangePlugin', 'LockPlugin'],
    RangePlugin: {
        tooltipNumber(num) {
            return num - 1;
        }
    },
    LockPlugin: {
        minDate: today,
        filter(date, picked) {
            if (window.processedBookedDates?.length > 0) {
                return date.inArray(window.processedBookedDates, '[)');
            }
            return false;
        }
    },
    minDate: today,
    lang: 'he-IL',
    locale: 'he'
});

async function refreshAdminCalendar() {
    const blockedDates = await fetchBlockedDates();
    const todayStr = today.toISOString().split('T')[0];
    window.processedBookedDates = processBlockedDates(blockedDates.filter(date => date >= todayStr));
    adminCalendar.updateOptions({
        LockPlugin: { filters: window.processedBookedDates }
    });
}

    let selectedDates = [];
    adminCalendar.on('select', (e) => {
        const { start, end } = e.detail;
        selectedDates = [];
        if (!end) {
            selectedDates.push(start.format('YYYY-MM-DD'));
        } else {
            let current = new Date(start);
            while (current <= new Date(end)) {
                selectedDates.push(new DateTime(current).format('YYYY-MM-DD'));
                current.setDate(current.getDate() + 1);
            }
        }
        document.getElementById('selected-dates-display').innerText = "תאריכים נבחרים: " + selectedDates.join(', ');
    });

    async function refreshAdminCalendar() {
        const blockedDates = await fetchBlockedDates();
        const todayStr = today.toISOString().split('T')[0];
        window.processedBookedDates = processBlockedDates(blockedDates.filter(date => date >= todayStr));
        adminCalendar.setOptions({
            LockPlugin: { filters: window.processedBookedDates }
        });
    }

    refreshAdminCalendar();
    setInterval(refreshAdminCalendar, 5000);

    document.getElementById('lock-dates-btn').addEventListener('click', async function () {
        const blockedDates = await fetchBlockedDates();
        const newBlocked = Array.from(new Set([...blockedDates, ...selectedDates]));
        await updateBlockedDates(newBlocked);
        await refreshAdminCalendar();
        alert("התאריכים ננעלו בהצלחה.");
    });

    document.getElementById('unlock-dates-btn').addEventListener('click', async function () {
		const blockedDates = await fetchBlockedDates();
		const todayStr = today.toISOString().split('T')[0];
		const newBlocked = blockedDates.filter(date => !selectedDates.includes(date));

		await updateBlockedDates(newBlocked);
		await refreshAdminCalendar();
		alert("התאריכים שוחררו בהצלחה.");
	});

});
    </script>
    <style>
    .easepick__cell.is-locked {
        background-color: #ccc !important;
        color: #666 !important;
        pointer-events: none;
    }
    </style>
    <?php
}

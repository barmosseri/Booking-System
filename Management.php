wp_localize_script('booking-admin-js', 'my_booking_nonce', [
    'nonce' => wp_create_nonce('wp_rest')
]);
function enqueue_admin_booking_script() {
    wp_enqueue_script('booking-admin-js', get_template_directory_uri() . '/js/booking-admin.js', array('jquery'), null, true);
    wp_localize_script('booking-admin-js', 'myBookingData', [
        'nonce' => wp_create_nonce('wp_rest')
    ]);
}
add_action('admin_enqueue_scripts', 'enqueue_admin_booking_script');

if (defined('LSCWP_V')) {
    do_action('litespeed_purge_all');
}

function create_woocommerce_order_from_elementor_form($record, $handler) {
    $form_name = $record->get_form_settings('form_name');
    if ('your_form_name' !== $form_name) {
        return;
    }

    $raw_fields = $record->get('fields');
    $fields = [];
    foreach ($raw_fields as $id => $field) {
        $fields[$id] = sanitize_text_field($field['value']); // Sanitize input
    }

    $customer_id = email_exists($fields['email']);
    if ($customer_id) {
        $customer = new WC_Customer($customer_id);
    } else {
        $customer = new WC_Customer();
        $customer->set_email($fields['email']);
        $customer->set_first_name($fields['first_name']);
        $customer->set_last_name($fields['last_name']);
        $customer->set_billing_phone($fields['phone']);
        $customer->save();
    }

    $order = wc_create_order(['customer_id' => $customer->get_id()]);
    $product_id = PRODUCT_ID;
    $product = wc_get_product($product_id);
    if ($product) {
        $order->add_product($product, 1);
    }

    $billing_address = [
        'first_name' => $fields['first_name'],
        'last_name'  => $fields['last_name'],
        'email'      => $fields['email'],
        'phone'      => $fields['phone'],
    ];
    $order->set_address($billing_address, 'billing');

    $order->calculate_totals();
    $order->update_status('processing', 'Order created from Elementor form submission.', true);

    wc_reduce_stock_levels($order->get_id());
    WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id());
}
add_action('elementor_pro/forms/new_record', 'create_woocommerce_order_from_elementor_form', 10, 2);

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
        'ניהול יומן', 
        'ניהול יומן', 
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
        <h1>ניהול יומן דינמי</h1>
        <div id="admin-easepick-calendar" style="max-width: 350px;"></div>
        <p id="selected-dates-display" style="margin-top:10px; font-weight:bold;"></p>
        <button id="lock-dates-btn" class="button button-primary">נעילת תאריכים</button>
        <button id="unlock-dates-btn" class="button button-secondary">שחרר תאריכים</button>
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
        body: JSON.stringify({ blocked_dates: selectedDates }) // ודא שזה מערך
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

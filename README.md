# Booking System
This project integrates Easepick as a booking calendar, defines a custom REST API, and connects with Elementor forms to create and manage WooCommerce orders. It automates date blocking and releasing when bookings are made or canceled.

# 📌 Features
✅ Easepick Calendar Integration – A visual date picker for admins.
✅ WooCommerce Order Creation – Automatically creates an order when an Elementor form is submitted.
✅ Date Blocking & Releasing – Ensures booked dates are unavailable and releases them if an order is canceled.
✅ Custom REST API – Allows retrieving and updating blocked dates.
✅ Admin Calendar Management – Manually lock/unlock dates via a custom admin panel.

# 🚀 Installation
1️⃣ Upload the PHP files to your theme’s functions.php or as snippet
2️⃣ Ensure WooCommerce and Elementor Pro are installed and activated
3️⃣ Configure an Elementor form with required fields (email, full_name, phone, etc.)
4️⃣ Set a WooCommerce product as the booking product ($product_id = 12345)
5️⃣ Use the REST API to fetch and update blocked dates dynamically

# 🎯 Technologies Used
WordPress & WooCommerce
Elementor Pro
Easepick (JavaScript Date Picker)
PHP & REST API

# 📖 Summary
This project streamlines WooCommerce bookings by integrating Elementor forms, API-based date management, and an admin calendar interface. It ensures that dates are managed automatically based on order status while providing manual control via the admin panel. 🚀

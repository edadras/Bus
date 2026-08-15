<?php

return [
    // Generic
    'server_error' => 'خطای غیرمنتظره‌ای رخ داد. لطفاً دوباره تلاش کنید.',
    'not_found' => 'مورد درخواستی یافت نشد.',
    'forbidden' => 'شما به این بخش دسترسی ندارید.',
    'unauthenticated' => 'برای ادامه باید وارد حساب کاربری شوید.',
    'validation_failed' => 'اطلاعات ارسالی معتبر نیست.',
    'rate_limited' => 'تعداد درخواست‌ها بیش از حد مجاز است. کمی صبر کنید.',
    'http_error' => 'درخواست قابل پردازش نیست.',
    'too_many_attempts' => 'تلاش‌های شما بیش از حد مجاز است. لطفاً کمی صبر کنید.',
    'domain_error' => 'انجام این عملیات ممکن نیست.',

    // Authentication
    'invalid_mobile' => 'شماره موبایل وارد شده معتبر نیست.',
    'invalid_credentials' => 'شماره موبایل یا رمز عبور اشتباه است.',
    'otp_not_found' => 'کد تأیید یافت نشد یا منقضی شده است.',
    'otp_invalid' => 'کد تأیید اشتباه است.',
    'otp_attempts_exceeded' => 'تعداد تلاش‌های مجاز به پایان رسید. کد جدید دریافت کنید.',
    'otp_cooldown' => 'برای دریافت کد جدید کمی صبر کنید.',
    'otp_daily_limit' => 'سقف درخواست کد تأیید در شبانه‌روز تکمیل شده است.',
    'account_suspended' => 'حساب کاربری شما تعلیق شده است.',
    'account_deleted' => 'این حساب کاربری حذف شده است.',
    'client_not_permitted' => 'شما مجاز به استفاده از این اپلیکیشن نیستید.',
    'unknown_city' => 'شهر انتخاب‌شده یافت نشد.',
    'city_inactive' => 'سرویس در این شهر فعال نیست.',
    'city_not_permitted' => 'شما به اطلاعات این شهر دسترسی ندارید.',

    // QR
    'qr_malformed' => 'کد QR نامعتبر است.',
    'qr_unsupported_version' => 'نسخه کد QR پشتیبانی نمی‌شود. اپلیکیشن را به‌روزرسانی کنید.',
    'qr_expired' => 'کد QR منقضی شده است. دوباره اسکن کنید.',
    'qr_invalid_signature' => 'کد QR معتبر نیست.',
    'qr_replayed' => 'این کد قبلاً استفاده شده است. کد جدید را اسکن کنید.',
    'qr_unknown_code' => 'این کد در سیستم ثبت نشده است.',
    'qr_revoked' => 'این کد باطل شده است. با پشتیبانی تماس بگیرید.',

    // Wallet & payment
    'insufficient_funds' => 'موجودی کیف پول شما کافی نیست.',
    'wallet_frozen' => 'کیف پول شما مسدود است.',
    'wallet_closed' => 'کیف پول بسته شده است.',
    'wallet_not_found' => 'کیف پول یافت نشد.',
    'amount_must_be_positive' => 'مبلغ باید بزرگ‌تر از صفر باشد.',
    'topup_below_minimum' => 'مبلغ شارژ کمتر از حداقل مجاز است.',
    'topup_above_maximum' => 'مبلغ شارژ بیشتر از حداکثر مجاز است.',
    'wallet_balance_limit_exceeded' => 'موجودی کیف پول از سقف مجاز بیشتر می‌شود.',
    'daily_spend_limit_exceeded' => 'سقف برداشت روزانه شما تکمیل شده است.',
    'currency_mismatch' => 'واحد پول تراکنش با کیف پول همخوانی ندارد.',
    'posting_not_balanced' => 'تراکنش مالی متوازن نیست.',
    'posting_has_no_lines' => 'تراکنش مالی فاقد سند است.',
    'ledger_is_immutable' => 'اسناد مالی قابل تغییر نیستند.',
    'transaction_not_reversible' => 'این تراکنش قابل برگشت نیست.',
    'transaction_already_reversed' => 'این تراکنش قبلاً برگشت خورده است.',
    'transaction_not_refundable' => 'این تراکنش قابل بازگشت وجه نیست.',
    'transaction_already_refunded' => 'وجه این تراکنش قبلاً بازگردانده شده است.',
    'transaction_already_settled' => 'این تراکنش قبلاً تسویه شده است.',
    'payment_amount_mismatch' => 'مبلغ پرداخت با مبلغ درخواست همخوانی ندارد.',
    'gateway_unavailable' => 'درگاه پرداخت در دسترس نیست.',
    'invalid_commission' => 'کارمزد محاسبه‌شده نامعتبر است.',
    'no_fare_rule_configured' => 'برای این خط نرخ کرایه تعریف نشده است.',

    // Boarding
    'no_active_trip' => 'این اتوبوس در حال حاضر سرویس فعالی ندارد.',
    'trip_not_accepting_boarding' => 'امکان سوار شدن در وضعیت فعلی سفر وجود ندارد.',
    'already_on_this_bus' => 'شما هم‌اکنون در این اتوبوس هستید.',
    'ride_already_in_progress' => 'شما یک سفر باز دارید. ابتدا آن را پایان دهید.',
    'reboard_cooldown' => 'به‌تازگی در این اتوبوس سوار شده‌اید.',
    'too_far_from_bus' => 'فاصله شما تا اتوبوس بیش از حد مجاز است.',

    // Driver & trips
    'not_a_driver' => 'حساب کاربری شما راننده نیست.',
    'driver_not_active' => 'حساب راننده شما فعال نیست.',
    'license_expired' => 'گواهینامه شما منقضی شده است.',
    'contract_ended' => 'قرارداد همکاری شما به پایان رسیده است.',
    'city_mismatch' => 'این اتوبوس متعلق به شهر دیگری است.',
    'bus_not_assigned' => 'این اتوبوس به شما تخصیص داده نشده است.',
    'bus_not_deployable' => 'این اتوبوس آماده سرویس نیست.',
    'bus_already_in_service' => 'راننده دیگری روی این اتوبوس شیفت باز دارد.',
    'driver_already_on_shift' => 'شما روی اتوبوس دیگری شیفت باز دارید.',
    'bus_already_on_trip' => 'این اتوبوس هم‌اکنون در سفر فعال است.',
    'no_open_shift' => 'شیفت بازی وجود ندارد.',
    'shift_not_open' => 'شیفت باز نیست.',
    'line_not_active' => 'این خط فعال نیست.',
    'invalid_trip_transition' => 'تغییر وضعیت سفر در این حالت مجاز نیست.',
    'trip_not_accepting_telemetry' => 'ارسال موقعیت برای این سفر مجاز نیست.',
    'eta_unavailable' => 'زمان رسیدن قابل محاسبه نیست.',

    // GPS
    'gps_accuracy_too_low' => 'دقت موقعیت مکانی کافی نیست.',
    'gps_speed_implausible' => 'سرعت گزارش‌شده غیرمنطقی است.',
    'gps_timestamp_in_future' => 'زمان دستگاه شما تنظیم نیست.',
    'gps_timestamp_too_old' => 'اطلاعات موقعیت بیش از حد قدیمی است.',
    'gps_jump_detected' => 'جابه‌جایی غیرممکن در موقعیت مکانی تشخیص داده شد.',
    'invalid_coordinate' => 'مختصات جغرافیایی نامعتبر است.',

    // Merchant
    'not_merchant_staff' => 'شما کارمند هیچ پذیرنده‌ای نیستید.',
    'merchant_not_active' => 'این پذیرنده فعال نیست.',
    'refund_not_permitted' => 'شما اجازه بازگشت وجه ندارید.',
    'reports_not_permitted' => 'شما به گزارش‌ها دسترسی ندارید.',
    'settlement_not_permitted' => 'فقط مدیر پذیرنده می‌تواند درخواست تسویه ثبت کند.',
    'merchant_refunds_disabled' => 'بازگشت وجه برای این پذیرنده غیرفعال است.',
    'amount_above_merchant_limit' => 'مبلغ از سقف مجاز این پذیرنده بیشتر است.',
    'nothing_to_settle' => 'تراکنش تسویه‌نشده‌ای در این بازه وجود ندارد.',
    'settlement_not_approvable' => 'این تسویه قابل تأیید نیست.',
    'settlement_below_minimum' => 'مبلغ تسویه کمتر از حداقل مجاز است.',
    'settlement_not_approved' => 'ابتدا باید تسویه تأیید شود.',
    'settlement_already_paid' => 'این تسویه قبلاً پرداخت شده است.',

    // Import
    'import_file_unreadable' => 'فایل ورودی خوانده نشد.',
    'import_file_empty' => 'فایل ورودی سطر داده‌ای ندارد.',

    // Complaints
    'complaint_closed' => 'این شکایت بسته شده است.',
    'complaint_not_rateable' => 'امکان امتیازدهی به این شکایت وجود ندارد.',
    'too_many_attachments' => 'تعداد فایل‌های پیوست بیش از حد مجاز است.',
];

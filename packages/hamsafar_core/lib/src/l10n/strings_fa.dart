/// Persian strings — the product default.
///
/// Keys mirror the server's `lang/fa/*.php` naming so a string can move
/// between surfaces without being renamed.
const faStrings = <String, String>{
  // ── Units and formatting ────────────────────────────────────────────────
  'unit.toman': 'تومان',
  'unit.rial': 'ریال',
  'unit.metres': ':count متر',
  'unit.kilometres': ':count کیلومتر',
  'unit.minutes': ':count دقیقه',
  'unit.hours_and_minutes': ':hours ساعت و :minutes دقیقه',
  'unit.under_a_minute': 'کمتر از یک دقیقه',
  'unit.moments_ago': 'چند لحظه پیش',
  'unit.minutes_ago': ':count دقیقه پیش',
  'unit.hours_ago': ':count ساعت پیش',
  'unit.days_ago': ':count روز پیش',

  // ── Shared ──────────────────────────────────────────────────────────────
  'common.retry': 'تلاش دوباره',
  'common.cancel': 'انصراف',
  'common.close': 'بستن',
  'common.confirm': 'تأیید',
  'common.transaction': 'تراکنش',
  'common.sample_data': 'داده نمونه',
  'common.sample_data_note':
      'خطوط و ایستگاه‌های این نسخه داده نمونه هستند و مرجع رسمی اتوبوس‌رانی نیستند.',
  'common.unknown_error': 'خطای نامشخص',

  // ── Errors ──────────────────────────────────────────────────────────────
  'error.no_response': 'پاسخی از سرور دریافت نشد. دوباره تلاش کنید.',
  'error.unreadable_response': 'پاسخ سرور قابل پردازش نبود.',
  'error.unknown': 'خطای نامشخصی رخ داد.',
  'error.no_connection': 'اتصال به سرور برقرار نشد. اینترنت خود را بررسی کنید.',

  // ── Sign-in ─────────────────────────────────────────────────────────────
  'auth.invalid_mobile': 'شماره موبایل وارد شده معتبر نیست.',
  'auth.mobile_label': 'شماره موبایل',
  'auth.mobile_hint': '۰۹۱۲۳۴۵۶۷۸۹',
  'auth.request_code': 'دریافت کد تأیید',
  'auth.code_sent_to': 'کد پنج‌رقمی ارسال‌شده به :mobile را وارد کنید.',
  'auth.sign_in': 'ورود',
  'auth.change_number': 'تغییر شماره',
  'auth.resend_in': 'ارسال مجدد تا :seconds ثانیه',
  'auth.resend': 'ارسال مجدد کد',
  'auth.debug_code': 'کد آزمایشی: :code',
};

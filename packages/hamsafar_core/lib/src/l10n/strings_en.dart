/// English strings. Every key here must exist in [faStrings] and vice versa;
/// a test in the core package fails the build when they drift apart.
const enStrings = <String, String>{
  // ── Units and formatting ────────────────────────────────────────────────
  'unit.toman': 'Toman',
  'unit.rial': 'Rial',
  'unit.metres': ':count m',
  'unit.kilometres': ':count km',
  'unit.minutes': ':count min',
  'unit.hours_and_minutes': ':hours h :minutes min',
  'unit.under_a_minute': 'less than a minute',
  'unit.moments_ago': 'moments ago',
  'unit.minutes_ago': ':count min ago',
  'unit.hours_ago': ':count h ago',
  'unit.days_ago': ':count days ago',

  // ── Shared ──────────────────────────────────────────────────────────────
  'common.retry': 'Try again',
  'common.cancel': 'Cancel',
  'common.close': 'Close',
  'common.confirm': 'Confirm',
  'common.transaction': 'Transaction',
  'common.sample_data': 'Sample data',
  'common.sample_data_note':
      'The lines and stops in this release are sample data, not the transit authority’s official network.',
  'common.unknown_error': 'Unknown error',

  // ── Errors ──────────────────────────────────────────────────────────────
  'error.no_response': 'No response from the server. Please try again.',
  'error.unreadable_response': 'The server response could not be read.',
  'error.unknown': 'Something went wrong.',
  'error.no_connection': 'Could not reach the server. Check your connection.',

  // ── Sign-in ─────────────────────────────────────────────────────────────
  'auth.invalid_mobile': 'That mobile number is not valid.',
  'auth.mobile_label': 'Mobile number',
  'auth.mobile_hint': '09123456789',
  'auth.request_code': 'Send verification code',
  'auth.code_sent_to': 'Enter the five-digit code sent to :mobile.',
  'auth.sign_in': 'Sign in',
  'auth.change_number': 'Change number',
  'auth.resend_in': 'Resend in :seconds seconds',
  'auth.resend': 'Resend the code',
  'auth.debug_code': 'Test code: :code',
};

/// Shared foundation for the Hamsafar passenger, driver and merchant apps.
///
/// Everything three apps would otherwise duplicate lives here: the API client
/// and its typed calls, the domain models, the design system, secure token
/// storage and the realtime layer.
library;

export 'src/api/api_client.dart';
export 'src/api/api_exception.dart';
export 'src/api/transit_api.dart';
export 'src/config/app_config.dart';
export 'src/l10n/app_strings.dart';
export 'src/l10n/locale_provider.dart';
export 'src/models/models.dart';
export 'src/push/push_registration.dart';
export 'src/providers/core_providers.dart';
export 'src/realtime/realtime_client.dart';
export 'src/storage/preference_store.dart';
export 'src/storage/token_store.dart';
export 'src/theme/app_theme.dart';
export 'src/util/formatters.dart';
export 'src/widgets/glass.dart';
export 'src/widgets/app_scaffold.dart';
export 'src/widgets/otp_login.dart';

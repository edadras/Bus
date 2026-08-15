import 'dart:async';
import 'dart:ui' show Locale;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../storage/preference_store.dart';
import 'app_strings.dart';

/// The active locale.
///
/// Persian is the default and is what a cold start renders, so a rider in
/// Bandar Abbas never sees the interface flip while a preference loads. A
/// stored choice is applied as soon as it is read; the device's own language
/// is deliberately not consulted, for the same reason the API ignores
/// Accept-Language — a phone set to English should not change the app for
/// someone who wants Persian.
class LocaleController extends StateNotifier<String> {
  LocaleController(this._store) : super(AppStrings.fallbackLocale) {
    unawaited(_restore());
  }

  final PreferenceStore _store;

  static const _key = 'locale';

  Future<void> _restore() async {
    try {
      final stored = await _store.read(_key);

      if (stored != null && AppStrings.supported.contains(stored)) {
        state = stored;
      }
    } catch (_) {
      // Unreadable storage is not a reason to fail to start; the default
      // locale is the right one for almost every user anyway.
    }
  }

  Future<void> set(String locale) async {
    if (!AppStrings.supported.contains(locale)) return;

    state = locale;

    try {
      await _store.write(_key, locale);
    } catch (_) {
      // The choice still applies for this session.
    }
  }
}

final preferenceStoreProvider = Provider<PreferenceStore>((ref) => PreferenceStore());

final localeProvider = StateNotifierProvider<LocaleController, String>(
  (ref) => LocaleController(ref.watch(preferenceStoreProvider)),
);

/// The string table for the active locale.
final stringsProvider = Provider<AppStrings>((ref) => AppStrings(ref.watch(localeProvider)));

final flutterLocaleProvider = Provider<Locale>((ref) => Locale(ref.watch(localeProvider)));

/// `ref.t('ride.board_now')` — the call every screen uses.
extension Translate on WidgetRef {
  String t(String key, [Map<String, Object?> params = const {}]) =>
      read(stringsProvider)(key, params);
}

extension TranslateRef on Ref {
  String t(String key, [Map<String, Object?> params = const {}]) =>
      read(stringsProvider)(key, params);
}

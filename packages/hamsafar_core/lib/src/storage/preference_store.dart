import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Small non-secret preferences that still need to survive a restart — the
/// chosen language, and anything like it.
///
/// It shares the secure-storage backend with [TokenStore] rather than adding a
/// second persistence dependency for two strings. Nothing here is a
/// credential; the keychain is simply the store the app already has.
class PreferenceStore {
  PreferenceStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  static const _prefix = 'hamsafar.pref.';

  final FlutterSecureStorage _storage;

  Future<String?> read(String key) => _storage.read(key: '$_prefix$key');

  Future<void> write(String key, String value) => _storage.write(key: '$_prefix$key', value: value);

  Future<void> remove(String key) => _storage.delete(key: '$_prefix$key');
}

/// In-memory implementation for tests and widget builds, so a test never
/// touches a platform channel that does not exist on the VM.
///
/// It extends rather than implements, because the parent's storage handle is
/// private: every method below is overridden, so the handle is never touched.
class InMemoryPreferenceStore extends PreferenceStore {
  InMemoryPreferenceStore() : super(storage: const FlutterSecureStorage());

  final Map<String, String> _values = {};

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async => _values[key] = value;

  @override
  Future<void> remove(String key) async => _values.remove(key);
}

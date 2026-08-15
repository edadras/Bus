import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Auth token persistence.
///
/// The token authorises payments, so it belongs in the platform keychain /
/// Android keystore rather than in shared preferences, which are readable on a
/// rooted device and included in some backup flows.
class TokenStore {
  TokenStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
            );

  static const _tokenKey = 'hamsafar.token';
  static const _abilitiesKey = 'hamsafar.abilities';

  final FlutterSecureStorage _storage;

  // Kept in memory so the request interceptor does not hit the keychain on
  // every single call, which is measurably slow on older Android devices.
  String? _cached;
  bool _loaded = false;

  Future<String?> read() async {
    if (_loaded) return _cached;

    _cached = await _storage.read(key: _tokenKey);
    _loaded = true;

    return _cached;
  }

  Future<void> write(String token, {List<String> abilities = const []}) async {
    _cached = token;
    _loaded = true;

    await _storage.write(key: _tokenKey, value: token);
    await _storage.write(key: _abilitiesKey, value: abilities.join(','));
  }

  Future<List<String>> abilities() async {
    final raw = await _storage.read(key: _abilitiesKey);

    return raw == null || raw.isEmpty ? const [] : raw.split(',');
  }

  Future<void> clear() async {
    _cached = null;
    _loaded = true;

    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _abilitiesKey);
  }

  Future<bool> get hasToken async => (await read()) != null;
}

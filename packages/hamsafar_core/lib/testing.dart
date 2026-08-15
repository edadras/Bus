/// Test doubles for the shared package.
///
/// Kept in a separate entrypoint so nothing here is reachable from an app
/// build: importing `hamsafar_core/hamsafar_core.dart` will never pull in a
/// fake token store.
library;

import 'src/storage/token_store.dart';

/// In-memory [TokenStore].
///
/// The real store talks to the platform keychain, which has no implementation
/// under `flutter_test`; using it there makes every widget test depend on a
/// plugin channel that is not registered.
class InMemoryTokenStore extends TokenStore {
  InMemoryTokenStore({String? token, List<String> abilities = const []})
      : _token = token,
        _abilities = abilities;

  String? _token;
  List<String> _abilities;

  @override
  Future<String?> read() async => _token;

  @override
  Future<void> write(String token, {List<String> abilities = const []}) async {
    _token = token;
    _abilities = abilities;
  }

  @override
  Future<List<String>> abilities() async => _abilities;

  @override
  Future<void> clear() async {
    _token = null;
    _abilities = const [];
  }

  @override
  Future<bool> get hasToken async => _token != null;
}

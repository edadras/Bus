import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../api/transit_api.dart';
import '../config/app_config.dart';
import '../models/models.dart';
import '../realtime/realtime_client.dart';
import '../storage/token_store.dart';
import '../util/formatters.dart';

/// Injected at app start, so each app can point at its own backend without
/// recompiling the shared package.
final appConfigProvider = Provider<AppConfig>((ref) {
  throw UnimplementedError('appConfigProvider must be overridden in main().');
});

final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore());

final apiClientProvider = Provider<ApiClient>((ref) {
  final config = ref.watch(appConfigProvider);

  final client = ApiClient(
    baseUrl: config.apiBaseUrl,
    tokenStore: ref.watch(tokenStoreProvider),
    citySlug: config.citySlug,
  );

  // One place to drop the session when the server rejects our token.
  client.onUnauthenticated = () => ref.read(authControllerProvider.notifier).forceSignOut();

  return client;
});

final transitApiProvider = Provider<TransitApi>((ref) => TransitApi(ref.watch(apiClientProvider)));

/// The socket, rebuilt whenever the session changes.
///
/// It watches the auth state rather than reading it once: a private channel is
/// signed against the token that asked for it, so a client that survived a
/// sign-out would keep listening on somebody else's behalf.
final realtimeProvider = Provider<RealtimeClient>((ref) {
  final config = ref.watch(appConfigProvider);

  // Rebuilt on sign-in and sign-out. A socket that outlived a session would
  // keep listening on a private channel the new signed-in user may not hold.
  ref.watch(authControllerProvider.select((state) => state.isSignedIn));

  final client = RealtimeClient(
    appKey: config.reverbKey,
    host: config.reverbHost,
    port: config.reverbPort,
    useTls: config.reverbUseTls,
    authEndpoint: '${config.apiBaseUrl}/broadcasting/auth',
    tokenProvider: () => ref.read(tokenStoreProvider).read(),
  );

  client.connect();
  ref.onDispose(client.dispose);

  return client;
});

// ── Authentication ────────────────────────────────────────────────────────

class AuthState {
  const AuthState({
    this.user,
    this.isLoading = false,
    this.isRestoring = true,
    this.error,
  });

  final AppUser? user;
  final bool isLoading;

  /// True until the stored token has been checked, so the UI can show a
  /// splash rather than flashing the sign-in screen on every cold start.
  final bool isRestoring;
  final String? error;

  bool get isSignedIn => user != null;

  AuthState copyWith({
    AppUser? user,
    bool? isLoading,
    bool? isRestoring,
    String? error,
    bool clearUser = false,
    bool clearError = false,
  }) =>
      AuthState(
        user: clearUser ? null : (user ?? this.user),
        isLoading: isLoading ?? this.isLoading,
        isRestoring: isRestoring ?? this.isRestoring,
        error: clearError ? null : (error ?? this.error),
      );
}

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._ref) : super(const AuthState()) {
    unawaited(restore());
  }

  final Ref _ref;

  TransitApi get _api => _ref.read(transitApiProvider);

  TokenStore get _tokens => _ref.read(tokenStoreProvider);

  /// Re-establish the session from the stored token, if it is still valid.
  ///
  /// Every failure path must end with `isRestoring: false`. This runs before
  /// the first frame, so an unhandled throw here — a corrupted keystore, a
  /// platform channel missing under test — would leave the app on its splash
  /// screen forever with no way out.
  Future<void> restore() async {
    try {
      if (!await _tokens.hasToken) {
        state = state.copyWith(isRestoring: false);

        return;
      }

      final user = await _api.me();
      state = state.copyWith(user: user, isRestoring: false);
    } catch (_) {
      // An expired token, a revoked session, or unreadable storage: start
      // clean rather than half-signed-in.
      try {
        await _tokens.clear();
      } catch (_) {
        // Storage is unavailable; there is nothing left to clean up.
      }

      state = const AuthState(isRestoring: false);
    }
  }

  Future<String?> requestOtp(String mobile) async {
    state = state.copyWith(isLoading: true, clearError: true);

    try {
      final debugCode = await _api.requestOtp(mobile);
      state = state.copyWith(isLoading: false);

      return debugCode;
    } catch (error) {
      state = state.copyWith(isLoading: false, error: _message(error));

      rethrow;
    }
  }

  Future<void> verifyOtp({
    required String mobile,
    required String code,
    required String client,
  }) async {
    state = state.copyWith(isLoading: true, clearError: true);

    try {
      final result = await _api.verifyOtp(
        mobile: mobile,
        code: code,
        client: client,
        deviceName: defaultTargetPlatform.name,
      );

      await _tokens.write(result.token, abilities: result.abilities);

      state = state.copyWith(user: result.user, isLoading: false, isRestoring: false);
    } catch (error) {
      state = state.copyWith(isLoading: false, error: _message(error));

      rethrow;
    }
  }

  Future<void> signOut() async {
    try {
      await _api.logout();
    } catch (_) {
      // Even if the server call fails, the local session must end.
    }

    await _tokens.clear();
    state = const AuthState(isRestoring: false);
  }

  /// Called by the API client when the server rejects our token mid-session.
  void forceSignOut() {
    state = const AuthState(isRestoring: false);
  }

  String _message(Object error) => error is Exception
      ? error.toString().replaceFirst('Exception: ', '')
      : Format.tr('common.unknown_error');
}

final authControllerProvider = StateNotifierProvider<AuthController, AuthState>(AuthController.new);

/// Map tiles and default viewport, fetched once and reused by every map.
final mapConfigProvider = FutureProvider<MapConfig>((ref) async {
  try {
    return await ref.watch(transitApiProvider).mapConfig();
  } catch (_) {
    // A missing config must not stop the map rendering at all.
    return MapConfig.fallback;
  }
});

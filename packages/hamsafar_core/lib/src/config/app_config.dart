import 'package:flutter/foundation.dart';

/// Runtime configuration, supplied via --dart-define so one build artefact can
/// target staging or production without a code change.
@immutable
class AppConfig {
  const AppConfig({
    required this.apiBaseUrl,
    required this.citySlug,
    required this.reverbKey,
    required this.reverbHost,
    required this.reverbPort,
    required this.reverbUseTls,
    required this.appName,
    required this.client,
  });

  /// Reads the environment, with defaults that work against a local server:
  /// 10.0.2.2 is how the Android emulator reaches the host machine.
  factory AppConfig.fromEnvironment({
    required String appName,
    required String client,
  }) {
    const baseUrl = String.fromEnvironment(
      'API_BASE_URL',
      defaultValue: 'http://10.0.2.2:8000',
    );

    final uri = Uri.parse(baseUrl);

    return AppConfig(
      apiBaseUrl: baseUrl,
      citySlug: const String.fromEnvironment('CITY_SLUG', defaultValue: 'bandar-abbas'),
      reverbKey: const String.fromEnvironment('REVERB_APP_KEY'),
      reverbHost: const String.fromEnvironment('REVERB_HOST').isEmpty
          ? uri.host
          : const String.fromEnvironment('REVERB_HOST'),
      reverbPort: const int.fromEnvironment('REVERB_PORT', defaultValue: 8080),
      reverbUseTls: const bool.fromEnvironment('REVERB_TLS', defaultValue: false),
      appName: appName,
      client: client,
    );
  }

  final String apiBaseUrl;
  final String citySlug;
  final String reverbKey;
  final String reverbHost;
  final int reverbPort;
  final bool reverbUseTls;
  final String appName;

  /// Which Sanctum ability set this build asks for: passenger|driver|merchant.
  final String client;

  bool get hasRealtime => reverbKey.isNotEmpty;
}

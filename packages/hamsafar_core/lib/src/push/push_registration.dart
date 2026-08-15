import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';

import '../api/transit_api.dart';

/// Registers this device for native push.
///
/// The messaging SDK itself is deliberately not a dependency of this package.
/// `firebase_messaging` requires a `google-services.json` / `GoogleService-Info.plist`
/// generated for a specific Firebase project, and those files are per-deployment
/// credentials that cannot live in a shared repository — adding the plugin
/// without them breaks the Android build outright for anyone who clones this.
///
/// So the seam is here instead: an app that has been given a Firebase project
/// adds the plugin, obtains a token, and calls [register]. Everything from that
/// point on — storage, delivery, and pruning a token the push service says is
/// dead — is already implemented server-side.
///
/// ```dart
/// final token = await FirebaseMessaging.instance.getToken();
/// if (token != null) await PushRegistration(api).register(token);
/// ```
///
/// Nothing else in the apps depends on this, so a build with no Firebase
/// project behaves exactly as before: notifications still arrive in the in-app
/// inbox and over the live socket, just without an out-of-app banner.
class PushRegistration {
  const PushRegistration(this._api);

  final TransitApi _api;

  /// Whether this deployment's server can deliver a native push at all.
  ///
  /// Checked before asking the OS for notification permission: a prompt that
  /// is accepted and then never honoured is worse than no prompt, and a
  /// declined one can be hard to ask for again.
  Future<bool> serverSupportsNativePush() async {
    try {
      return await _api.nativePushEnabled();
    } catch (_) {
      return false;
    }
  }

  /// Hand a platform registration token to the server.
  ///
  /// Safe to call on every launch: registration is keyed on (user, token), so
  /// a repeat is an update rather than a duplicate, and a rotated token simply
  /// arrives as a new row while the push service retires the old one.
  Future<bool> register(String token, {String? deviceName}) async {
    try {
      await _api.registerPushSubscription(
        endpoint: token,
        platform: _platform,
        deviceName: deviceName ?? _defaultDeviceName,
      );

      return true;
    } catch (_) {
      // Failing to register must never block sign-in or app start.
      return false;
    }
  }

  String get _platform {
    if (kIsWeb) return 'web';

    return Platform.isIOS ? 'ios' : 'android';
  }

  String get _defaultDeviceName => kIsWeb ? 'web' : Platform.operatingSystemVersion;
}

import '../util/formatters.dart';

/// A failure returned by the platform API, carrying the server's stable
/// machine-readable code so screens can branch on the reason rather than on a
/// translated string.
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    this.status,
    this.details,
  });

  final String code;
  final String message;
  final int? status;
  final Map<String, dynamic>? details;

  /// Re-authenticating would plausibly fix this.
  bool get isAuthFailure => status == 401 || code == 'unauthenticated';

  bool get isValidation => code == 'validation_failed';

  bool get isRateLimited => status == 429;

  /// The user could fix this by topping up.
  bool get isInsufficientFunds => code == 'insufficient_funds';

  /// The QR code needs re-scanning rather than any user action.
  bool get isStaleQr => code == 'qr_expired' || code == 'qr_replayed' || code == 'qr_malformed';

  /// First validation message for a field, if the server supplied one.
  String? fieldError(String field) {
    final value = details?[field];

    if (value is List && value.isNotEmpty) return value.first.toString();
    if (value is String) return value;

    return null;
  }

  @override
  String toString() => 'ApiException($code): $message';
}

/// The device could not reach the server at all — distinct from the server
/// rejecting the request, because the remedy the user needs is different.
class NetworkException extends ApiException {
  // Not const: the default message is translated, and a translation is a
  // lookup rather than a literal.
  NetworkException([String? message])
      : super(
          code: 'network_unavailable',
          message: message ?? Format.tr('error.no_connection'),
        );
}

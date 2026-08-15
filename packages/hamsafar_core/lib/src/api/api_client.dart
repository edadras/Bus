import 'package:dio/dio.dart';

import '../storage/token_store.dart';
import 'api_exception.dart';

/// Typed wrapper over the platform's `/api/v1` surface.
///
/// Every response shares one envelope, so unwrapping, error mapping and the
/// city/auth headers all live here. Screens receive plain data or an
/// [ApiException]; they never see Dio.
class ApiClient {
  ApiClient({
    required String baseUrl,
    required TokenStore tokenStore,
    String citySlug = 'bandar-abbas',
    Dio? dio,
  })  : _tokenStore = tokenStore,
        _citySlug = citySlug,
        _dio = dio ??
            Dio(
              BaseOptions(
                baseUrl: '$baseUrl/api/v1',
                connectTimeout: const Duration(seconds: 12),
                receiveTimeout: const Duration(seconds: 20),
                sendTimeout: const Duration(seconds: 20),
                headers: {'Accept': 'application/json'},
                // The envelope is parsed here, so any status is a valid
                // response as far as Dio is concerned.
                validateStatus: (_) => true,
              ),
            ) {
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _tokenStore.read();

          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }

          options.headers['X-City'] = _citySlug;
          options.headers['X-Locale'] = 'fa';

          handler.next(options);
        },
      ),
    );
  }

  final Dio _dio;
  final TokenStore _tokenStore;
  String _citySlug;

  /// Called when a request comes back unauthenticated, so the app can drop to
  /// the sign-in screen from one place instead of at every call site.
  void Function()? onUnauthenticated;

  String get citySlug => _citySlug;

  /// Switching city mid-session (multi-city support) without rebuilding the
  /// client, so open screens keep their state.
  void useCity(String slug) => _citySlug = slug;

  Future<ApiResult> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get<dynamic>(path, queryParameters: _clean(query)));

  Future<ApiResult> post(String path, {Object? body, Map<String, dynamic>? query}) =>
      _send(() => _dio.post<dynamic>(path, data: body, queryParameters: _clean(query)));

  Future<ApiResult> patch(String path, {Object? body}) =>
      _send(() => _dio.patch<dynamic>(path, data: body));

  Future<ApiResult> delete(String path) => _send(() => _dio.delete<dynamic>(path));

  Future<ApiResult> upload(String path, FormData form) =>
      _send(() => _dio.post<dynamic>(path, data: form));

  Future<ApiResult> _send(Future<Response<dynamic>> Function() request) async {
    late final Response<dynamic> response;

    try {
      response = await request();
    } on DioException catch (error) {
      throw switch (error.type) {
        DioExceptionType.connectionTimeout ||
        DioExceptionType.sendTimeout ||
        DioExceptionType.receiveTimeout =>
          const NetworkException('پاسخی از سرور دریافت نشد. دوباره تلاش کنید.'),
        DioExceptionType.connectionError => const NetworkException(),
        _ => NetworkException(error.message),
      };
    }

    final body = response.data;

    if (body is! Map<String, dynamic>) {
      // 204 and other empty successes are legitimate.
      if (response.statusCode == 204 || body == null) {
        return const ApiResult(data: null, meta: null);
      }

      throw ApiException(
        code: 'server_error',
        message: 'پاسخ سرور قابل پردازش نبود.',
        status: response.statusCode,
      );
    }

    if (body['success'] == true) {
      return ApiResult(
        data: body['data'],
        meta: body['meta'] as Map<String, dynamic>?,
      );
    }

    final error = (body['error'] as Map<String, dynamic>?) ?? const {};

    final exception = ApiException(
      code: (error['code'] as String?) ?? 'server_error',
      message: (error['message'] as String?) ?? 'خطای نامشخصی رخ داد.',
      status: response.statusCode,
      details: error['details'] as Map<String, dynamic>?,
    );

    if (exception.isAuthFailure) {
      await _tokenStore.clear();
      onUnauthenticated?.call();
    }

    throw exception;
  }

  Map<String, dynamic>? _clean(Map<String, dynamic>? query) {
    if (query == null) return null;

    final cleaned = <String, dynamic>{};

    query.forEach((key, value) {
      if (value != null && value != '') cleaned[key] = value;
    });

    return cleaned.isEmpty ? null : cleaned;
  }
}

/// The unwrapped envelope: payload plus optional pagination/context metadata.
class ApiResult {
  const ApiResult({required this.data, required this.meta});

  final dynamic data;
  final Map<String, dynamic>? meta;

  Map<String, dynamic> get asMap => (data as Map<String, dynamic>?) ?? const {};

  List<Map<String, dynamic>> get asList =>
      ((data as List<dynamic>?) ?? const <dynamic>[]).cast<Map<String, dynamic>>();

  bool get hasMorePages => (meta?['pagination'] as Map<String, dynamic>?)?['has_more'] == true;

  int get currentPage =>
      ((meta?['pagination'] as Map<String, dynamic>?)?['current_page'] as int?) ?? 1;
}

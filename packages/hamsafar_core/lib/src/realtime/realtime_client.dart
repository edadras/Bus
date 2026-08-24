import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

/// Minimal Pusher-protocol client for Laravel Reverb.
///
/// Written directly against the wire protocol rather than pulling in a large
/// client library: the app needs exactly three things — subscribe to a public
/// channel, subscribe to a private one, and receive events.
///
/// It is deliberately failure-tolerant. Every screen that uses it also polls,
/// so a blocked socket degrades to slower updates instead of a frozen map.
/// Reconnection backs off exponentially and stops announcing itself after the
/// first failure, so a permanently blocked network does not spam the log.
class RealtimeClient {
  RealtimeClient({
    required this.appKey,
    required this.host,
    required this.port,
    required this.useTls,
    this.authEndpoint,
    this.tokenProvider,
  });

  final String appKey;
  final String host;
  final int port;
  final bool useTls;
  final String? authEndpoint;

  /// Read at the moment a private channel is authorised, rather than captured
  /// once: the token lives in the platform keychain and has no business being
  /// copied into app state on the way here.
  final Future<String?> Function()? tokenProvider;

  WebSocketChannel? _channel;
  StreamSubscription<dynamic>? _subscription;
  Timer? _reconnectTimer;
  Timer? _pingTimer;

  final _handlers = <String, Map<String, List<void Function(Map<String, dynamic>)>>>{};
  final _pendingChannels = <String>{};
  final _connectionState = StreamController<RealtimeStatus>.broadcast();

  final _http = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 8),
    receiveTimeout: const Duration(seconds: 8),
  ),);

  int _attempt = 0;
  bool _disposed = false;
  String? _socketId;

  Stream<RealtimeStatus> get status => _connectionState.stream;

  bool get isConnected => _socketId != null;

  void connect() {
    if (_disposed || appKey.isEmpty) return;

    _cleanUpSocket();

    final scheme = useTls ? 'wss' : 'ws';
    final uri =
        Uri.parse('$scheme://$host:$port/app/$appKey?protocol=7&client=flutter&version=1.0');

    try {
      _channel = WebSocketChannel.connect(uri);
      _connectionState.add(RealtimeStatus.connecting);

      _subscription = _channel!.stream.listen(
        _onMessage,
        onError: (_) => _scheduleReconnect(),
        onDone: _scheduleReconnect,
        cancelOnError: true,
      );
    } catch (_) {
      _scheduleReconnect();
    }
  }

  /// Listen for `event` on `channel`. Safe to call before the socket is up:
  /// the subscription is replayed once connected.
  void on(String channel, String event, void Function(Map<String, dynamic>) handler) {
    _handlers.putIfAbsent(channel, () => {}).putIfAbsent(event, () => []).add(handler);

    if (isConnected) {
      _subscribe(channel);
    } else {
      _pendingChannels.add(channel);
    }
  }

  void leave(String channel) {
    _handlers.remove(channel);
    _pendingChannels.remove(channel);

    _send({
      'event': 'pusher:unsubscribe',
      'data': {'channel': channel},
    });
  }

  void dispose() {
    _disposed = true;
    _reconnectTimer?.cancel();
    _pingTimer?.cancel();
    _cleanUpSocket();
    _connectionState.close();
  }

  void _onMessage(dynamic raw) {
    final Map<String, dynamic> message;

    try {
      message = jsonDecode(raw as String) as Map<String, dynamic>;
    } catch (_) {
      return;
    }

    final event = message['event'] as String?;

    // The payload arrives as a JSON string inside the frame.
    final data = switch (message['data']) {
      final String s => _tryDecode(s),
      final Map<String, dynamic> m => m,
      _ => <String, dynamic>{},
    };

    switch (event) {
      case 'pusher:connection_established':
        _socketId = data['socket_id']?.toString();
        _attempt = 0;
        _connectionState.add(RealtimeStatus.connected);
        _startPing();

        for (final channel in {..._handlers.keys, ..._pendingChannels}) {
          _subscribe(channel);
        }
        _pendingChannels.clear();

      case 'pusher:error':
        _connectionState.add(RealtimeStatus.disconnected);
        _scheduleReconnect();

      case 'pusher:ping':
        _send({'event': 'pusher:pong', 'data': {}});

      default:
        if (event == null) return;

        final channel = message['channel'] as String?;
        if (channel == null) return;

        // Laravel prefixes broadcast names with a dot.
        final name = event.startsWith('.') ? event.substring(1) : event;

        for (final handler in _handlers[channel]?[name] ?? const []) {
          handler(data);
        }
    }
  }

  Map<String, dynamic> _tryDecode(String raw) {
    try {
      final decoded = jsonDecode(raw);

      return decoded is Map<String, dynamic> ? decoded : <String, dynamic>{};
    } catch (_) {
      return <String, dynamic>{};
    }
  }

  void _subscribe(String channel) {
    if (!channel.startsWith('private-')) {
      _send({
        'event': 'pusher:subscribe',
        'data': {'channel': channel},
      });

      return;
    }

    // A private channel is not something the client can grant itself. The
    // server signs the pair (socket id, channel name) with the app secret and
    // decides, in routes/channels.php, whether this token may listen at all —
    // which is why the driver's own shift and the operations map can share one
    // channel name without sharing an audience.
    unawaited(_authorise(channel));
  }

  Future<void> _authorise(String channel) async {
    final endpoint = authEndpoint;
    final socketId = _socketId;

    if (endpoint == null || socketId == null) return;

    final token = await tokenProvider?.call();

    // Without credentials there is nothing to ask with. Skipped rather than
    // treated as an error: a signed-out passenger still gets the public map.
    if (token == null || _disposed || _socketId != socketId) return;

    try {
      final response = await _http.post<dynamic>(
        endpoint,
        data: {'socket_id': socketId, 'channel_name': channel},
        options: Options(
          headers: {
            'Authorization': 'Bearer $token',
            'Accept': 'application/json',
          },
        ),
      );

      final auth = (response.data as Map<String, dynamic>?)?['auth'];

      // A refusal is a legitimate answer — this token may not listen here —
      // and the socket carries on serving the channels it may.
      if (auth is! String || _disposed || _socketId != socketId) return;

      _send({
        'event': 'pusher:subscribe',
        'data': {'channel': channel, 'auth': auth},
      });
    } catch (_) {
      // The reconnect path re-attempts every channel, so one failed
      // authorisation is not worth surfacing or retrying here.
    }
  }

  void _send(Map<String, dynamic> payload) {
    try {
      _channel?.sink.add(jsonEncode(payload));
    } catch (_) {
      // A dead sink is handled by the reconnect path.
    }
  }

  void _startPing() {
    _pingTimer?.cancel();
    _pingTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      _send({'event': 'pusher:ping', 'data': {}});
    });
  }

  void _scheduleReconnect() {
    if (_disposed) return;

    _socketId = null;
    _pingTimer?.cancel();
    _connectionState.add(RealtimeStatus.disconnected);

    _reconnectTimer?.cancel();

    // Exponential backoff capped at a minute: on a hostile network this must
    // not become a battery drain.
    final delay = Duration(seconds: (1 << _attempt.clamp(0, 6)).clamp(1, 60));
    _attempt++;

    _reconnectTimer = Timer(delay, connect);
  }

  void _cleanUpSocket() {
    unawaited(_subscription?.cancel());
    _subscription = null;
    _channel?.sink.close();
    _channel = null;
    _socketId = null;
  }
}

enum RealtimeStatus { connecting, connected, disconnected }

/// Channel names, kept in one place so they cannot drift from the server's
/// definitions in routes/channels.php.
abstract final class Channels {
  static const prefix = 'transit';

  static String cityBuses(int cityId) => '$prefix.city.$cityId.buses';

  static String trip(int tripId) => '$prefix.trip.$tripId';

  static String tripCrew(int tripId) => 'private-$prefix.trip.$tripId.crew';

  static String userWallet(int userId) => 'private-wallet.user.$userId';

  /// A taxi driver's own shift, where a fare landing is announced.
  static String taxiShift(int shiftId) => 'private-$prefix.taxi.shift.$shiftId';
}

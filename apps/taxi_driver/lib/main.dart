import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'src/shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: AppColors.ink950,
    ),
  );

  unawaited(SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]));

  final config = AppConfig.fromEnvironment(
    appName: Format.tr('taxi.app_name'),
    client: 'taxi_driver',
  );

  runApp(
    ProviderScope(
      overrides: [appConfigProvider.overrideWithValue(config)],
      child: const TaxiDriverApp(),
    ),
  );
}

class TaxiDriverApp extends StatelessWidget {
  const TaxiDriverApp({super.key});

  @override
  Widget build(BuildContext context) {
    return HamsafarApp(title: Format.tr('taxi.app_name'), home: const TaxiDriverShell());
  }
}
